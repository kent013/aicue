<?php

declare(strict_types=1);

namespace App\Services\EnterpriseSso;

use App\DataTransferObjects\EnterpriseSso\OidcJsonWebKeySet;
use App\DataTransferObjects\EnterpriseSso\OidcProviderMetadata;
use App\DataTransferObjects\EnterpriseSso\VerifiedIdTokenClaims;
use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Enums\EnterpriseSso\OidcSigningAlgorithm;
use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Models\OrganizationOidcConnection;
use App\Support\EnterpriseSso\AttemptFingerprint;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Config;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * ID トークンの検証 (deny-by-default。1 つでも該当したらその試行を拒否する)。
 *
 * ## 拒否条件
 *
 * | 層 | 拒否する条件 |
 * |---|---|
 * | JWT の形 | malformed (3 セグメントでない / base64url でない / ヘッダが JSON でない) |
 * | ヘッダ | `alg` が {@see OidcSigningAlgorithm} の case でない (`none` / HMAC は enum に無い) /
 * |        | **`alg` が IdP の広告集合に無い** / `kid` の欠落 |
 * | JWKS | `kid` に一致する鍵が無い (→ **再取得を 1 回だけ**) / `kid` の重複 /
 * |      | `kty` が `alg` と不整合 / EC の `crv` が不整合 /
 * |      | **`use` が存在するのに** `sig` でない / **`key_ops` が存在するのに** `verify` を含まない |
 * | 署名 | 検証に失敗した |
 * | claim の型 | `iss` / `sub` / `nonce` が文字列でない / `aud` が文字列でも文字列配列でもない /
 * |            | `exp` / `iat` / `nbf` が整数でない |
 * | claim の値 | `iss` 不一致 / `sub` が空・長さ超過 / **`exp` の欠落** / **`iat` の欠落** /
 * |            | `exp` 超過 / `iat` が未来 / `nbf` が未来 / `nonce` の指紋が試行と不一致 |
 * | audience | (1) `aud` は必ず client_id を含む / (2) `aud` が複数なら `azp` は必須 /
 * |          | (3) `azp` が存在するなら文字列で client_id と一致 |
 *
 * ★理由コードは条件ごとに分けるが、**利用者への応答は一様**である
 *   (区別が出るのは内部のログだけ)。
 */
