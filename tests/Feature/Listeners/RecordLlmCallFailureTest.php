<?php

declare(strict_types=1);

use App\Listeners\RecordLlmCallFailure;
use App\Models\LlmCallLog;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;

/**
 * @param  array<string, mixed>  $metadata
 */
function makeFailedEvent(
    Throwable $exception,
    array $metadata = [],
    string $executionId = 'exec-fail-1',
): PromptExecutionFailed {
    return new PromptExecutionFailed(
        executionId: $executionId,
        promptClass: 'App\\Prompts\\ExampleSummaryPrompt',
        promptTemplate: 'example-summary',
        provider: 'anthropic',
        model: 'claude-sonnet-4-5-20250929',
        durationMs: 88.4,
        exception: $exception,
        metadata: $metadata,
    );
}

beforeEach(function (): void {
    // FX の stray request を防ぐ (失敗系でも writer は FX 解決を試みる)
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

test('失敗イベントで finish_reason=failed・トークン 0・コスト null の 1 行が記録される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    app(RecordLlmCallFailure::class)->handle(makeFailedEvent(
        new RuntimeException('API timeout'),
        metadata: [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
        ],
    ));

    $log = LlmCallLog::query()->sole();
    expect($log->finish_reason)->toBe('failed');
    expect($log->step_count)->toBe(0);
    expect($log->input_tokens)->toBe(0);
    expect($log->output_tokens)->toBe(0);
    expect($log->input_cost_usd)->toBeNull();
    expect($log->total_cost_usd)->toBeNull();
    expect($log->request_id)->toBeNull();
    expect($log->duration_ms)->toBe(88);
    expect($log->organization_id)->toBe($organization->id);
    expect($log->user_id)->toBe($owner->id);
    expect($log->failure_reason)->toBe('RuntimeException: API timeout');
    expect($log->metadata_missing)->toBeFalse();
});

test('failure_reason の機密情報 (API キー / Bearer / email) がマスクされる', function (): void {
    app(RecordLlmCallFailure::class)->handle(makeFailedEvent(
        new RuntimeException(
            'auth failed for sk-abcdefghijklmnopqrstuvwxyz123456 with Bearer eyToken123 contact admin@example.com'
        ),
    ));

    $reason = LlmCallLog::query()->sole()->failure_reason;
    expect($reason)->not->toContain('sk-abcdefghijklmnopqrstuvwxyz123456');
    expect($reason)->not->toContain('Bearer eyToken123');
    expect($reason)->not->toContain('admin@example.com');
    expect($reason)->toContain('[REDACTED]');
});

test('failure_reason は 500 文字に切り詰められる', function (): void {
    app(RecordLlmCallFailure::class)->handle(makeFailedEvent(
        new RuntimeException(str_repeat('あ', 1000)),
    ));

    $reason = LlmCallLog::query()->sole()->failure_reason;
    expect($reason)->not->toBeNull();
    expect(mb_strlen((string) $reason))->toBeLessThanOrEqual(500);
    expect($reason)->toEndWith('...');
});

test('失敗 → 成功の同一 execution_id 再書込で 1 行のまま backfill される', function (): void {
    $listener = app(RecordLlmCallFailure::class);
    $listener->handle(makeFailedEvent(new RuntimeException('timeout'), executionId: 'exec-retry'));
    $listener->handle(makeFailedEvent(new RuntimeException('timeout again'), executionId: 'exec-retry'));

    expect(LlmCallLog::query()->count())->toBe(1);
    expect(LlmCallLog::query()->sole()->failure_reason)->toContain('timeout again');
});
