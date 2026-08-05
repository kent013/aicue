<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\RecentAuthProviderDto;
use App\DataTransferObjects\Auth\RecentAuthStatusDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * recent-auth status の XHR 応答
 * ({ recent, passwordSet, availableProviders[], passkeyAvailable, canSatisfy, confirmedAt })。
 * top-level (data ラップなし)、no-store は controller 側で付与。
 *
 * @property-read RecentAuthStatusDto $resource
 */
final class RecentAuthStatusResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{
     *     recent: bool,
     *     passwordSet: bool,
     *     availableProviders: list<array{provider: string, capability: string, reauthUrl: string}>,
     *     passkeyAvailable: bool,
     *     canSatisfy: bool,
     *     confirmedAt: int|null,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'recent' => $this->resource->recent,
            'passwordSet' => $this->resource->passwordSet,
            'availableProviders' => array_map(
                static fn (RecentAuthProviderDto $p): array => [
                    'provider' => $p->provider,
                    'capability' => $p->capability->value,
                    'reauthUrl' => $p->reauthUrl,
                ],
                $this->resource->availableProviders,
            ),
            'passkeyAvailable' => $this->resource->passkeyAvailable,
            'canSatisfy' => $this->resource->canSatisfy,
            'confirmedAt' => $this->resource->confirmedAt,
        ];
    }
}
