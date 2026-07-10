<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Kent013\PrismPrompt\Pricing\PricingSnapshot;
use Prism\Prism\Enums\FinishReason;

/**
 * LlmCallLogWriter::write() の入力 DTO。
 *
 * 成功系 listener (RecordLlmCallCost) と失敗系 listener (RecordLlmCallFailure) の
 * 両方が使う。コストは float で運び、DB 境界で DECIMAL(12,6) 文字列に整形する。
 *
 * PricingSnapshot は kent013/laravel-prism-prompt のベンダ型で、
 * pricing_snapshot JSON カラムとの Arrayable round-trip のためだけに保持する。
 * 他のアプリ層 DTO に伝播させないこと。
 */
final readonly class LlmCallLogData
{
    public function __construct(
        public string $executionId,
        public string $promptClass,
        public ?string $promptTemplate,
        public string $provider,
        public string $model,
        public FinishReason|string $finishReason,
        public int $stepCount,
        public int $inputTokens,
        public int $outputTokens,
        public ?int $cacheWriteInputTokens,
        public ?int $cacheReadInputTokens,
        public ?int $thoughtTokens,
        public float $durationMs,
        public ?string $requestId,
        public ?int $organizationId,
        public ?int $userId,
        public ?string $subjectType,
        /**
         * int (auto-increment) も ULID string (HasUlids 系) も保持できるよう string に統一。
         * カラムは string(64)。数値は listener が string キャスト済みで渡す。
         */
        public ?string $subjectId,
        public ?string $failureReason = null,
        public ?float $inputCostUsd = null,
        public ?float $outputCostUsd = null,
        public ?float $totalCostUsd = null,
        public ?PricingSnapshot $pricingSnapshot = null,
    ) {}

    /**
     * 'failed' は RecordLlmCallFailure が明示的に設定する string sentinel。
     * enum が将来 'failed' case を持った場合にも吸収できるよう、値ベースで比較する。
     */
    public function isFailure(): bool
    {
        $value = $this->finishReason instanceof FinishReason
            ? $this->finishReason->value
            : $this->finishReason;

        return $value === 'failed';
    }

    /**
     * totalCostUsd === null は upstream の pricing 解決失敗。
     * 0.0 は unknown モデルの zero-cost snapshot (正常系) なので区別する。
     */
    public function hasCostResolutionFailure(): bool
    {
        return $this->totalCostUsd === null;
    }
}
