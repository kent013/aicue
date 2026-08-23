<?php

declare(strict_types=1);

namespace App\DataTransferObjects\EnterpriseSso;

use App\Enums\EnterpriseSso\OidcSigningAlgorithm;
use App\Enums\EnterpriseSso\RejectionReason;
use App\Enums\EnterpriseSso\TokenEndpointAuthMethod;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use JsonException;

/**
 * IdP の接続先情報 (OIDC Discovery 文書のうち**本アプリが使う要素だけ**)。
 *
 * ★**未知の要素を `array<string, mixed>` のまま内側へ出さない**。
 *   必要な要素だけを「存在」と「具体型」を検査してから組み立てる。
 */
final readonly class OidcProviderMetadata
{
    /**
     * @param  non-empty-list<TokenEndpointAuthMethod>  $tokenEndpointAuthMethods
     * @param  non-empty-list<OidcSigningAlgorithm>  $idTokenSigningAlgorithms  IdP が広告した署名方式
     */
    private function __construct(
        public OidcIssuerUrl $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public array $tokenEndpointAuthMethods,
        public array $idTokenSigningAlgorithms,
    ) {}

    /**
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public static function fromResponseBody(string $body, OidcIssuerUrl $expectedIssuer): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // ★例外は **理由の enum だけ**を受け取る形に統一する。
            //   previous を受け取れない構築子なので、body が例外の連鎖で展開される経路が型で消える。
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNotJson);
        }

        if (! is_array($decoded)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNotObject);
        }

        $issuer = OidcIssuerUrl::fromString(self::requireString($decoded, 'issuer'));
        if (! hash_equals($expectedIssuer->value, $issuer->value)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryIssuerMismatch);
        }

        // 各 endpoint は https の絶対 URL であること。
        // ★**同一 origin は要求しない** — OIDC 標準の要件ではなく、実在の IdP
        //   (issuer と JWKS が別 origin) を拒否してしまう。
        // ★**query は禁じない** (禁じる標準上の根拠が無い)。
        $authorization = self::requireHttpsUrl($decoded, 'authorization_endpoint');
        $token = self::requireHttpsUrl($decoded, 'token_endpoint');
        $jwks = self::requireHttpsUrl($decoded, 'jwks_uri');

        // ★`token_endpoint_auth_methods_supported` は OIDC Discovery で **optional** であり、
        //   欠落時の既定は `client_secret_basic` である (仕様)。
        //   欠落を「対応方式なし」として拒否すると**仕様準拠の IdP を拒否する**。
        $methods = self::supportedAuthMethods($decoded);
        if ($methods === []) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedAuthMethod);
        }

        // ★`id_token_signing_alg_values_supported` は OIDC Discovery の **必須項目**である。
        //   アプリの許可集合との共通部分を取り、空なら拒否する。
        $algorithms = self::supportedSigningAlgorithms($decoded);
        if ($algorithms === []) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedSigningAlg);
        }

        return new self($issuer, $authorization, $token, $jwks, $methods, $algorithms);
    }

    /**
     * キャッシュから読み戻す (**素の配列から明示的に組み立て直して検査する**)。
     *
     * 破損 / 空配列 / 未知の値のいずれでも null を返し、呼び出し側が `forget` する。
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromCachePayload(array $payload): ?self
    {
        $issuerValue = $payload['issuer'] ?? null;
        $authorization = $payload['authorization_endpoint'] ?? null;
        $token = $payload['token_endpoint'] ?? null;
        $jwks = $payload['jwks_uri'] ?? null;
        $methods = $payload['auth_methods'] ?? null;
        $algorithms = $payload['id_token_signing_algorithms'] ?? null;

        if (! is_string($issuerValue) || ! is_string($authorization) || ! is_string($token) || ! is_string($jwks)) {
            return null;
        }

        if (! is_array($methods) || ! is_array($algorithms)) {
            return null;
        }

        if (! OidcIssuerUrl::isValid($issuerValue) || ! self::isHttpsAbsoluteUrl($authorization)
            || ! self::isHttpsAbsoluteUrl($token) || ! self::isHttpsAbsoluteUrl($jwks)
        ) {
            return null;
        }

        /** @var list<TokenEndpointAuthMethod> $decodedMethods */
        $decodedMethods = [];
        foreach ($methods as $method) {
            if (! is_string($method)) {
                return null;
            }
            $case = TokenEndpointAuthMethod::tryFrom($method);
            if ($case === null) {
                return null;
            }
            $decodedMethods[] = $case;
        }

        /** @var list<OidcSigningAlgorithm> $decodedAlgorithms */
        $decodedAlgorithms = [];
        foreach ($algorithms as $algorithm) {
            if (! is_string($algorithm)) {
                return null;
            }
            $case = OidcSigningAlgorithm::tryFrom($algorithm);
            if ($case === null) {
                return null;
            }
            $decodedAlgorithms[] = $case;
        }

        if ($decodedMethods === [] || $decodedAlgorithms === []) {
            return null;
        }

        return new self(
            OidcIssuerUrl::fromString($issuerValue),
            $authorization,
            $token,
            $jwks,
            $decodedMethods,
            $decodedAlgorithms,
        );
    }

    /**
     * キャッシュへ入れる形 (**素の配列とスカラーだけ**。セキュリティ不変条件 11)。
     *
     * ★**広告された署名方式も保存する** — 保存しないとキャッシュ hit の後に
     *   「アプリの許可集合 ∩ IdP の広告集合」が成立しない。
     *
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string,
     *               jwks_uri: string, auth_methods: non-empty-list<string>,
     *               id_token_signing_algorithms: non-empty-list<string>}
     */
    public function toCachePayload(): array
    {
        $methods = [];
        foreach ($this->tokenEndpointAuthMethods as $method) {
            $methods[] = $method->value;
        }

        $algorithms = [];
        foreach ($this->idTokenSigningAlgorithms as $algorithm) {
            $algorithms[] = $algorithm->value;
        }

        return [
            'issuer' => $this->issuer->value,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'jwks_uri' => $this->jwksUri,
            'auth_methods' => $methods,
            'id_token_signing_algorithms' => $algorithms,
        ];
    }

    /** IdP が広告した署名方式にこの alg が含まれるか。 */
    public function advertises(OidcSigningAlgorithm $algorithm): bool
    {
        return in_array($algorithm, $this->idTokenSigningAlgorithms, true);
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     */
    private static function requireString(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryMissingField);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     */
    private static function requireHttpsUrl(array $decoded, string $key): string
    {
        $value = self::requireString($decoded, $key);

        if (! self::isHttpsAbsoluteUrl($value)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryInvalidEndpoint);
        }

        return $value;
    }

    /** https の絶対 URL で userinfo と fragment を持たないこと (query は許す)。 */
    private static function isHttpsAbsoluteUrl(string $value): bool
    {
        $parts = parse_url($value);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        if (($parts['host'] ?? '') === '') {
            return false;
        }

        return ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment']);
    }

    /**
     * ★欠落は `[ClientSecretBasic]` (仕様の既定)。明示されていてどちらも無いときだけ空を返す。
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<TokenEndpointAuthMethod>
     */
    private static function supportedAuthMethods(array $decoded): array
    {
        if (! array_key_exists('token_endpoint_auth_methods_supported', $decoded)) {
            return [TokenEndpointAuthMethod::ClientSecretBasic];
        }

        $declared = $decoded['token_endpoint_auth_methods_supported'];
        if (! is_array($declared)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryMissingField);
        }

        // ★basic を優先する (body 漏洩面が小さい)。
        $supported = [];
        foreach ([TokenEndpointAuthMethod::ClientSecretBasic, TokenEndpointAuthMethod::ClientSecretPost] as $method) {
            if (in_array($method->value, $declared, true)) {
                $supported[] = $method;
            }
        }

        return $supported;
    }

    /**
     * ★必須項目。欠落・非配列・具体型の違反はいずれも拒否する。
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<OidcSigningAlgorithm>
     */
    private static function supportedSigningAlgorithms(array $decoded): array
    {
        $declared = $decoded['id_token_signing_alg_values_supported'] ?? null;

        if (! is_array($declared)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryMissingField);
        }

        $supported = [];
        foreach (OidcSigningAlgorithm::cases() as $algorithm) {
            if (in_array($algorithm->value, $declared, true)) {
                $supported[] = $algorithm;
            }
        }

        return $supported;
    }
}
