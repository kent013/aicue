<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EnterpriseSsoCallbackRequest;
use App\Models\OrganizationOidcConnection;
use App\Services\EnterpriseSso\EnterpriseCallbackAuthenticator;
use App\Services\EnterpriseSso\EnterpriseLoginAttemptStore;
use App\Services\EnterpriseSso\OidcDiscoveryService;
use App\Support\EnterpriseSso\AttemptFingerprint;
use App\Support\EnterpriseSso\UniformLoginFailure;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * 企業 IdP との OIDC SSO のログイン導線。
 *
 * ★**待機ログインを作らない** (家系の裁定 AG-200)。確認できた時点で `Auth::login()` で
 *   ログインを確定させ、画面へ送る。**2 要素認証の入力画面へ転送する分岐を持たない**。
 *   これは tests/Architecture/SsoTwoFactorInterpositionGateTest が企業・ソーシャルの
 *   両 controller に対して静的に裏当てし、主たる証明は
 *   tests/Feature/Auth/EnterpriseSsoLoginTest の実挙動側にある。
 *   組織の 2 要素義務づけの強制は別関門 (`RequireTwoFactorForEnforcedOrganizations`) が
 *   **ログイン確定後**にアカウント全体のゲートとして担い、転送先は 2 要素の**設定ページ**である。
 *
 * ★`remember: false` である。remember cookie を許すと、接続を無効化した後も
 *   cookie から新しいセッションを開始できてしまい、
 *   「次回ログインができなくなる」という効果の主張と整合しない。
 *
 * ★失敗の応答は**一様**である (接続や利用者の存在を読み取れない)。
 *   組み立てるのは {@see UniformLoginFailure} の 1 か所だけで、
 *   **FormRequest の validation 失敗も同じ応答を通る** (入力を 1 つも flash しない)。
 *   理由の区別が出るのは**内部のログの理由コードだけ**である。
 */
class EnterpriseSsoLoginController extends Controller
{
    /**
     * 企業ログインの入口 (識別名の入力画面)。
     *
     * ★外向き通信をしない。DB も変えない。
     */
    public function show(): InertiaResponse
    {
        return Inertia::render('Auth/EnterpriseLogin');
    }

    /**
     * 開始。**行を作ってからリダイレクトする** (逆順だと戻ってきた state が存在しない)。
     *
     *  1. 接続を識別名で解決し、**Active であること**を確かめる
     *  2. CSPRNG で state / nonce / PKCE の検証子 / ブラウザ結合の秘密を各 32 バイト生成する
     *  3. ブラウザ結合の秘密を**セッションへ置く** (キーは state の指紋ごとに分ける = 複数タブ共存)
     *  4. 試行の行を作る (state / nonce / 結合の指紋 + 暗号化した検証子 + 期限)
     *  5. 認可要求の URL を組み立ててリダイレクトする
     *
     * ★GET だが **DB に試行の行を作る変更操作**である (OAuth の開始)。
     *   CSRF トークンの代わりに state・ブラウザ結合・流量制限・no-store が守る。
     */
    public function redirect(
        Request $request,
        OrganizationOidcConnection $connection,
        EnterpriseLoginAttemptStore $attempts,
        OidcDiscoveryService $discovery,
    ): RedirectResponse {
        // ★接続の解決と「使えるか」の判定は PublicOidcConnectionBinder が済ませている。
        //   **不在の識別名と使えない接続は binder の段で同じ 404 に畳まれ**、
        //   route の missing() が利用者向けの一様な案内へ変える (実在オラクルを作らない)。
        //   したがって**無効な接続で行が作られることはない**。

        try {
            $metadata = $discovery->fetchMetadata(OidcIssuerUrl::fromString($connection->issuer));
        } catch (EnterpriseSsoAttemptRejectedException $e) {
            return UniformLoginFailure::response($e->reason);
        }

        $state = AttemptFingerprint::newSecret();
        $nonce = AttemptFingerprint::newSecret();
        $codeVerifier = AttemptFingerprint::newSecret();
        $bindingSecret = AttemptFingerprint::newSecret();

        // ★セッションのキーは state の指紋ごとに分ける (複数タブが共存できる)。
        $request->session()->put(
            EnterpriseCallbackAuthenticator::bindingSessionKey(
                AttemptFingerprint::of(FingerprintPurpose::State, $state),
            ),
            $bindingSecret,
        );

        // ★**行を作ってからリダイレクトする**。
        $attempts->start($connection, $state, $nonce, $codeVerifier, $bindingSecret);

        return redirect()->away($this->authorizationUrl($metadata->authorizationEndpoint, [
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'client_id' => $connection->client_id,
            'redirect_uri' => route('enterprise-sso.callback'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ]));
    }

    /**
     * 戻り口。
     *
     * ★ここで `redirect()->intended()` を使うのは**ログイン直後フロー**だからである
     *   (禁止事項 7 の明示的な適用範囲内。既存の `SocialAuthController` と同じ形)。
     */
    public function callback(
        EnterpriseSsoCallbackRequest $request,
        EnterpriseCallbackAuthenticator $authenticator,
    ): RedirectResponse {
        if ($request->providerReturnedError()) {
            return UniformLoginFailure::response(RejectionReason::ProviderReturnedError);
        }

        try {
            $user = $authenticator->authenticate(
                $request->session(),
                $request->stateValue(),
                $request->codeValue(),
                route('enterprise-sso.callback'),
            );
        } catch (EnterpriseSsoAttemptRejectedException $e) {
            return UniformLoginFailure::response($e->reason);
        }

        Auth::login($user, remember: false);
        $request->session()->regenerate();

        return redirect()->intended(route('app.entry'));
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function authorizationUrl(string $endpoint, array $parameters): string
    {
        // ★endpoint は query を持ちうる (discovery で禁じていない)。既存の query を保つ。
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /** PKCE (S256) の challenge。 */
    private function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
