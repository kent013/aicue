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
        public bool $canSatisfy,
        // 契約: recent===true ⇒ confirmedAt は session の recent_auth_at (unix epoch 秒)。
        // recent===false (未設定 / stale) は一律 null で fail-closed に倒す。
        public ?int $confirmedAt = null,
    ) {}
}
