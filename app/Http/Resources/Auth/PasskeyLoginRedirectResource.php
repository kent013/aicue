<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\PasskeyLoginRedirectDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * passkey ログイン成功の JSON ボディ ({ redirect })。
 *
 * `response()->json()` 直接使用を避けるための JsonResource (禁止事項 4)。
 * `data` ラップはしない (top-level)。
 *
 * @property-read PasskeyLoginRedirectDto $resource
 */
final class PasskeyLoginRedirectResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{redirect: string}
     */
    public function toArray(Request $request): array
    {
        return ['redirect' => $this->resource->redirect];
    }
}
