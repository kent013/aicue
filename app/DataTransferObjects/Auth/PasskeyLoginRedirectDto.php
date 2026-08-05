<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * passkey ログイン成功時に client (fetch) へ返す着地 URL。
 *
 * client は受け取った URL へ `window.location.assign` する
 * (WebAuthn ceremony は fetch 完結のため Inertia のページ遷移とは無関係。
 *  transport 契約は詳細設計 施策 4-d)。
 */
final readonly class PasskeyLoginRedirectDto
{
    public function __construct(
        public string $redirect,
    ) {}
}
