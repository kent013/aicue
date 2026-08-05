<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * recent-auth status (クライアント主導 step-up の precheck) の応答 DTO。
 *
 * 最終 gate は RequireRecentAuth middleware であり、本 DTO は UI 補助 (precheck) に
 * すぎない。鮮度判定 (`recent`) は middleware と同一の `RecentAuthWindow` を参照する。
 */
final readonly class RecentAuthStatusDto
{
    /**
     * @param  list<RecentAuthProviderDto>  $availableProviders  step-up satisfier 可能な再SSO 候補のみ
     */
    public function __construct(
        public bool $recent,
        public bool $passwordSet,
        public array $availableProviders,
        // パスキーで再認証できるか (登録済み credential が 1 件以上あるか)。
        // **ログイン可否 (PasskeyLoginPolicy) とは別**: TOTP 有効ユーザーは passkey で
        // ログインできないが、再認証 (POST /passkeys/confirm) には使える。
        public bool $passkeyAvailable,
        public bool $canSatisfy,
        // 契約: recent===true ⇒ confirmedAt は session の recent_auth_at (unix epoch 秒)。
        // recent===false (未設定 / stale) は一律 null で fail-closed に倒す。
        public ?int $confirmedAt = null,
    ) {}
}
