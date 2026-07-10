<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\RecentAuthRequiredDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * recent-auth 鮮度切れ時の XHR / Inertia mutation 向け 409 ボディ
 * ({ code, message, redirect })。
 *
 * `response()->json()` 直接使用を避けるための JsonResource。no-store ヘッダは
 * middleware 側で付与する。`data` ラップはしない (top-level)。
 *
 * @property-read RecentAuthRequiredDto $resource
 */
final class RecentAuthRequiredResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'recent_auth_required', message: string, redirect: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => RecentAuthRequiredDto::CODE,
            'message' => $this->resource->message,
            'redirect' => $this->resource->redirect,
        ];
    }
}
