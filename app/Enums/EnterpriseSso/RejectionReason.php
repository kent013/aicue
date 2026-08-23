<?php

declare(strict_types=1);

namespace App\Enums\EnterpriseSso;

use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;

/**
 * 企業 SSO の試行を拒否した理由。**機械可読な固定文字列だけ**である。
 *
 * ★{@see EnterpriseSsoAttemptRejectedException} は
 *   本 enum しか受け取らない。外部由来の文字列・vendor の例外・要求 body は
 *   例外の中へ入る道が**型で存在しない**。
 * ★利用者への応答は理由によらず**一様**である。区別は内部のログ (理由コードだけ) にしか出ない。
 */
enum RejectionReason: string
{
    // --- discovery ---
    case DiscoveryFetchFailed = 'discovery_fetch_failed';
    case DiscoveryNotJson = 'discovery_not_json';
    case DiscoveryNotObject = 'discovery_not_object';
    case DiscoveryIssuerMismatch = 'discovery_issuer_mismatch';
    case DiscoveryMissingField = 'discovery_missing_field';
    case DiscoveryInvalidEndpoint = 'discovery_invalid_endpoint';
    case DiscoveryNoSupportedAuthMethod = 'discovery_no_supported_auth_method';
    case DiscoveryNoSupportedSigningAlg = 'discovery_no_supported_signing_alg';
    case DiscoveryBodyTooLarge = 'discovery_body_too_large';

    // --- JWKS ---
    case JwksFetchFailed = 'jwks_fetch_failed';
    case JwksMalformed = 'jwks_malformed';
    case JwksKeyNotFound = 'jwks_key_not_found';
    case JwksDuplicateKeyId = 'jwks_duplicate_key_id';
    case JwksRefetchUnavailable = 'jwks_refetch_unavailable';

    // --- token 交換 ---
    case TokenExchangeFailed = 'token_exchange_failed';
    case TokenResponseMalformed = 'token_response_malformed';
    case TokenResponseMissingIdToken = 'token_response_missing_id_token';

    // --- ID トークン ---
    case IdTokenMalformed = 'id_token_malformed';
    case IdTokenAlgorithmNotAllowed = 'id_token_algorithm_not_allowed';
    case IdTokenKeyIdMissing = 'id_token_key_id_missing';
    case IdTokenSignatureInvalid = 'id_token_signature_invalid';
    case IdTokenClaimTypeInvalid = 'id_token_claim_type_invalid';
    case IdTokenIssuerMismatch = 'id_token_issuer_mismatch';
    case IdTokenAudienceMismatch = 'id_token_audience_mismatch';
    case IdTokenExpired = 'id_token_expired';
    case IdTokenNotYetValid = 'id_token_not_yet_valid';
    case IdTokenIssuedInFuture = 'id_token_issued_in_future';
    case IdTokenSubjectInvalid = 'id_token_subject_invalid';
    case IdTokenNonceMismatch = 'id_token_nonce_mismatch';

    // --- 試行・接続 ---
    case AttemptNotFound = 'attempt_not_found';
    case AttemptExpired = 'attempt_expired';
    case AttemptBindingMismatch = 'attempt_binding_mismatch';
    case AttemptBindingMissing = 'attempt_binding_missing';
    case ConnectionNotUsable = 'connection_not_usable';
    case ProviderReturnedError = 'provider_returned_error';
}