final readonly class EnterpriseIdTokenVerifier
{
    public function __construct(private OidcDiscoveryService $discovery) {}

    /**
     * @param  string  $expectedNonceFingerprint  試行が保持している nonce の指紋 (原文ではない)
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public function verify(
        OrganizationOidcConnection $connection,
        OidcProviderMetadata $metadata,
        OidcJsonWebKeySet $jwks,
        #[SensitiveParameter] string $idToken,
        string $expectedNonceFingerprint,
    ): VerifiedIdTokenClaims {
        $header = $this->decodeHeader($idToken);

        $algorithm = OidcSigningAlgorithm::tryFrom($header['alg']);
        if ($algorithm === null) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAlgorithmNotAllowed);
        }

        // ★アプリの許可集合と **IdP の広告集合の両方**に入ることを要求する。
        if (! $metadata->advertises($algorithm)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAlgorithmNotAllowed);
        }

        // ★未知の kid なら**1 回だけ**取り直す (再帰しない)。
        if (! $jwks->has($header['kid'])) {
            $jwks = $this->discovery->refetchJwks($metadata, $connection->id);
        }

        $jwk = $jwks->keyFor($header['kid'], $algorithm);

        $payload = $this->decodePayload($idToken, $jwk, $algorithm);

        return $this->claimsFrom($connection, $metadata, $payload, $expectedNonceFingerprint);
    }

    /**
     * @return array{alg: string, kid: string}
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    private function decodeHeader(#[SensitiveParameter] string $idToken): array
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenMalformed);
        }

        $raw = base64_decode(strtr($segments[0], '-_', '+/'), true);
        if ($raw === false) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenMalformed);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true);
        if (! is_array($decoded)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenMalformed);
        }

        $algorithm = $decoded['alg'] ?? null;
        if (! is_string($algorithm) || $algorithm === '') {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAlgorithmNotAllowed);
        }

        $keyId = $decoded['kid'] ?? null;
        if (! is_string($keyId) || $keyId === '') {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenKeyIdMissing);
        }

        return ['alg' => $algorithm, 'kid' => $keyId];
    }

    /**
     * @param  array<string, string>  $jwk
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    private function decodePayload(
        #[SensitiveParameter] string $idToken,
        array $jwk,
        OidcSigningAlgorithm $algorithm,
    ): stdClass {
        // ★`firebase/php-jwt` は既定で `exp` / `nbf` / `iat` を見るが、
        //   **欠落は例外にしない**ので、値の検査は本クラスが自分で行う (下の claimsFrom)。
        //   ここで vendor に任せるのは**署名の検証だけ**である。
        JWT::$leeway = Config::integer('enterprise-sso.id_token.leeway_seconds');

        try {
            $material = JWK::parseKey($jwk, $algorithm->value);
            if (! $material instanceof Key) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
            }

            $payload = JWT::decode($idToken, $material);
        } catch (EnterpriseSsoAttemptRejectedException $e) {
            throw $e;
        } catch (Throwable) {
            // ★vendor の例外を**連結しない** (previous を受け取れない構築子なので型で無理)。
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenSignatureInvalid);
        }

        return $payload;
    }

    /**
     * @throws EnterpriseSsoAttemptRejectedException
     */
    private function claimsFrom(
        OrganizationOidcConnection $connection,
        OidcProviderMetadata $metadata,
        stdClass $payload,
        string $expectedNonceFingerprint,
    ): VerifiedIdTokenClaims {
        /** @var array<string, mixed> $claims */
        $claims = get_object_vars($payload);

        $issuer = $claims['iss'] ?? null;
        if (! is_string($issuer)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
        }
        if (! hash_equals($metadata->issuer->value, $issuer)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenIssuerMismatch);
        }

        $subject = $claims['sub'] ?? null;
        if (! is_string($subject)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
        }

        $this->assertAudience($claims, $connection->client_id);
        $this->assertTiming($claims);

        $nonce = $claims['nonce'] ?? null;
        if (! is_string($nonce)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
        }
        if (! hash_equals($expectedNonceFingerprint, AttemptFingerprint::of(FingerprintPurpose::Nonce, $nonce))) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenNonceMismatch);
        }

        $email = $claims['email'] ?? null;
        $name = $claims['name'] ?? null;

        return VerifiedIdTokenClaims::of(
            issuer: $issuer,
            subject: $subject,
            claimedEmail: is_string($email) && $email !== '' ? $email : null,
            name: is_string($name) && $name !== '' ? $name : null,
            maxSubjectLength: Config::integer('enterprise-sso.id_token.max_subject_length'),
        );
    }

    /**
     * audience の 3 条 (★論理和で書かず 3 条に分ける)。
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    private function assertAudience(array $claims, string $clientId): void
    {
        $audience = $claims['aud'] ?? null;

        if (is_string($audience)) {
            $audiences = [$audience];
        } elseif (is_array($audience)) {
            $audiences = [];
            foreach ($audience as $entry) {
                if (! is_string($entry)) {
                    throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
                }
                $audiences[] = $entry;
            }
        } else {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
        }

        // (1) `aud` は必ず client_id を含む
        if (! in_array($clientId, $audiences, true)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAudienceMismatch);
        }

        $authorizedParty = $claims['azp'] ?? null;

        // (2) `aud` が複数なら `azp` は必須
        if (count($audiences) > 1 && $authorizedParty === null) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAudienceMismatch);
        }

        // (3) `azp` が存在するなら文字列で client_id と一致
        if ($authorizedParty !== null) {
            if (! is_string($authorizedParty)) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
            }
            if (! hash_equals($clientId, $authorizedParty)) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAudienceMismatch);
            }
        }
    }

    /**
     * 時刻の claim (**`exp` と `iat` は欠落そのものを拒否する**)。
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    private function assertTiming(array $claims): void
    {
        $leeway = Config::integer('enterprise-sso.id_token.leeway_seconds');
        $now = time();

        $expiresAt = $claims['exp'] ?? null;
        if (! is_int($expiresAt)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
        }
        if ($expiresAt + $leeway < $now) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenExpired);
        }

        $issuedAt = $claims['iat'] ?? null;
        if (! is_int($issuedAt)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
        }
        if ($issuedAt - $leeway > $now) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenIssuedInFuture);
        }

        // `nbf` は optional。在るときだけ見る。
        if (array_key_exists('nbf', $claims)) {
            $notBefore = $claims['nbf'];
            if (! is_int($notBefore)) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
            }
            if ($notBefore - $leeway > $now) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenNotYetValid);
            }
        }
    }
}
