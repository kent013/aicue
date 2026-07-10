<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\LlmCallLogData;
use App\Models\LlmCallLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Webmozart\Assert\Assert;

/**
 * LLM コスト記録の書き込み単一窓口。
 *
 * 成功系 (RecordLlmCallCost) / 失敗系 (RecordLlmCallFailure) の両 listener が
 * この write() を使う。価格解決は upstream (kent013/laravel-prism-prompt の
 * LlmPricingService) 済みで、本サービスは FX 換算・metadata 欠落検知・
 * failure_reason のマスキング・冪等 persist のみを担う。
 */
final readonly class LlmCallLogWriter
{
    public function __construct(
        private FxRateService $fx,
    ) {}

    public function write(LlmCallLogData $data): LlmCallLog
    {
        Assert::stringNotEmpty($data->executionId, 'executionId must be a non-empty string');

        $fxSnapshot = $this->fx->resolve();
        $totalCostJpy = ($fxSnapshot !== null && $data->totalCostUsd !== null)
            ? round($data->totalCostUsd * $fxSnapshot->rate, 2)
            : null;

        // missing 判定は (organization_id, subject_type, subject_id) 三点セット欠落。
        // user_id は console コマンド等 actor 不在の呼び出しがあるため判定に含めない。
        $metadataMissing = $data->organizationId === null
            || $data->subjectType === null
            || $data->subjectId === null;

        if ($metadataMissing) {
            Log::warning('LlmCallLog metadata missing', [
                'execution_id' => $data->executionId,
                'prompt_class' => $data->promptClass,
                'prompt_template' => $data->promptTemplate,
                'organization_id' => $data->organizationId,
                'subject_type' => $data->subjectType,
                'subject_id' => $data->subjectId,
            ]);
        }

        $finishReason = $data->finishReason instanceof FinishReason
            ? $data->finishReason->value
            : $data->finishReason;

        $failureReason = $data->failureReason !== null
            ? $this->sanitizeFailureReason($data->failureReason)
            : null;

        return LlmCallLog::recordWithOrganization(
            $data->organizationId,
            $data->userId,
            $data->executionId,
            [
                'subject_type' => $data->subjectType,
                'subject_id' => $data->subjectId,
                'prompt_class' => $data->promptClass,
                'prompt_template' => $data->promptTemplate,
                'provider' => $data->provider,
                'model' => $data->model,
                'finish_reason' => $finishReason,
                'step_count' => $data->stepCount,
                'input_tokens' => $data->inputTokens,
                'output_tokens' => $data->outputTokens,
                'cache_write_input_tokens' => $data->cacheWriteInputTokens,
                'cache_read_input_tokens' => $data->cacheReadInputTokens,
                'thought_tokens' => $data->thoughtTokens,
                'input_cost_usd' => $this->formatCost($data->inputCostUsd),
                'output_cost_usd' => $this->formatCost($data->outputCostUsd),
                'total_cost_usd' => $this->formatCost($data->totalCostUsd),
                'pricing_snapshot' => $data->pricingSnapshot?->toArray(),
                'fx_snapshot' => $fxSnapshot?->toArray(),
                'total_cost_jpy' => $totalCostJpy !== null
                    ? number_format($totalCostJpy, 2, '.', '')
                    : null,
                'duration_ms' => (int) round($data->durationMs),
                'request_id' => $data->requestId,
                'metadata_missing' => $metadataMissing,
                'failure_reason' => $failureReason,
                'created_at' => now(),
            ],
        );
    }

    /**
     * USD コストを DECIMAL(12,6) 文字列に整形する (half-up 丸め)。
     */
    private function formatCost(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 6, '.', '');
    }

    /**
     * 失敗メッセージから機密情報 (API キー / Bearer / JWT / 資格情報 URL /
     * email 等) をマスクし、500 文字に切り詰める。
     */
    private function sanitizeFailureReason(string $raw): string
    {
        $patterns = [
            '/sk-[a-zA-Z0-9_-]{20,}/',
            '/Bearer\s+[a-zA-Z0-9_\-\.=+\/]+/',
            '/Authorization["\']?\s*[:=]\s*["\']?[^\s"\']+/i',
            '/(password|api[_-]?key|secret|token|session[_-]?id)["\']?\s*[:=]\s*["\']?[^\s"\',}]+/i',
            '/eyJ[a-zA-Z0-9_-]+\.eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+/',
            '/[a-z]+:\/\/[^:\/\s]+:[^@\s]+@[^\s]+/i',
            '/[\w.+-]+@[\w-]+\.[\w.-]+/',
        ];

        $masked = $raw;
        foreach ($patterns as $pattern) {
            $result = preg_replace($pattern, '[REDACTED]', $masked);
            if (is_string($result)) {
                $masked = $result;
            }
        }

        // 末尾 '...' 込みで varchar(500) に収める (limit=500 だと 503 文字になり
        // pgsql で insert 自体が落ち、記録がまるごと失われる)
        return Str::limit($masked, 497, '...');
    }
}
