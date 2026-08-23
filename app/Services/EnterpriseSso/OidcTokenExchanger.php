<?php

declare(strict_types=1);

namespace App\Services\EnterpriseSso;

use App\DataTransferObjects\EnterpriseSso\OidcProviderMetadata;
use App\DataTransferObjects\EnterpriseSso\OidcTokenResponse;
use App\Enums\EnterpriseSso\RejectionReason;
use App\Enums\EnterpriseSso\TokenEndpointAuthMethod;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Models\OrganizationOidcConnection;
use App\Support\EnterpriseSso\BasicCredentials;
use Illuminate\Support\Facades\Config;
use Kent013\SsrfPin\Dtos\Deadline;
use Kent013\SsrfPin\Dtos\PinnedFailure;
use Kent013\SsrfPin\Dtos\PinnedRequest;
use Kent013\SsrfPin\PinnedHttpClient;
use SensitiveParameter;

/**
 * 認可コードとトークンの交換。
 *
 * ★本サービスは `kent013/laravel-ssrf-pin` ^0.4 の「**要求 body を運べる pin 済み取得**」を
 *   必要とする (v0.2 系では実装そのものが成立しない)。
 *
 * ## 秘密を漏らさないための 4 点
 *
 *  1. **vendor の例外を外へ連結しない** — previous に載せると、要求 body (認可コード /
 *     client secret / code_verifier) が例外の連鎖からログへ展開されうる。
 *     境界で**固定の理由コードの例外**へ変換する。
 *     `EnterpriseSsoAttemptRejectedException` は **`previous` を受け取れない構築子**を持つので、
 *     型で連鎖が起きない
 *  2. 平文を受ける引数に **`#[SensitiveParameter]`** を付ける (スタックトレースに出さない)
 *  3. client secret は `ConnectionSecret::revealForTokenExchange()` で**ここでだけ**平文化する
 *     (呼び出し元は gate が exact-fit で pin する)
 *  4. client 認証は **`client_secret_basic` を優先** (body 漏洩面が小さい)。
 *     IdP が対応しない場合だけ `client_secret_post` へ落とす
 *
 * ★**リダイレクトを追従しない**。追従すると転送先へ資格情報つきの要求が飛びうる
 *   (^0.4 の client は 2 hop 目以降 body を落とすが、ヘッダの Basic は落ちない)。
 */
final readonly class OidcTokenExchanger
{
    public function __construct(private PinnedHttpClient $pinned) {}

    /**
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public function exchange(
        OrganizationOidcConnection $connection,
        OidcProviderMetadata $metadata,
        string $redirectUri,
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $codeVerifier,
    ): OidcTokenResponse {
        $method = $this->chooseAuthMethod($metadata);

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $connection->client_id,
            'code_verifier' => $codeVerifier,            // ★PKCE の往復の片端
        ];

        $headers = ['Accept' => 'application/json'];
        if ($method === TokenEndpointAuthMethod::ClientSecretBasic) {
            $headers['Authorization'] = BasicCredentials::header(
                $connection->client_id,
                $connection->clientSecret()->revealForTokenExchange(),
            );
        } else {
            $form['client_secret'] = $connection->clientSecret()->revealForTokenExchange();
        }

        $request = new PinnedRequest(
            method: 'POST',
            url: $metadata->tokenEndpoint,
            headers: $headers,
            connectTimeout: (float) Config::integer('enterprise-sso.token.connect_timeout_seconds'),
            body: http_build_query($form, '', '&', PHP_QUERY_RFC1738),
            contentType: 'application/x-www-form-urlencoded',
        );

        $result = $this->pinned->fetch(
            $request,
            Deadline::afterSeconds((float) Config::integer('enterprise-sso.token.request_timeout_seconds')),
            followRedirects: false,
        );

        // ★fetch() は PinnedResponse|PinnedFailure を返す。**失敗は値で返る**ので
        //   catch では捕まらない。明示的に分岐して固定の理由コードへ変換する。
        if ($result instanceof PinnedFailure) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenExchangeFailed);
        }

        // ★3xx を成功として扱わない。
        if ($result->status < 200 || $result->status >= 300) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenExchangeFailed);
        }

        if (strlen($result->body) > Config::integer('enterprise-sso.token.max_body_bytes')) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenResponseMalformed);
        }

        return OidcTokenResponse::fromResponseBody($result->body);
    }

    /**
     * ★basic を優先する (body 漏洩面が小さい)。どちらも無ければ拒否する。
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    private function chooseAuthMethod(OidcProviderMetadata $metadata): TokenEndpointAuthMethod
    {
        foreach ([TokenEndpointAuthMethod::ClientSecretBasic, TokenEndpointAuthMethod::ClientSecretPost] as $method) {
            if (in_array($method, $metadata->tokenEndpointAuthMethods, true)) {
                return $method;
            }
        }

        throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedAuthMethod);
    }
}
