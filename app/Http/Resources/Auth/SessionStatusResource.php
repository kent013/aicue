<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\SessionStatusDto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * セッション有効性プローブの XHR 応答 ({ authenticated, sessionEpochMatches })。
 *
 * top-level (data ラップなし) にするのは、クライアント guard が JSON shape を厳密判定
 * するため (RecentAuthStatusResource と同じ作法)。
 *
 * `no-store, private` は controller ではなく本 Resource (withResponse) で付ける:
 * 本 endpoint は **guest 応答も対象**であり、認証済み限定の baseline middleware
 * (NoStoreCacheHeadersForAuthenticatedPages) では guest 分を取りこぼすため。
 *
 * @property-read SessionStatusDto $resource
 */
final class SessionStatusResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{authenticated: bool, sessionEpochMatches: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'authenticated' => $this->resource->authenticated,
            'sessionEpochMatches' => $this->resource->sessionEpochMatches,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}
