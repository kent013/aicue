<?php

declare(strict_types=1);

namespace App\DataTransferObjects\EnterpriseSso;

use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use JsonException;
use SensitiveParameter;

/**
 * token endpoint の応答のうち**本アプリが使う要素だけ**。
 *
 * ★`access_token` / `refresh_token` は**持たない**。
 *   ログインの確定に使うのは ID トークンだけで、他のトークンは保存も利用もしない
 *   (外向きの経路と保管する秘密を増やさない)。
 */
final readonly class OidcTokenResponse
{
    private function __construct(#[SensitiveParameter] public string $idToken) {}

    /**
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public static function fromResponseBody(string $body): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenResponseMalformed);
        }

        if (! is_array($decoded)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenResponseMalformed);
        }

        $idToken = $decoded['id_token'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenResponseMissingIdToken);
        }

        return new self($idToken);
    }
}
