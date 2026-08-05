<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\LoginMethodRequiredDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ログイン手段保持 guard の拒否ボディ ({ code, message, settingsUrl })。
 *
 * `response()->json()` 直接使用を避けるための JsonResource (禁止事項 4)。
 * no-store ヘッダは middleware 側で付与する。`data` ラップはしない (top-level)。
 *
 * @property-read LoginMethodRequiredDto $resource
 */
final class LoginMethodRequiredResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'login_method_required', message: string, settingsUrl: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => LoginMethodRequiredDto::CODE,
            'message' => $this->resource->message,
            'settingsUrl' => $this->resource->settingsUrl,
        ];
    }
}
