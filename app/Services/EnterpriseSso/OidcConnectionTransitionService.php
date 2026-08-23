<?php

declare(strict_types=1);

namespace App\Services\EnterpriseSso;

use App\DataTransferObjects\EnterpriseSso\ConnectionCredentialsSnapshot;
use App\DataTransferObjects\EnterpriseSso\VerifyOutcome;
use App\Enums\EnterpriseSso\ConnectionTransitionRejection;
use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Exceptions\EnterpriseSso\OidcConnectionTransitionException;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Closure;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * 接続の状態遷移。
 *
 * 許す遷移 (これ以外は例外):
 *   Draft                    → Verified  (接続先情報の取得に成功した)
 *   Verified                 → Active    (運営が有効にした)
 *   Active                   → Disabled  (運営が止めた)
 *   Disabled                 → Active    (運営が戻した。verified_at が残っている場合のみ)
 *   Verified/Active/Disabled → Draft     (★**認証材料を更新した**)
 *
 * ## 更新の規則は 3 段に分かれる
 *
 * | 変えるもの | 規則 | 理由 |
 * |---|---|---|
 * | **issuer / client_id** | ★**身元が 1 件でもあれば変更禁止**。新しい接続を作らせる。
 *   **身元が 0 件なら変更できるが、その場合も必ず `Draft` へ戻し `verified_at` を消す** |
 *   OIDC の身元は実質 (issuer, subject) であり、pairwise subject では client_id も
 *   名前空間を変えうる。変えた後に偶然同じ subject が返ると**以前の利用者へ誤ってログインさせる** |
 * | **client_secret** | **Draft へ差し戻し + verified_at を消す** | 名前空間は変わらないが、
 *   未検証の構成で直ちにログインできる状態を作らない |
 * | **表示名** | 状態を変えない | 認証に関与しない |
 *
 *  - 更新と状態変更は **同一トランザクション**で行う (片方だけが残る窓を作らない)
 *  - **身元が 1 件でもある接続は物理削除できない** (削除すると身元だけが消え、
 *    利用者が残ってアカウントが分裂する。運用は無効化で行う)
 *
 * ## 接続を変える操作はすべて接続の行をロックする (C2 との線形化)
 *
 * ★対象は **無効化だけではない**。`update` / `activate` / `disable` / `destroy` の**すべて**が
 * **接続の行を `lockForUpdate()` した同一トランザクション**で、
 * 「身元の有無の確認 → 検査 → 変更」を行う。
 * C2 の callback も同じ行をロックして「Active の確認 → JIT」を行うので、両者は直列化される。
 *
 * ★ロックしないと次の競合が起きる:
 *   (1) 管理操作が「身元 0 件」を確認 → (2) callback が行をロックして JIT →
 *   (3) 管理操作が issuer を更新 / 物理削除
 *   = **身元があるのに名前空間が変わる / 身元だけが消える**。
 *
 * ★**`verify` だけはこの形にしない**。`verify` は外向き HTTP を伴うので、同じ形にすると
 *   **通信の間ずっと DB のロックを保持する**ことになる。`verify` は下の**三段構成**で線形化する。
 *
 * ★**ロック付きの再取得は 5 操作とも relation 起点に統一する**:
 *
 *     $organization->oidcConnections()->whereKey($id)->lockForUpdate()->first()
 *
 *   クラス起点の主キー同一性クエリで書かない — AGENTS.md セキュリティ不変条件 3 が
 *   deny-by-default で分類を求める形であり、かつ**再取得の経路そのものが組織スコープを失う**。
 *   親の `$organization` は route の scoped binding が解決したものだけを受け取り、
 *   **payload 由来の組織 id を入れない** (不変条件 1)。
 *   ★入口の binding が済んでいても**再取得の側で改めて relation 起点にする**。
 *   「入口で確認したから中は自由」は、経路が増えたときに必ず崩れる。
 *
 * ★**ロックの取得順を統一する** (接続の行が唯一のロック対象。他の行を先に取らない)。
 *
 * ## 取得の失敗で接続を殺さない
 *
 * IdP の 5xx・鍵ローテーションの途中・DNS の一時障害を理由に**自動で無効化しない**
 * (可用性の後退になる)。失敗はすべて「そのログイン試行だけを fail-closed で拒否する」に留め、
 * 接続の状態を変えるのは**本サービスを通した運営操作だけ**である。
 */
