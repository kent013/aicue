<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\TwoFactorDisableForbiddenDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 2FA 必須組織メンバーの self-disable 拒否の XHR 向け 422 ボディ ({ code, message })。
 *
 * `response()->json()` 直接使用を避けるための JsonResource。`data` ラップはしない (top-level)。
 *
 * @property-read TwoFactorDisableForbiddenDto $resource
 */
final class TwoFactorDisableForbiddenResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'two_factor_disable_forbidden', message: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => TwoFactorDisableForbiddenDto::CODE,
            'message' => $this->resource->message,
        ];
    }
}
