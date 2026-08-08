<?php

declare(strict_types=1);

use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Manual\VideoManualService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| キュー投入の原子性 (マニュアル削除経路。AG-114 確定 1)
|--------------------------------------------------------------------------
|
| 主契約は tx level 観測 (baseline + 1 以上)。rollback テストは補助である。
*/

/**
 * 削除対象マニュアル一式 (take と source document の S3 キーを持つ)。
 *
 * @return array{Project, VideoManual}
 */
function manualDeletionContext(): array
{
    Storage::fake();
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    Take::factory()->forCut($cut)->create();
    SourceDocument::factory()->forManual($manual)->create();

    return [$project, $manual];
}

test('マニュアル削除の DeleteTakeObjectsJob は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database-media.after_commit'))->toBeFalse();

    [$project, $manual] = manualDeletionContext();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(VideoManualService::class)->delete($project, $manual),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), DeleteTakeObjectsJob::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('マニュアル削除の外側 tx が rollback すると manual 行も削除 job も残らない', function (): void {
    config()->set('queue.default', 'database');
    [$project, $manual] = manualDeletionContext();
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($project, $manual): void {
            app(VideoManualService::class)->delete($project, $manual);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(VideoManual::query()->whereKey($manual->id)->exists())->toBeTrue();
    expect(DB::table('jobs')->count())->toBe($jobsBefore);
});
