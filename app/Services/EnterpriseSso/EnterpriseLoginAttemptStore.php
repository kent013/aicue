<?php

declare(strict_types=1);

namespace App\Services\EnterpriseSso;

use App\DataTransferObjects\EnterpriseSso\AttemptConsumeResult;
use App\DataTransferObjects\EnterpriseSso\ConsumedLoginAttempt;
use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptStoreFailure;
use App\Models\EnterpriseSsoLoginAttempt;
use App\Models\OrganizationOidcConnection;
use App\Support\EnterpriseSso\AttemptFingerprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * ログイン試行の保管。
 *
 * ## 不変条件 (これが正本)
 *
 *   **同じ試行の使用権を、ちょうど 1 つの要求だけが得る。**
 *   **かつ、その試行を開始したブラウザだけが使える。**
 *
 * ## 契約する DB は pgsql である
 *
 * `phpunit.xml` が `DB_CONNECTION=pgsql` を force しており、**テストも本番も pgsql** である。
 * したがって `SELECT … FOR UPDATE` の排他契約は本番と同じである。
 * ★「ドライバに依存しない」とは書かない — SQLite の FOR UPDATE は同じ契約を持たない。
 *
 * ## なぜセッションに置かないか
 *
 * 同一セッションへの並行要求は route 側で `->block()` を書かない限り直列化が保証されず、
 * 「普通の get() + forget() を書いても契約を満たしたと誤認できる」形になる。
 *
 * ## なぜブラウザ結合が要るか (login CSRF)
 *
 * state の役割は「推測不能であること」だけではない。**その認可要求を開始した
 * ユーザーエージェントに結び付いていること**が要る。グローバルな表だけを根拠にすると、
 * 攻撃者が自分のブラウザで開始し自分の IdP アカウントで認可した callback URL を
 * 被害者に開かせることで、**被害者のブラウザが攻撃者のアカウントへログインする**。
 *
 * ## 保存の形
 *
 * | 項目 | 形 |
 * |---|---|
 * | state | 指紋だけ (原文を保存しない) |
 * | nonce | 指紋だけ |
 * | ブラウザ結合 | セッションへ置いた秘密の指紋 (session ID は保存しない) |
 * | PKCE の検証子 | 交換でそのまま送るので原文が要る → 暗号化して保存 |
 *
 * ## 保証しないもの
 *
 * - セッション cookie ごと奪われた場合のブラウザ結合は破れる (結合はセッションの秘密に依存する)
 * - 期限切れ行の掃除は日次の実行点とオンアクセスの二段であり、**即時削除ではない**
 */
final readonly class EnterpriseLoginAttemptStore
{
    /**
     * 試行の行を作る。**リダイレクトより前に呼ぶ** (逆順だと戻ってきた state が存在しない)。
     */
    public function start(
        OrganizationOidcConnection $connection,
        #[SensitiveParameter] string $state,
        #[SensitiveParameter] string $nonce,
        #[SensitiveParameter] string $codeVerifier,
        #[SensitiveParameter] string $browserBindingSecret,
    ): EnterpriseSsoLoginAttempt {
        $attempt = new EnterpriseSsoLoginAttempt;

        // ★$fillable は空なので保護キーは forceFill で明示代入する。
        $attempt->forceFill([
            'organization_oidc_connection_id' => $connection->id,
            'state_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::State, $state),
            'nonce_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::Nonce, $nonce),
            'browser_binding_fingerprint' => AttemptFingerprint::of(
                FingerprintPurpose::BrowserBinding,
                $browserBindingSecret,
            ),
            'pkce_verifier_encrypted' => $codeVerifier,
            'expires_at' => now()->addSeconds(Config::integer('enterprise-sso.login_attempt.ttl_seconds')),
        ])->save();

        return $attempt;
    }

    /**
     * 使用権を取得する。取得できた要求だけが値を読み出せる。
     *
     * ★**業務上の拒否では例外を投げない。DB・基盤の障害は例外として伝播し巻き戻す。**
     *   - **業務上の拒否** (行が無い / 期限切れ / ブラウザ結合の不一致) はすべて
     *     {@see AttemptConsumeResult} の分類として**返す**。ここを例外にすると、
     *     同じトランザクションで行っている**期限切れ行のオンアクセス掃除まで巻き戻り**、
     *     「オンアクセスでも掃除する」が成立しない。
     *   - **DB・基盤の障害** ({@see EnterpriseSsoAttemptStoreFailure} と、その他の
     *     予期しない例外) は**握り潰さず伝播させ**、トランザクションごと巻き戻す。
     *     ★このときオンアクセス掃除が巻き戻ることは**受け入れる** —
     *     掃除の正本は日次の実行点であり、オンアクセスはその前倒しに過ぎない。
     *
     * ★呼び出し側 ({@see EnterpriseCallbackAuthenticator}) が
     *   「行が消えた失敗か / 行を保持した失敗か」で**セッションの秘密の始末を分け**、
     *   その後で**外向きの一様な例外へ変換する**。
     */
    public function consume(
        #[SensitiveParameter] string $state,
        #[SensitiveParameter] string $browserBindingSecret,
    ): AttemptConsumeResult {
        $stateFingerprint = AttemptFingerprint::of(FingerprintPurpose::State, $state);
        $expectedBinding = AttemptFingerprint::of(FingerprintPurpose::BrowserBinding, $browserBindingSecret);

        return DB::transaction(function () use ($stateFingerprint, $expectedBinding): AttemptConsumeResult {
            $row = EnterpriseSsoLoginAttempt::query()
                ->where('state_fingerprint', $stateFingerprint)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return AttemptConsumeResult::notFound();
            }

            if ($row->expires_at->isPast()) {
                $this->deleteOrFail($row);   // ★この削除は commit される

                return AttemptConsumeResult::expired();
            }

            if (! hash_equals($row->browser_binding_fingerprint, $expectedBinding)) {
                // ★行を消さない (攻撃者が被害者の試行を消せる形にしない)。
                return AttemptConsumeResult::bindingMismatch();
            }

            // 行をそのまま外へ出さない。DTO へ畳む。
            $attempt = ConsumedLoginAttempt::fromModel($row);

            $this->deleteOrFail($row);

            return AttemptConsumeResult::consumed($attempt);
        });
    }

    /**
     * ★`delete()` が真を返さないのは **DB の障害**であって業務上の拒否ではない。
     *   一様な拒否へ握り潰すと「排他が壊れた」という重大な事実が隠れる。
     *   例外を投げてトランザクションを巻き戻す (行もセッションの秘密も残る)。
     *
     * @throws EnterpriseSsoAttemptStoreFailure
     */
    private function deleteOrFail(EnterpriseSsoLoginAttempt $row): void
    {
        if ($row->delete() !== true) {
            throw new EnterpriseSsoAttemptStoreFailure('attempt row delete did not affect a row');
        }
    }
}
