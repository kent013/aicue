<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

use App\Enums\ProviderCapability;

/**
 * recent-auth status が返す、再SSO 候補プロバイダ 1 件分。
 */
final readonly class RecentAuthProviderDto
{
    public function __construct(
        public string $provider,
        public ProviderCapability $capability,
        public string $reauthUrl,
    ) {}
}
