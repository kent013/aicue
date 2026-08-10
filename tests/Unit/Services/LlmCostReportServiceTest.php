<?php

declare(strict_types=1);

use App\DataTransferObjects\LlmCostReportData;
use App\DataTransferObjects\LlmCostRowData;
use App\Enums\LlmCostGroupBy;
use App\Models\LlmCallLog;
use App\Models\Organization;
use App\Models\VideoManual;
use App\Services\LlmCostReportService;
use Carbon\CarbonImmutable;

/*
 * llm_call_logs の薄型集計 (施策 2)。**実 LLM を呼ばない** (factory でデータを作る)。
 *
 * 集計層が知ってよいのは llm_call_logs の列だけであり、アプリのドメイン語彙は持ち込まない
 * (他リポジトリへそのまま移植できる状態を保つ)。
 */

function llmCostReportService(): LlmCostReportService
{
    return app(LlmCostReportService::class);
}

/** @return array<string, LlmCostRowData> key => 行 */
function rowsByKey(LlmCostReportData $report): array
{
    $indexed = [];
    foreach ($report->rows as $row) {
        $indexed[$row->key] = $row;
    }

    return $indexed;
}

it('prompt_template 軸で行が分かれる', function (): void {
    LlmCallLog::factory()->count(2)->create(['prompt_template' => 'sop-extract']);
    LlmCallLog::factory()->create(['prompt_template' => 'scenario-generation']);

    $report = llmCostReportService()->report(LlmCostGroupBy::PromptTemplate);
    $rows = rowsByKey($report);

    expect($rows)->toHaveKeys(['sop-extract', 'scenario-generation'])
        ->and($rows['sop-extract']->calls)->toBe(2)
        ->and($rows['scenario-generation']->calls)->toBe(1);
});

it('model 軸で行が分かれる', function (): void {
    LlmCallLog::factory()->create(['model' => 'claude-sonnet-4-5-20250929']);
    LlmCallLog::factory()->count(3)->create(['model' => 'claude-haiku-4-5']);

    $rows = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::Model));

    expect($rows['claude-sonnet-4-5-20250929']->calls)->toBe(1)
        ->and($rows['claude-haiku-4-5']->calls)->toBe(3);
});

it('organization 軸で行が分かれ、組織なしは (none) に正規化される', function (): void {
    $organization = Organization::factory()->create();
    LlmCallLog::factory()->count(2)->create(['organization_id' => $organization->id]);
    LlmCallLog::factory()->metadataMissing()->create();

    $rows = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::Organization));

    expect($rows[(string) $organization->id]->calls)->toBe(2)
        ->and($rows['(none)']->calls)->toBe(1);
});

it('subject 軸のキーは subject_type と subject_id の複合になる', function (): void {
    $manual = VideoManual::factory()->create();
    $other = VideoManual::factory()->create();
    LlmCallLog::factory()->count(2)->create([
        'subject_type' => $manual->getMorphClass(),
        'subject_id' => (string) $manual->id,
    ]);
    LlmCallLog::factory()->create([
        'subject_type' => $other->getMorphClass(),
        'subject_id' => (string) $other->id,
    ]);

    $rows = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::Subject));

    expect($rows[$manual->getMorphClass().'#'.$manual->id]->calls)->toBe(2)
        ->and($rows[$other->getMorphClass().'#'.$other->id]->calls)->toBe(1);
});

it('期間は半開区間で since ちょうどを含み until ちょうどを含まない', function (): void {
    $since = CarbonImmutable::parse('2026-08-01 00:00:00');
    $until = CarbonImmutable::parse('2026-08-10 00:00:00');

    LlmCallLog::factory()->create(['created_at' => $since, 'prompt_template' => 'on-since']);
    LlmCallLog::factory()->create(['created_at' => $until, 'prompt_template' => 'on-until']);
    LlmCallLog::factory()->create([
        'created_at' => $since->subSecond(),
        'prompt_template' => 'before-since',
    ]);

    $rows = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate, $since, $until));

    expect($rows)->toHaveKey('on-since')
        ->and($rows)->not->toHaveKey('on-until')
        ->and($rows)->not->toHaveKey('before-since');
});

it('total_cost_usd が null の行は 0 に潰さず usdUnresolvedCalls に数える', function (): void {
    LlmCallLog::factory()->create([
        'prompt_template' => 'mix',
        'total_cost_usd' => '1.000000',
    ]);
    LlmCallLog::factory()->create([
        'prompt_template' => 'mix',
        'total_cost_usd' => null,
    ]);

    $row = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate))['mix'];

    expect($row->calls)->toBe(2)
        ->and($row->usdUnresolvedCalls)->toBe(1)
        ->and((float) $row->totalCostUsd)->toBe(1.0);
});

