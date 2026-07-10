<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\TwoFactorRequiredDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 組織 2FA 必須ゲートの XHR 向け 409 ボディ ({ code, message, redirect })。
 *
 * `response()->json()` 直接使用を避けるための JsonResource。no-store ヘッダは
 * middleware 側で付与する。`data` ラップはしない (top-level)。
 *
 * @property-read TwoFactorRequiredDto $resource
 */
final class TwoFactorRequiredResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'two_factor_required', message: string, redirect: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => TwoFactorRequiredDto::CODE,
            'message' => $this->resource->message,
            'redirect' => $this->resource->redirect,
        ];
    }
}
