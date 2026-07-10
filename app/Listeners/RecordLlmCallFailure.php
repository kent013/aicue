<?php

declare(strict_types=1);

namespace App\Listeners;

use App\DataTransferObjects\LlmCallLogData;
use App\Services\LlmCallLogWriter;
use App\Support\LlmMetadataExtractor;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;
use Throwable;

/**
 * 失敗系 listener。finish_reason='failed'・トークン 0・コスト null・
 * サニタイズ済み failure_reason で llm_call_logs に 1 行記録する。
 * 例外でも provider 側で課金が発生している可能性があるため (partial generation 等)、
 * この行はコスト予測に有用。
 */
final readonly class RecordLlmCallFailure
{
    public function __construct(
        private LlmCallLogWriter $writer,
    ) {}

    public function handle(PromptExecutionFailed $event): void
    {
        try {
            $this->writer->write(new LlmCallLogData(
                executionId: $event->executionId,
                promptClass: $event->promptClass,
                promptTemplate: $event->promptTemplate,
                provider: $event->provider,
                model: $event->model,
                finishReason: 'failed',
                stepCount: 0,
                inputTokens: 0,
                outputTokens: 0,
                cacheWriteInputTokens: null,
                cacheReadInputTokens: null,
                thoughtTokens: null,
                durationMs: $event->durationMs,
                requestId: null,
                organizationId: LlmMetadataExtractor::extractInt($event->metadata, 'organization_id'),
                userId: LlmMetadataExtractor::extractInt($event->metadata, 'user_id'),
                subjectType: LlmMetadataExtractor::extractString($event->metadata, 'subject_type'),
                subjectId: LlmMetadataExtractor::extractIntOrString($event->metadata, 'subject_id'),
                failureReason: sprintf(
                    '%s: %s',
                    $event->exception::class,
                    $event->exception->getMessage()
                ),
            ));
        } catch (Throwable $e) {
            // original_error は生メッセージに機密情報が残り得るため exception class のみ記録する
            // (message は llm_call_logs.failure_reason にサニタイズ済みで書き込まれる)。
            Log::error('Failed to record LLM call failure', [
                'execution_id' => $event->executionId,
                'original_error_class' => $event->exception::class,
                'record_error' => $e->getMessage(),
            ]);
        }
    }
}
