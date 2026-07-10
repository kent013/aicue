<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\ApiErrorCode;

/**
 * 統一 API エラー payload ({error: {code, message, status, details?}}) の値オブジェクト。
 *
 * details は省略可能: null のとき ApiErrorResource はキー自体を出力しない
 * (単純なエラーをコンパクトに保つ)。validation 失敗時は details.errors に
 * フィールド別メッセージを入れる。
 */
class ApiError
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status,
        public readonly ?array $details = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function fromCode(
        ApiErrorCode $code,
        ?string $message = null,
        ?int $status = null,
        ?array $details = null,
    ): self {
        return new self(
            code: $code->value,
            message: $message ?? $code->defaultMessage(),
            status: $status ?? $code->defaultStatus(),
            details: $details,
        );
    }

    /** @return array{code: string, message: string, status: int, details?: array<string, mixed>} */
    public function toArray(): array
    {
        $payload = [
            'code' => $this->code,
            'message' => $this->message,
            'status' => $this->status,
        ];

        if ($this->details !== null) {
            $payload['details'] = $this->details;
        }

        return $payload;
    }
}
