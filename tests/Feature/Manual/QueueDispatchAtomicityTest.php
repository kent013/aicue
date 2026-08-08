<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Jobs\Manual\RunManualAnalysis;
use App\Jobs\Manual\RunManualRender;
use App\Models\AnalysisJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\SourceDocument;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\RenderJobService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| キュー投入の原子性 (Manual 経路。AG-114 確定 1)
|--------------------------------------------------------------------------
|
| **主契約は tx level 観測**である。action 直前の DB::transactionLevel() を baseline とし、
| 対象ジョブで filter した JobQueueing の level が baseline + 1 以上であることを見る。
|
| ★ rollback テストは**補助**である。旧実装 (service 内 tx の commit 後に dispatch) でも
|   テストが外側 tx で包めば jobs 行は rollback で消えるため、移設の検出には使えない。
| ★ `Queue::fake()` は使わない (QueueFake::push は enqueueUsing を通らず原子性を観測できない)。
| ★ 観測の前提: 対象ジョブの **pin 先接続** が after_commit=false であること
|   (`queue.default='database'` は onConnection で pin されたジョブには効かない)。
*/

/**
 * 解析トリガ可能な manual 一式 (draft + SOP + 残高)。
 *
 * @return array{Project, VideoManual}
 */
function atomicityAnalyzableManual(): array
{
    Storage::fake();
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => VideoManualStatus::Draft->value]);
    SourceDocument::factory()->forManual($manual)->create();
    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');

    return [$project, $manual];
}

/**
 * レンダトリガ可能な manual 一式 (ready + cut + 採用テイク + 残高)。
 *
 * @return array{Project, VideoManual}
 */
function atomicityRenderableManual(): array
{
    Storage::fake();
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => VideoManualStatus::Ready->value]);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();
    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');

    return [$project, $manual];
}

test('解析トリガの RunManualAnalysis は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database-analysis.after_commit'))->toBeFalse();

    [$project, $manual] = atomicityAnalyzableManual();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(AnalysisJobService::class)->trigger($project, $manual),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), RunManualAnalysis::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('レンダトリガの RunManualRender は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database-render.after_commit'))->toBeFalse();

    [$project, $manual] = atomicityRenderableManual();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(RenderJobService::class)->trigger($project, $manual),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), RunManualRender::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('プレビュートリガの RunManualRender は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database-render.after_commit'))->toBeFalse();

    [$project, $manual] = atomicityRenderableManual();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(RenderJobService::class)->triggerPreview($project, $manual),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), RunManualRender::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('外側 tx が rollback すると analysis_jobs も jobs 行も残らない', function (): void {
    config()->set('queue.default', 'database');
    [$project, $manual] = atomicityAnalyzableManual();
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($project, $manual): void {
            app(AnalysisJobService::class)->trigger($project, $manual);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(AnalysisJob::query()->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe($jobsBefore);
});

test('外側 tx が rollback すると render_jobs も jobs 行も残らない', function (): void {
    config()->set('queue.default', 'database');
    [$project, $manual] = atomicityRenderableManual();
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($project, $manual): void {
            app(RenderJobService::class)->trigger($project, $manual);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(RenderJob::query()->where('status', JobStatus::Queued->value)->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe($jobsBefore);
});
