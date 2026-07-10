<?php

declare(strict_types=1);

use App\Listeners\RecordLlmCallCost;
use App\Models\LlmCallLog;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Pricing\CostCalculation;
use Kent013\PrismPrompt\Pricing\PricingSnapshot;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * @param  array<string, mixed>  $metadata
 */
function makeCompletedEvent(
    array $metadata = [],
    ?CostCalculation $cost = null,
    string $executionId = 'exec-1',
): PromptExecutionCompleted {
    $usage = new Usage(120, 45, cacheWriteInputTokens: 10, cacheReadInputTokens: 20, thoughtTokens: 5);
    $response = new TextResponse(
        steps: new Collection,
        text: 'ok',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: $usage,
        meta: new Meta(id: 'res-1', model: 'claude-sonnet-4-5-20250929'),
        messages: new Collection,
    );

    return new PromptExecutionCompleted(
        executionId: $executionId,
        promptClass: 'App\\Prompts\\ExampleSummaryPrompt',
        promptTemplate: 'example-summary',
        provider: 'anthropic',
        model: 'claude-sonnet-4-5-20250929',
        finishReason: FinishReason::Stop,
        stepCount: 1,
        totalUsage: $usage,
        durationMs: 321.9,
        requestId: 'req-1',
        response: $response,
        metadata: $metadata,
        cost: $cost,
    );
}

function makeCostCalculation(): CostCalculation
{
    return new CostCalculation(
        inputCostUsd: 0.00036,
        outputCostUsd: 0.000675,
        cacheWriteCostUsd: null,
        cacheReadCostUsd: null,
        totalCostUsd: 0.001035,
        snapshot: new PricingSnapshot(
            inputPerMillion: 3.0,
            outputPerMillion: 15.0,
            cacheWritePerMillion: null,
            cacheReadPerMillion: null,
            unit: 'per_1m_tokens',
            currency: 'USD',
            source: 'config',
        ),
    );
}

function fakeFrankfurter(float $rate = 150.0): void
{
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => $rate]])]);
}

test('成功イベントでコスト・FX 換算込みの 1 行が記録される', function (): void {
    fakeFrankfurter(150.0);
    [$organization, $owner] = createOrganizationWithOwner();

    app(RecordLlmCallCost::class)->handle(makeCompletedEvent(
        metadata: [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
        ],
        cost: makeCostCalculation(),
    ));

    $log = LlmCallLog::query()->sole();
    expect($log->execution_id)->toBe('exec-1');
    expect($log->finish_reason)->toBe('stop');
    expect($log->step_count)->toBe(1);
    expect($log->input_tokens)->toBe(120);
    expect($log->output_tokens)->toBe(45);
    expect($log->cache_write_input_tokens)->toBe(10);
    expect($log->cache_read_input_tokens)->toBe(20);
    expect($log->thought_tokens)->toBe(5);
    expect($log->input_cost_usd)->toBe('0.000360');
    expect($log->output_cost_usd)->toBe('0.000675');
    expect($log->total_cost_usd)->toBe('0.001035');
    expect($log->pricing_snapshot)->toMatchArray(['input' => 3.0, 'output' => 15.0]);
    expect($log->fx_snapshot)->toMatchArray(['rate' => 150.0, 'pair' => 'USDJPY', 'source' => 'frankfurter']);
    expect($log->total_cost_jpy)->toBe('0.16'); // 0.001035 * 150 = 0.15525 → round 2 桁
    expect($log->duration_ms)->toBe(322);
    expect($log->request_id)->toBe('req-1');
    expect($log->organization_id)->toBe($organization->id);
    expect($log->user_id)->toBe($owner->id);
    expect($log->subject_type)->toBe(Organization::class);
    expect($log->subject_id)->toBe((string) $organization->id);
    expect($log->metadata_missing)->toBeFalse();
    expect($log->failure_reason)->toBeNull();
});

test('metadata 欠落時は metadata_missing=true で記録される', function (): void {
    fakeFrankfurter();

    app(RecordLlmCallCost::class)->handle(makeCompletedEvent(cost: makeCostCalculation()));

    $log = LlmCallLog::query()->sole();
    expect($log->metadata_missing)->toBeTrue();
    expect($log->organization_id)->toBeNull();
    expect($log->user_id)->toBeNull();
    expect($log->subject_type)->toBeNull();
    expect($log->subject_id)->toBeNull();
});

test('cost=null (pricing 解決失敗) でもコスト列 null で記録される', function (): void {
    fakeFrankfurter();

    app(RecordLlmCallCost::class)->handle(makeCompletedEvent(cost: null));

    $log = LlmCallLog::query()->sole();
    expect($log->input_cost_usd)->toBeNull();
    expect($log->output_cost_usd)->toBeNull();
    expect($log->total_cost_usd)->toBeNull();
    expect($log->pricing_snapshot)->toBeNull();
    expect($log->total_cost_jpy)->toBeNull();
});

test('FX 取得失敗時は total_cost_jpy=null で記録される (throw しない)', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);

    app(RecordLlmCallCost::class)->handle(makeCompletedEvent(cost: makeCostCalculation()));

    $log = LlmCallLog::query()->sole();
    expect($log->fx_snapshot)->toBeNull();
    expect($log->total_cost_jpy)->toBeNull();
    expect($log->total_cost_usd)->toBe('0.001035');
});

test('同一 execution_id の再送は 1 行に収束する (冪等 upsert)', function (): void {
    fakeFrankfurter();

    $listener = app(RecordLlmCallCost::class);
    $listener->handle(makeCompletedEvent(cost: null, executionId: 'exec-dup'));
    $listener->handle(makeCompletedEvent(cost: makeCostCalculation(), executionId: 'exec-dup'));

    expect(LlmCallLog::query()->count())->toBe(1);
    // last-write-wins backfill: 2 回目のコストが反映される
    expect(LlmCallLog::query()->sole()->total_cost_usd)->toBe('0.001035');
});

test('記録の失敗は握り潰され例外が listener の外に漏れない', function (): void {
    fakeFrankfurter();

    // execution_id 空文字は writer の Assert で例外化するが、listener が catch する
    $usage = new Usage(1, 1);
    $event = new PromptExecutionCompleted(
        executionId: '',
        promptClass: 'App\\Prompts\\ExampleSummaryPrompt',
        promptTemplate: null,
        provider: 'anthropic',
        model: 'claude-sonnet-4-5-20250929',
        finishReason: FinishReason::Stop,
        stepCount: 1,
        totalUsage: $usage,
        durationMs: 1.0,
        requestId: null,
        response: new TextResponse(
            steps: new Collection,
            text: 'ok',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: $usage,
            meta: new Meta(id: 'res-x', model: 'claude-sonnet-4-5-20250929'),
            messages: new Collection,
        ),
        metadata: [],
        cost: null,
    );

    app(RecordLlmCallCost::class)->handle($event);

    expect(LlmCallLog::query()->count())->toBe(0);
});
