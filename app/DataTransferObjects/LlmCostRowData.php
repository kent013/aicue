<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * コストレポートの集計 1 行 (TOTAL 行も同じ型)。
 *
 * 金額は DECIMAL の SUM を **numeric-string** のまま持つ (float 化も丸め直しもしない)。
 * null は「upstream の pricing / FX 解決失敗」であって 0 (unknown モデルの zero-cost
 * snapshot = 正常系) とは違う。潰さず、件数として別に返す (「安く見える」嘘をつかない)。
 */
final readonly class LlmCostRowData
{
    /**
     * @param  string  $key  集計キー (null 成分は '(none)'、複合は '#' 連結)
     * @param  int<0, max>  $calls
     * @param  int<0, max>  $inputTokens
     * @param  int<0, max>  $outputTokens
     * @param  numeric-string|null  $totalCostUsd  usdUnresolvedCalls を除いた合計
     * @param  numeric-string|null  $totalCostJpy  jpyUnresolvedCalls を除いた合計
     * @param  int<0, max>  $usdUnresolvedCalls  total_cost_usd IS NULL の件数
     * @param  int<0, max>  $jpyUnresolvedCalls  total_cost_jpy IS NULL の件数
     * @param  int<0, max>  $failedCalls  failure_reason IS NOT NULL の件数
     * @param  int<0, max>  $metadataMissingCalls  metadata_missing = true の件数 (帰属配線の健全性)
     */
    public function __construct(
        public string $key,
        public int $calls,
        public int $inputTokens,
        public int $outputTokens,
        public ?string $totalCostUsd,
        public ?string $totalCostJpy,
        public int $usdUnresolvedCalls,
        public int $jpyUnresolvedCalls,
        public int $failedCalls,
        public int $metadataMissingCalls,
    ) {}

    /**
     * @return array{
     *     key: string,
     *     calls: int,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_cost_usd: string|null,
     *     total_cost_jpy: string|null,
     *     usd_unresolved_calls: int,
     *     jpy_unresolved_calls: int,
     *     failed_calls: int,
     *     metadata_missing_calls: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'calls' => $this->calls,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_cost_usd' => $this->totalCostUsd,
            'total_cost_jpy' => $this->totalCostJpy,
            'usd_unresolved_calls' => $this->usdUnresolvedCalls,
            'jpy_unresolved_calls' => $this->jpyUnresolvedCalls,
            'failed_calls' => $this->failedCalls,
            'metadata_missing_calls' => $this->metadataMissingCalls,
        ];
    }
}
