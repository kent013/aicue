<?php

declare(strict_types=1);

namespace App\Listeners;

use App\DataTransferObjects\LlmCallLogData;
use App\Services\LlmCallLogWriter;
use App\Support\LlmMetadataExtractor;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Throwable;

/**
 * 成功系 listener。PromptExecutionCompleted 1 イベント = llm_call_logs 1 行。
 *
 * 失敗系は RecordLlmCallFailure が担う。記録の失敗で LLM 呼び出し本体を
 * 巻き込まないよう Throwable は catch して report に留める (best-effort)。
 */
final readonly class RecordLlmCallCost
{
    public function __construct(
        private LlmCallLogWriter $writer,
    ) {}

    public function handle(PromptExecutionCompleted $event): void
    {
        try {
            $data = $this->buildData($event);

            if ($data->hasCostResolutionFailure()) {
                Log::warning('llm_call_cost_resolution_failed', [
                    'execution_id' => $event->executionId,
                    'provider' => $event->provider,
                    'model' => $event->model,
                    'organization_id' => $data->organizationId,
                    'subject_type' => $data->subjectType,
                    'subject_id' => $data->subjectId,
                    'request_id' => $event->requestId,
                ]);
            }

            $this->writer->write($data);
        } catch (Throwable $e) {
            Log::error('Failed to record LLM call cost', [
                'execution_id' => $event->executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildData(PromptExecutionCompleted $event): LlmCallLogData
    {
        $cost = $event->cost;

        return new LlmCallLogData(
            executionId: $event->executionId,
            promptClass: $event->promptClass,
            promptTemplate: $event->promptTemplate,
            provider: $event->provider,
            model: $event->model,
            finishReason: $event->finishReason,
            stepCount: $event->stepCount,
            inputTokens: $event->totalUsage->promptTokens,
            outputTokens: $event->totalUsage->completionTokens,
            cacheWriteInputTokens: $event->totalUsage->cacheWriteInputTokens,
            cacheReadInputTokens: $event->totalUsage->cacheReadInputTokens,
            thoughtTokens: $event->totalUsage->thoughtTokens,
            durationMs: $event->durationMs,
            requestId: $event->requestId,
            // metadata から抽出する汎用キーは organization_id / user_id / subject_type / subject_id のみ。
            // アプリ固有キーの抽出はアプリ側で本 listener を拡張して行う。
            organizationId: LlmMetadataExtractor::extractInt($event->metadata, 'organization_id'),
            userId: LlmMetadataExtractor::extractInt($event->metadata, 'user_id'),
            subjectType: LlmMetadataExtractor::extractString($event->metadata, 'subject_type'),
            // subject_id は int 主キーと ULID (HasUlids 系) の双方を string(64) として受ける
            subjectId: LlmMetadataExtractor::extractIntOrString($event->metadata, 'subject_id'),
            failureReason: null,
            inputCostUsd: $cost?->inputCostUsd,
            outputCostUsd: $cost?->outputCostUsd,
            totalCostUsd: $cost?->totalCostUsd,
            pricingSnapshot: $cost?->snapshot,
        );
    }
}
