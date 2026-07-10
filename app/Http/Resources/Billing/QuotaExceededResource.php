<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Exceptions\Billing\QuotaExceededException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quota 上限超過の 422 ボディ ({ code, message })。XHR (撮影 PWA の upload-url 等) 専用契約。
 * code 厳格一致でクライアントが自分宛て応答のみ処理する (insufficient_tickets と同方式)。
 * TS 側 types/capture.ts の QuotaExceededBody と対で保守する。
 *
 * @property-read QuotaExceededException $resource
 */
final class QuotaExceededResource extends JsonResource
{
    /** 422 契約の判別子 */
    public const string CODE = 'quota_exceeded';

    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'quota_exceeded', message: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => self::CODE,
            'message' => $this->resource->getMessage(),
        ];
    }
}