final readonly class OidcConnectionTransitionService
{
    public function __construct(private OidcDiscoveryService $discovery) {}

    /**
     * 接続を登録する (常に `Draft` から始まる)。
     */
    public function create(
        Organization $organization,
        string $loginSlug,
        string $displayName,
        OidcIssuerUrl $issuer,
        string $clientId,
        #[SensitiveParameter] ConnectionSecret $clientSecret,
    ): OrganizationOidcConnection {
        return DB::transaction(function () use (
            $organization,
            $loginSlug,
            $displayName,
            $issuer,
            $clientId,
            $clientSecret,
        ): OrganizationOidcConnection {
            $connection = new OrganizationOidcConnection;

            // ★$fillable は空。保護キーは forceFill で明示代入する。
            $connection->forceFill([
                'organization_id' => $organization->id,
                'login_slug' => $loginSlug,
                'display_name' => $displayName,
                'issuer' => $issuer->value,
                'client_id' => $clientId,
                'client_secret_encrypted' => $clientSecret,
                'status' => OidcConnectionStatus::Draft,
                'verified_at' => null,
                'credentials_revision' => 1,
            ])->save();

            return $connection;
        });
    }

    /**
     * 接続を更新する。
     *
     * @param  string|null  $displayName  null = 変えない
     * @param  OidcIssuerUrl|null  $issuer  null = 変えない
     * @param  string|null  $clientId  null = 変えない
     * @param  ConnectionSecret|null  $clientSecret  null = 変えない (据え置き)
     *
     * @throws OidcConnectionTransitionException
     */
    public function update(
        Organization $organization,
        int $connectionId,
        ?string $displayName,
        ?OidcIssuerUrl $issuer,
        ?string $clientId,
        #[SensitiveParameter] ?ConnectionSecret $clientSecret,
    ): OrganizationOidcConnection {
        return $this->withLockedConnection(
            $organization,
            $connectionId,
            function (OrganizationOidcConnection $locked) use (
                $displayName,
                $issuer,
                $clientId,
                $clientSecret,
            ): OrganizationOidcConnection {
                if ($displayName !== null) {
                    // ★表示名は認証に関与しない。状態も版も変えない。
                    $locked->forceFill(['display_name' => $displayName])->save();
                }

                $changesNamespace = ($issuer !== null && $issuer->value !== $locked->issuer)
                    || ($clientId !== null && $clientId !== $locked->client_id);

                if ($changesNamespace && $locked->identities()->exists()) {
                    // ★身元がある接続の名前空間は変えられない (別人へ誤ログインさせるため)。
                    throw OidcConnectionTransitionException::of(
                        ConnectionTransitionRejection::IdentitiesExistCannotChangeNamespace,
                    );
                }

                $changesSecret = $clientSecret !== null;

                if ($changesNamespace || $changesSecret) {
                    $this->applyCredentialChange($locked, $issuer, $clientId, $clientSecret);
                }

                return $locked;
            },
        );
    }

    /**
     * 接続先情報の取得に成功したことを確認し、Draft → Verified へ進める。
     *
     * ★**外向き取得の間、DB のロックを一切保持しない**。段は 3 つに分かれる。
     *
     *   第 1 段 (ロックなし): 検証の対象となる**スナップショット**を読む
     *   第 2 段 (ロックなし・トランザクションの外): 外向き取得と検証
     *   第 3 段 (トランザクション + 行ロック): 一致の再確認と遷移
     *
     * ★**第 2 段をトランザクションの中に入れない**。中に入れると、ロックを取っていなくても
     *   pgsql のトランザクションが外部 HTTP の往復のあいだ開きっぱなしになる
     *   (idle in transaction が積み上がる)。開くのは第 3 段だけである。
     *
     * ## 比較子は 3 層である
     *
     * | 層 | 見るもの | 何を捕まえるか |
     * |---|---|---|
     * | **主** | `credentials_revision` | 認証材料の**あらゆる**変更 (書き手が規律を守っている限り) |
     * | **第 2** | `issuer` / `client_id` の**実値** | ★**`+1` を忘れた書き手** (名前空間を変えた場合) |
     * | **第 3** | `client_secret_encrypted` の**暗号文の digest** | ★**`+1` を忘れた書き手**
     *            (secret を変えた場合)。★復号しない |
     *
     * 暗号文は保存のたびに変わりうる (同じ平文でも再暗号化で別の暗号文になる) ので、
     * 第 3 層の比較は**空振りする側 = 拒否する側**へ倒れる。fail-closed であり安全側である
     * (運営はもう一度押せばよい)。
     *
     * ## この形が保証すること / しないこと
     *
     * - **保証する**: 外向き取得の開始から完了までの間に認証材料が変わったなら、
     *   その `verify` の結果は**採用されない** (`Draft` のまま拒否される)
     * - **保証する**: 外向き取得の**間、接続の行のロックを保持しない**
     * - **保証する**: `verify` の経路は **client secret を一度も復号しない**
     * - **保証しない**: 「取得した瞬間に IdP 側が正しかった」こと。IdP は `verify` の**後**に
     *   いつでも構成を変えられる。`Verified` は**そのときの取得が成功した**という記録に過ぎない
     * - **保証しない**: 拒否された `verify` の**自動再実行** (運営がもう一度押す)
     *
     * @throws EnterpriseSsoAttemptRejectedException 第 2 段の外向き取得に失敗した
     *                                               (★接続の状態は変えない。可用性の後退を作らない)
     * @throws OidcConnectionTransitionException 遷移表に無い状態から呼ばれた
     */
    public function verify(Organization $organization, OrganizationOidcConnection $connection): VerifyOutcome
    {
        // ── 第 1 段: スナップショット (ロックなし)
        // ★**行を読み直してから撮る**。呼び出し側が渡してきたインスタンスの
        //   `getRawOriginal()` は、直前に保存した直後だと保存に使った暗号文と食い違うことがある
        //   (暗号化のたびに別の暗号文になるため)。比べたいのは「**保存されている暗号文**が
        //   取得の間に変わったか」なので、スナップショットも保存された値から撮る。
        // ★ここも **relation 起点**である (組織スコープを入口の binding だけに依存させない)。
        $current = $organization->oidcConnections()->whereKey($connection->id)->first();

        if ($current === null) {
            return VerifyOutcome::ConnectionGone;   // アーリーリターン
        }

        $snapshot = ConnectionCredentialsSnapshot::of($current);

        // ── 第 2 段: 外向き取得 (ロックなし・トランザクションの外)
        //    取得の失敗で接続の状態を変えない (「取得の失敗で接続を殺さない」)。
        $metadata = $this->discovery->fetchMetadata(OidcIssuerUrl::fromString($snapshot->issuer));
        $this->discovery->fetchJwks($metadata);

        // ── 第 3 段: 一致の再確認と遷移 (ここで初めてトランザクションと行ロック)
        return DB::transaction(function () use ($organization, $snapshot): VerifyOutcome {
            // ★**relation 起点で引く**。親は scoped binding で解決済みの $organization であり、
            //   ★**payload 由来の組織 id をここへ入れない**。
            $fresh = $organization->oidcConnections()
                ->whereKey($snapshot->connectionId)
                ->lockForUpdate()
                ->first();

            // 接続が消えていた (または組織の外へ出た) → 結果を捨てる (アーリーリターン)
            if ($fresh === null) {
                return VerifyOutcome::ConnectionGone;
            }

            // ★**主の比較子は credentials_revision** である。
            if ($fresh->credentials_revision !== $snapshot->credentialsRevision) {
                return VerifyOutcome::StaleCredentials;   // ★結果を捨てる。Draft のまま
            }

            // ★**第 2 / 第 3 の比較子**。主の代わりではなく、「+1 を忘れた書き手がいたら落ちる」層。
            if ($fresh->issuer !== $snapshot->issuer
                || $fresh->client_id !== $snapshot->clientId
                || ! hash_equals($snapshot->clientSecretCiphertextDigest, $fresh->clientSecretCiphertextDigest())
            ) {
                return VerifyOutcome::StaleCredentials;
            }

            // ★同じ材料を別の要求が既に Verified にしていた場合は、何もせず成功とする。
            if ($fresh->status === OidcConnectionStatus::Verified) {
                return VerifyOutcome::AlreadyVerified;
            }

            // Draft 以外 (Active / Disabled) からは遷移しない。定義外の遷移は例外。
            if ($fresh->status !== OidcConnectionStatus::Draft) {
                throw OidcConnectionTransitionException::of(ConnectionTransitionRejection::UndefinedTransition);
            }

            $fresh->forceFill([
                'status' => OidcConnectionStatus::Verified,
                'verified_at' => now(),
            ])->save();

            return VerifyOutcome::Verified;
        });
    }

    /**
     * 有効化する (Verified → Active / Disabled → Active)。
     *
     * ★`Disabled` から戻せるのは `verified_at` が残っている場合だけである
     *   (一度も確認できていない構成でログインを開けない)。
     *
     * @throws OidcConnectionTransitionException
     */
    public function activate(Organization $organization, int $connectionId): OrganizationOidcConnection
    {
        return $this->withLockedConnection(
            $organization,
            $connectionId,
            static function (OrganizationOidcConnection $locked): OrganizationOidcConnection {
                $allowed = $locked->status === OidcConnectionStatus::Verified
                    || ($locked->status === OidcConnectionStatus::Disabled && $locked->verified_at !== null);

                if (! $allowed) {
                    throw OidcConnectionTransitionException::of(ConnectionTransitionRejection::UndefinedTransition);
                }

                $locked->forceFill(['status' => OidcConnectionStatus::Active])->save();

                return $locked;
            },
        );
    }

    /**
     * 無効化する (Active → Disabled)。
     *
     * ★C2 の callback と**同じ行をロックする**ので両者は直列化される。
     *   無効化が先に線形化したら JIT もログインも起きず、callback が先なら
     *   無効化はその後に成立する (次回から入れない)。
     *
     * @throws OidcConnectionTransitionException
     */
    public function disable(Organization $organization, int $connectionId): OrganizationOidcConnection
    {
        return $this->withLockedConnection(
            $organization,
            $connectionId,
            static function (OrganizationOidcConnection $locked): OrganizationOidcConnection {
                if ($locked->status !== OidcConnectionStatus::Active) {
                    throw OidcConnectionTransitionException::of(ConnectionTransitionRejection::UndefinedTransition);
                }

                $locked->forceFill(['status' => OidcConnectionStatus::Disabled])->save();

                return $locked;
            },
        );
    }

    /**
     * 物理削除する。
     *
     * ★**身元が 1 件でもある接続は消せない**。消すと身元だけが消えて利用者が残り、
     *   同じ IdP を再登録したときに同じ subject で**新しい利用者が JIT で作られる**
     *   (アカウントの分裂)。企業 SSO でしか入れない利用者は元のアカウントへ二度と戻れない。
     *   運用は**無効化**で行う (無効化なら身元は残り、再び有効にしたときに同じ利用者へ戻る)。
     *
     * @throws OidcConnectionTransitionException
     */
    public function destroy(Organization $organization, int $connectionId): void
    {
        $this->withLockedConnection(
            $organization,
            $connectionId,
            static function (OrganizationOidcConnection $locked): OrganizationOidcConnection {
                if ($locked->identities()->exists()) {
                    throw OidcConnectionTransitionException::of(
                        ConnectionTransitionRejection::IdentitiesExistCannotDelete,
                    );
                }

                $locked->delete();

                return $locked;
            },
        );
    }

    /**
     * ★issuer / client_id / client_secret のいずれかを変える**唯一の書き手**。
     *
     * 3 つを 1 か所に閉じ込めるのは、`credentials_revision` の +1 を
     * 「書き手が思い出す規律」ではなく「経路の性質」にするためである。
     */
    private function applyCredentialChange(
        OrganizationOidcConnection $locked,
        ?OidcIssuerUrl $issuer,
        ?string $clientId,
        #[SensitiveParameter] ?ConnectionSecret $clientSecret,
    ): void {
        $changes = [];

        if ($issuer !== null) {
            $changes['issuer'] = $issuer->value;
        }

        if ($clientId !== null) {
            $changes['client_id'] = $clientId;
        }

        if ($clientSecret !== null) {
            $changes['client_secret_encrypted'] = $clientSecret;
        }

        // ★必ず +1 し、必ず Draft へ戻し、verified_at を消す。
        $changes['credentials_revision'] = $locked->credentials_revision + 1;
        $changes['status'] = OidcConnectionStatus::Draft;
        $changes['verified_at'] = null;

        $locked->forceFill($changes)->save();
    }

    /**
     * ★ロック付きの再取得を **relation 起点**に統一する 1 本道。
     *
     * 接続が組織の外にある / 消えている場合は 404 として扱えるよう `ModelNotFoundException` を
     * そのまま伝播させる (`firstOrFail`)。層 2 (テナント境界 = 404) は層 3 (認可 = 403) より前で
     * 閉じており、ここは route の scoped binding が既に通した後の**再取得**である。
     *
     * @param  Closure(OrganizationOidcConnection): OrganizationOidcConnection  $callback
     */
    private function withLockedConnection(
        Organization $organization,
        int $connectionId,
        Closure $callback,
    ): OrganizationOidcConnection {
        return DB::transaction(function () use ($organization, $connectionId, $callback): OrganizationOidcConnection {
            $locked = $organization->oidcConnections()
                ->whereKey($connectionId)
                ->lockForUpdate()
                ->firstOrFail();

            return $callback($locked);
        });
    }
}
