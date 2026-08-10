<?php

declare(strict_types=1);

use App\Models\LlmCallLog;
use App\Models\Organization;
use App\Models\VideoManual;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * 既定期間 (30 日前 <= created_at < 現在) に確実に入る記録時刻。
 *
 * `until` は排他かつ `created_at` は秒精度の timestamp 列なので、`now()` ちょうどに
 * 記録された行は既定期間に入らないことがある。既定オプションの検査ではここを使う。
 */
function llmCostReportRecordedAt(): CarbonImmutable
{
    return CarbonImmutable::now()->subMinutes(5);
}

/*
 * 期間集計コマンド (施策 3)。読み取り専用で、集計本体は LlmCostReportService が持つ
 * (1 実装・複数入口)。ここでは入口としての契約 (入力検証・終了コード・出力形) を固定する。
 *
 * 出力を読むため Artisan::call() / Artisan::output() を使う
 * (PendingCommand の mock 出力は table() 描画を素通しするため出力検査に使えない)。
 */

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string} [終了コード, 標準出力]
 */
function runLlmCostReport(array $parameters = []): array
{
    $exitCode = Artisan::call('operations:llm-cost-report', $parameters);

    return [$exitCode, Artisan::output()];
}

/** @return array<string, mixed> */
function runLlmCostReportJson(array $parameters = []): array
{
    [$exitCode, $output] = runLlmCostReport($parameters + ['--json' => true]);
    expect($exitCode)->toBe(Command::SUCCESS);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('既定オプションで表を出力し成功する', function (): void {
    LlmCallLog::factory()->create([
        'prompt_template' => 'sop-extract',
        'created_at' => llmCostReportRecordedAt(),
    ]);

    [$exitCode, $output] = runLlmCostReport();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('sop-extract')
        ->and($output)->toContain('TOTAL')
        ->and($output)->toContain('meta_missing');
});

it('--json は LlmCostReportData の shape をそのまま出す', function (): void {
    LlmCallLog::factory()->create([
        'prompt_template' => 'sop-extract',
        'created_at' => llmCostReportRecordedAt(),
    ]);

    $decoded = runLlmCostReportJson();

    expect($decoded)->toHaveKeys(['group_by', 'since', 'until', 'after_id', 'rows', 'total'])
        ->and($decoded['group_by'])->toBe('prompt_template')
        ->and($decoded['rows'][0]['key'])->toBe('sop-extract')
        ->and($decoded['rows'][0])->toHaveKeys([
            'key', 'calls', 'input_tokens', 'output_tokens',
            'total_cost_usd', 'total_cost_jpy',
            'usd_unresolved_calls', 'jpy_unresolved_calls',
            'failed_calls', 'metadata_missing_calls',
        ])
        ->and($decoded['total']['key'])->toBe('TOTAL');
});

it('--group-by=subject が動く', function (): void {
    $manual = VideoManual::factory()->create();
    LlmCallLog::factory()->create([
        'subject_type' => $manual->getMorphClass(),
        'subject_id' => (string) $manual->id,
        'created_at' => llmCostReportRecordedAt(),
    ]);

    $decoded = runLlmCostReportJson(['--group-by' => 'subject']);

    expect($decoded['rows'][0]['key'])->toBe($manual->getMorphClass().'#'.$manual->id);
});

it('--group-by=organization が動く', function (): void {
    $organization = Organization::factory()->create();
    LlmCallLog::factory()->create([
        'organization_id' => $organization->id,
        'created_at' => llmCostReportRecordedAt(),
    ]);

    $decoded = runLlmCostReportJson(['--group-by' => 'organization']);

    expect($decoded['rows'][0]['key'])->toBe((string) $organization->id);
});

it('不正な --group-by は終了コード 2', function (): void {
    [$exitCode] = runLlmCostReport(['--group-by' => 'manual']);

    expect($exitCode)->toBe(Command::INVALID);
});

it('parse 不能な --since は終了コード 2', function (): void {
    [$exitCode] = runLlmCostReport(['--since' => 'not-a-date']);

    expect($exitCode)->toBe(Command::INVALID);
});

it('桁溢れした --until は終了コード 2 (再フォーマット一致で厳格に弾く)', function (): void {
    [$exitCode] = runLlmCostReport(['--until' => '2026-13-45']);

    expect($exitCode)->toBe(Command::INVALID);
});

it('since >= until は終了コード 2', function (): void {
    [$exitCode] = runLlmCostReport(['--since' => '2026-08-10', '--until' => '2026-08-01']);

    expect($exitCode)->toBe(Command::INVALID);
});

it('日付のみの --until はその日を含む (排他境界を翌日 0 時にする)', function (): void {
    LlmCallLog::factory()->create([
        'prompt_template' => 'end-of-day',
        'created_at' => CarbonImmutable::parse('2026-08-10 23:59:59'),
    ]);

    $decoded = runLlmCostReportJson(['--since' => '2026-08-01', '--until' => '2026-08-10']);

    expect($decoded['until'])->toBe(CarbonImmutable::parse('2026-08-11 00:00:00')->toIso8601String())
        ->and($decoded['rows'][0]['key'])->toBe('end-of-day');
});

it('日時つきの --until はそのまま排他境界として使う', function (): void {
    $decoded = runLlmCostReportJson(['--since' => '2026-08-01', '--until' => '2026-08-10 12:00:00']);

    expect($decoded['until'])->toBe(CarbonImmutable::parse('2026-08-10 12:00:00')->toIso8601String());
});
