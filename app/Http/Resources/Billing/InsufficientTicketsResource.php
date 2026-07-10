<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Exceptions\Billing\InsufficientTicketsException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * チケット残高不足の 402 ボディ ({ code, message })。XHR (analyze 等) 専用契約。
 * code 厳格一致でクライアントが自分宛て応答のみ処理する (analysis_conflict と同方式)。
 * TS 側 types/manual.ts の InsufficientTicketsBody と対で保守する。
 *
 * @property-read InsufficientTicketsException $resource
 */
final class InsufficientTicketsResource extends JsonResource
{
    /** 402 契約の判別子 */
    public const string CODE = 'insufficient_tickets';

    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'insufficient_tickets', message: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => self::CODE,
            'message' => $this->resource->getMessage(),
        ];
    }
}
