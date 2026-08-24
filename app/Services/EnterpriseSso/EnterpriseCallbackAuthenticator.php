<?php

declare(strict_types=1);

namespace App\Services\EnterpriseSso;

use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Models\User;
use App\Support\EnterpriseSso\AttemptFingerprint;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * 企業 SSO の戻り口の application service。
 *
 * ## 順序 (理由つき)
 *
 *  1. **入力の検査**は FormRequest が済ませている (スカラー型・長さ上限・code と error の排他)。
 *     ★不正な入力では**外向き取得を一切開始しない**
 *  2. IdP の error 応答は一様な失敗として扱う (呼び出し側が判定済み)
 *  3. セッションから**結合の秘密**を取り出す (state の指紋から試行ごとのキーを導く)。
 *     **非空の文字列でなければ、外向き取得を始めずに一様拒否する**
 *  4. **consume** (試行の行のロック。トランザクションを**閉じる**) —
 *     ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる。
 *     ★`consume()` は**投げずに分類を返す**ので、**本サービスが**
 *     「行が消えた失敗ならセッションの秘密も消す / 結合の不一致なら残す」を決め、
 *     そのうえで**外向きの一様な例外**へ変換する
 *  5. 外向き取得 (discovery → token 交換 → JWKS) と ID トークンの検証。
 *     ★この間はどのロックも持たない
 *  6. **線形化の区間**: 1 つのトランザクションで
 *       接続の行を `lockForUpdate()` → **Active を確認** → **JIT** → commit
 *
 * ## 無効化 (disable) との線形化
 *
 * 「Active を 2 回読む」だけでは競合を閉じられない (最終確認の直後に disable が commit され、
 * その後ログインが確定する窓が残る)。また JIT を確認より前に置くと、
 * **拒否されたのに利用者・身元・所属だけが残る**。
 *
 * ★**線形化点を接続の行ロックに定める**。上の 6 が線形化の区間であり、
 *   {@see OidcConnectionTransitionService} の無効化も**同じ行を `lockForUpdate()` する**。
 *   したがって両者は直列化され、次の 2 つが成り立つ:
 *     - **無効化が先に線形化したら、JIT もログインも起きない** (Active の確認で落ち、
 *       同一トランザクションなので副作用が巻き戻る)
 *     - **callback が先なら、無効化はその後に成立する** (次回から入れない)
 *   commit の後・`Auth::login` の前に disable が入る窓は残るが、それは
 *   「無効化より前に線形化したログイン」であり、**既存セッションの即時失効はスコープ外**という
 *   本設計の主張と整合する。
 *
 * ## 身元の名前空間を壊さない
 *
 * OIDC の身元は実質 **(issuer, subject)** であり、pairwise subject では
 * **client_id も名前空間を変えうる**。同じ接続の issuer や client_id を別の IdP のものへ
 * 変えた後に偶然同じ subject が返ると、**以前の利用者へ誤ってログインさせる**。
 * これを防ぐのは D1 の更新規則である —
 * **身元が 1 件でもある接続では issuer と client_id を変更できない**。
 * 本サービスは「接続 id で身元を引く」形のままでよい (名前空間の不変性を D1 が保証する)。
 */
final readonly class EnterpriseCallbackAuthenticator
{
    /** セッションに置くブラウザ結合の秘密のキーの接頭辞 (**state の指紋ごとに分ける**)。 */
    private const string BINDING_SESSION_PREFIX = 'enterprise-sso.binding.';

    public function __construct(
        private EnterpriseLoginAttemptStore $attempts,
        private OidcDiscoveryService $discovery,
        private OidcTokenExchanger $exchanger,
        private EnterpriseIdTokenVerifier $verifier,
        private EnterpriseUserProvisioner $provisioner,
    ) {}

    /** 開始側がセッションへ結合の秘密を置くときのキー (state の指紋ごとに分ける)。 */
    public static function bindingSessionKey(string $stateFingerprint): string
    {
        return self::BINDING_SESSION_PREFIX.$stateFingerprint;
    }

    /**
     * 戻り口の本体。失敗はすべて**一様な例外**になる。
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public function authenticate(
        Session $session,
        #[SensitiveParameter] string $state,
        #[SensitiveParameter] string $code,
        string $redirectUri,
    ): User {
        $bindingKey = self::bindingSessionKey(AttemptFingerprint::of(FingerprintPurpose::State, $state));

        /** @var mixed $bindingSecret */
        $bindingSecret = $session->get($bindingKey);

        // ★結合の秘密がセッションに無い / 非文字列なら、**外向き取得を始めずに**拒否する。
        if (! is_string($bindingSecret) || $bindingSecret === '') {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::AttemptBindingMissing);
        }

        $result = $this->attempts->consume($state, $bindingSecret);

        // ★行が不可逆に消えた失敗ではセッションの秘密も消す (再開できない試行の秘密を残さない)。
        //   結合の不一致では**行もセッションの秘密も残す** (攻撃者が被害者の結合を消せる形にしない)。
        if ($result->rowIsGone) {
            $session->forget($bindingKey);
        }

        if (! $result->succeeded || $result->attempt === null) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::AttemptNotFound);
        }

        $attempt = $result->attempt;
        $connection = $attempt->connection;

        // ★明らかに使えない接続で外部へ出ないための足切り。
        //   これは競合を閉じる線形化点ではない (線形化点は下の行ロックである)。
        if ($connection->status !== OidcConnectionStatus::Active) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::ConnectionNotUsable);
        }

        // ★ここから外向き取得。**どのロックも持っていない**。
        $metadata = $this->discovery->fetchMetadata(OidcIssuerUrl::fromString($connection->issuer));

        $tokens = $this->exchanger->exchange($connection, $metadata, $redirectUri, $code, $attempt->codeVerifier);

        $jwks = $this->discovery->fetchJwks($metadata);

        $claims = $this->verifier->verify(
            $connection,
            $metadata,
            $jwks,
            $tokens->idToken,
            $attempt->nonceFingerprint,
        );

        // ★**線形化の区間**。接続の行をロックして Active を確認してから JIT する。
        return DB::transaction(function () use ($connection, $claims): User {
            $locked = $connection->organization?->oidcConnections()
                ->whereKey($connection->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== OidcConnectionStatus::Active) {
                // ★同一トランザクションなので、ここで落ちれば副作用は 1 バイトも残らない。
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::ConnectionNotUsable);
            }

            return $this->provisioner->resolve($locked, $claims);
        });
    }
}
