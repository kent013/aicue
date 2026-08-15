<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Recovery\RecoveryStream;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\AnalysisJobService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/*
 * 滞留回収の唯一の入口 (work:recover-stuck) の振る舞い。
 * 既定は実行しない (数えるだけ) / 引数の誤りは無制限実行に落とさない / 監視の語彙が消えない。
 */

/** 30 分超過した queued 解析ジョブを 1 件作る */
function stuckWorkAnalysisJob(): AnalysisJob
{
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);

    return AnalysisJob::factory()->forManual($manual)->create(['status' => 'queued']);
}

/**
 * 解析ジョブの回収が必ず例外になるよう仕込む
 * (registry と系列は本物のまま。ドメイン Service だけ差し替えて実配線を通す)。
 */
function makeAnalysisRecoveryThrow(int $candidateId): void
{
    $jobs = Mockery::mock(AnalysisJobService::class);
    $jobs->shouldReceive('staleJobIds')
        ->andReturnUsing(static fn (CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array => $afterId === null ? [$candidateId] : []);
    $jobs->shouldReceive('failStaleJob')->andThrow(new RuntimeException('回収に失敗した'));
    app()->instance(AnalysisJobService::class, $jobs);
}

test('--apply 無しでは DB が 1 バイトも変わらない (候補だけ数える)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    $job = stuckWorkAnalysisJob();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $exitCode = Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job']);

    expect($exitCode)->toBe(Command::SUCCESS);
    expect(Artisan::output())
        ->toContain('analysis_job: mode=dry-run (candidates は回収件数の上界) candidates=1 recovered=0');

    expect($job->refresh()->status)->toBe(JobStatus::Queued);
});

test('--stream に未知の値を渡すと失敗し、有効な値の一覧が出る', function (): void {
    $exitCode = Artisan::call('work:recover-stuck', ['--stream' => 'nope', '--apply' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::FAILURE);
    expect($output)->toContain('--stream の値が不正です: nope');
    foreach (RecoveryStream::cases() as $stream) {
        expect($output)->toContain($stream->value);
    }
});

test('--limit の不正値は失敗する (無制限実行へ落とさない)', function (string $limit): void {
    $exitCode = Artisan::call('work:recover-stuck', [
        '--stream' => 'analysis_job',
        '--apply' => true,
        '--limit' => $limit,
    ]);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('--limit には 1 以上の整数を指定してください');
})->with(['0', '-1', 'abc', '1.5']);

test('--limit の未指定と有効値は区別され、どちらも成功する', function (): void {
    expect(Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job', '--apply' => true]))
        ->toBe(Command::SUCCESS);
    expect(Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job', '--apply' => true, '--limit' => '1']))
        ->toBe(Command::SUCCESS);
});

test('--stream 省略時は 5 系列すべての行が出力される', function (): void {
    expect(Artisan::call('work:recover-stuck'))->toBe(Command::SUCCESS);
    $output = Artisan::output();

    foreach (RecoveryStream::cases() as $stream) {
        expect($output)->toContain($stream->value.': mode=dry-run');
    }
});

test('出力に監視の語彙 5 つが必ず含まれる (黙って消えない)', function (): void {
    Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job']);
    $output = Artisan::output();

    foreach (['errors=', 'deferred=', 'escalated=', 'cleanup-failed=', 'limit-reached='] as $vocabulary) {
        expect($output)->toContain($vocabulary);
    }
});

test('1 件でも例外があれば終了コードは失敗になる (掃引自体は止まらない)', function (): void {
    makeAnalysisRecoveryThrow(candidateId: 1);

    $exitCode = Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job', '--apply' => true]);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('errors=1');
});
