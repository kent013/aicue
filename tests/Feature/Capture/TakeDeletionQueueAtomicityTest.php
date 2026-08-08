<?php

declare(strict_types=1);

use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Capture\CaptureTakeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| キュー投入の原子性 (テイク削除経路。AG-114 確定 1)
|--------------------------------------------------------------------------
|
| 主契約は tx level 観測 (baseline + 1 以上)。rollback テストは補助である。
| 保証するのは「take 行を消したのに削除 job が投入されない窓」の解消だけで、
| worker 停止 / job 失敗 / ストレージ失敗ではオブジェクトは残る (誇張しない)。
*/

/**
 * 削除対象テイク一式 (S3 パスを持つ take)。
 *
 * @return array{Project, VideoManual, Cut, Take}
 */
function takeDeletionContext(): array
{
    Storage::fake();
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();

    return [$project, $manual, $cut, $take];
}

test('テイク削除の DeleteTakeObjectsJob は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database-media.after_commit'))->toBeFalse();

    [$project, $manual, $cut, $take] = takeDeletionContext();
    expect($take->video_path)->not->toBeNull();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(CaptureTakeService::class)->delete($project, $manual, $cut, $take),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), DeleteTakeObjectsJob::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('テイク削除の外側 tx が rollback すると take 行も削除 job も残らない', function (): void {
    config()->set('queue.default', 'database');
    [$project, $manual, $cut, $take] = takeDeletionContext();
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($project, $manual, $cut, $take): void {
            app(CaptureTakeService::class)->delete($project, $manual, $cut, $take);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(Take::query()->whereKey($take->id)->exists())->toBeTrue();
    expect(DB::table('jobs')->count())->toBe($jobsBefore);
});