it('total_cost_jpy が null の行は jpyUnresolvedCalls に数える', function (): void {
    LlmCallLog::factory()->withFxSnapshot()->create(['prompt_template' => 'jpy']);
    LlmCallLog::factory()->create(['prompt_template' => 'jpy']); // fx_snapshot なし = JPY 未解決

    $row = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate))['jpy'];

    expect($row->jpyUnresolvedCalls)->toBe(1)
        ->and($row->totalCostJpy)->not->toBeNull();
});

it('failure_reason を持つ行を failedCalls に数える', function (): void {
    LlmCallLog::factory()->failed()->count(2)->create(['prompt_template' => 'fail']);
    LlmCallLog::factory()->create(['prompt_template' => 'fail']);

    $row = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate))['fail'];

    expect($row->calls)->toBe(3)->and($row->failedCalls)->toBe(2);
});

it('metadata_missing の行を metadataMissingCalls に数える', function (): void {
    LlmCallLog::factory()->metadataMissing()->create(['prompt_template' => 'meta']);
    LlmCallLog::factory()->create(['prompt_template' => 'meta']);

    $row = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate))['meta'];

    expect($row->metadataMissingCalls)->toBe(1);
});

it('afterId は境界より大きい id の行だけを対象にする', function (): void {
    $older = LlmCallLog::factory()->create(['prompt_template' => 'older']);
    LlmCallLog::factory()->create(['prompt_template' => 'newer']);

    $report = llmCostReportService()->report(
        LlmCostGroupBy::PromptTemplate,
        afterId: $older->id,
    );

    expect(rowsByKey($report))->toHaveKey('newer')
        ->and(rowsByKey($report))->not->toHaveKey('older')
        ->and($report->afterId)->toBe($older->id);
});

it('TOTAL 行が各行の単純合計と一致する (別クエリで取っている)', function (): void {
    LlmCallLog::factory()->count(2)->create(['prompt_template' => 'a']);
    LlmCallLog::factory()->count(3)->create(['prompt_template' => 'b']);

    $report = llmCostReportService()->report(LlmCostGroupBy::PromptTemplate);

    $calls = array_sum(array_map(fn ($row): int => $row->calls, $report->rows));
    $inputTokens = array_sum(array_map(fn ($row): int => $row->inputTokens, $report->rows));
    $outputTokens = array_sum(array_map(fn ($row): int => $row->outputTokens, $report->rows));
    $usd = array_sum(array_map(fn ($row): float => (float) $row->totalCostUsd, $report->rows));

    expect($report->total->key)->toBe('TOTAL')
        ->and($report->total->calls)->toBe($calls)
        ->and($report->total->inputTokens)->toBe($inputTokens)
        ->and($report->total->outputTokens)->toBe($outputTokens)
        ->and(round((float) $report->total->totalCostUsd, 6))->toBe(round($usd, 6));
});

it('対象 0 件でも TOTAL は 1 行返り、整数列は 0 / 金額列は null になる', function (): void {
    // COALESCE を整数列から外すと SUM() の NULL が int 引数へ流れて TypeError になる回帰
    $report = llmCostReportService()->report(LlmCostGroupBy::PromptTemplate);

    expect($report->rows)->toBe([])
        ->and($report->total->calls)->toBe(0)
        ->and($report->total->inputTokens)->toBe(0)
        ->and($report->total->outputTokens)->toBe(0)
        ->and($report->total->usdUnresolvedCalls)->toBe(0)
        ->and($report->total->jpyUnresolvedCalls)->toBe(0)
        ->and($report->total->failedCalls)->toBe(0)
        ->and($report->total->metadataMissingCalls)->toBe(0)
        ->and($report->total->totalCostUsd)->toBeNull()
        ->and($report->total->totalCostJpy)->toBeNull();
});

it('toArray() が集計軸と期間と行を機械可読な形で返す', function (): void {
    $since = CarbonImmutable::parse('2026-08-01 00:00:00');
    $until = CarbonImmutable::parse('2026-08-11 00:00:00');
    LlmCallLog::factory()->create(['prompt_template' => 'x', 'created_at' => $since]);

    $array = llmCostReportService()->report(LlmCostGroupBy::PromptTemplate, $since, $until)->toArray();

    expect($array['group_by'])->toBe('prompt_template')
        ->and($array['since'])->toBe($since->toIso8601String())
        ->and($array['until'])->toBe($until->toIso8601String())
        ->and($array['after_id'])->toBeNull()
        ->and($array['rows'][0])->toHaveKeys([
            'key', 'calls', 'input_tokens', 'output_tokens',
            'total_cost_usd', 'total_cost_jpy',
            'usd_unresolved_calls', 'jpy_unresolved_calls',
            'failed_calls', 'metadata_missing_calls',
        ])
        ->and($array['total']['key'])->toBe('TOTAL');
});
