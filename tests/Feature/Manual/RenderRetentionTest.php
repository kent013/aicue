<?php

declare(strict_types=1);

use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\RenderJobService;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * 出力保持ポリシー「非同期で最新 succeeded 1 世代へ収束」(概念設計 §5):
 * - DeleteRenderOutputsJob: 世代交代済みのみ削除 + CAS NULL 化。最新 / NULL / prefix 不一致は no-op
 * - S3 削除中に DB トランザクション/行ロックを保持しない (3 段構成)
 * - reconcileOutputs: 取り残し検出で削除 job を再投入 (dispatched/skipped 集計)
 */

/**
 * @return array{Organization, User, Project, VideoManual}
 */
function retentionContext(): array
{
    Storage::fake('s3');
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    return [$organization, $owner, $project, $manual];
}

/** manual 配下の succeeded render job + S3 実体を作る */
function retentionSucceededJob(VideoManual $manual, string $suffix, bool $preview = false): RenderJob
{
    $directory = $preview ? 'previews' : 'renders';
    $key = "projects/{$manual->project_id}/manuals/{$manual->id}/{$directory}/{$suffix}";
    Storage::disk('s3')->put($key, 'fake-output');
    $factory = RenderJob::factory()->forManual($manual);
    if ($preview) {
        $factory = $factory->preview();
    }

    return $factory->succeeded($key)->create();
}

function runDeleteRenderOutputsJob(int $renderJobId, ?RenderObjectStorage $storage = null): void
{
    (new DeleteRenderOutputsJob($renderJobId))->handle(
        $storage ?? app(RenderObjectStorage::class),
        app(RenderJobService::class),
    );
}

test('旧世代のみ削除 + output_path NULL 化 (最新 succeeded は実体・行とも不変)', function (): void {
    [, , , $manual] = retentionContext();
    $old = retentionSucceededJob($manual, 'v1-old.mp4');
    $latest = retentionSucceededJob($manual, 'v2-new.mp4');

    runDeleteRenderOutputsJob($old->id);

    expect(Storage::disk('s3')->exists("projects/{$manual->project_id}/manuals/{$manual->id}/renders/v1-old.mp4"))->toBeFalse();
    expect($old->refresh()->output_path)->toBeNull();
    expect(Storage::disk('s3')->exists((string) $latest->output_path))->toBeTrue();
    expect($latest->refresh()->output_path)->not->toBeNull();
});

test('最新 succeeded 自身は no-op (世代交代していない)', function (): void {
    [, , , $manual] = retentionContext();
    $latest = retentionSucceededJob($manual, 'v2-new.mp4');

    runDeleteRenderOutputsJob($latest->id);

    expect(Storage::disk('s3')->exists((string) $latest->output_path))->toBeTrue();
    expect($latest->refresh()->output_path)->not->toBeNull();
});

test('kind が異なる新 succeeded は世代交代に数えない (render と preview は独立)', function (): void {
    [, , , $manual] = retentionContext();
    $render = retentionSucceededJob($manual, 'v1-render.mp4');
    retentionSucceededJob($manual, 'v2-preview.mp4', preview: true); // 後続だが別 kind

    runDeleteRenderOutputsJob($render->id);

    expect(Storage::disk('s3')->exists((string) $render->output_path))->toBeTrue();
});

test('output_path NULL 済みは no-op (冪等)', function (): void {
    [, , , $manual] = retentionContext();
    $job = RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2.mp4')->create();

    runDeleteRenderOutputsJob($job->id); // 例外なく no-op

    expect($job->refresh()->output_path)->toBeNull();
});

test('prefix 不一致は削除せず構造化ログ (過大削除の防止)', function (): void {
    [, , , $manual] = retentionContext();
    $foreignKey = 'projects/999/manuals/999/renders/v1-foreign.mp4';
    Storage::disk('s3')->put($foreignKey, 'foreign');
    $old = RenderJob::factory()->forManual($manual)->succeeded($foreignKey)->create();
    retentionSucceededJob($manual, 'v2-new.mp4');

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'render output prefix mismatch'
            && $context['render_job_id'] === $old->id,
    );

    runDeleteRenderOutputsJob($old->id);

    expect(Storage::disk('s3')->exists($foreignKey))->toBeTrue();
    expect($old->refresh()->output_path)->toBe($foreignKey);
});

test('S3 削除中に DB トランザクション/行ロックを保持しない (段 2 は tx 外)', function (): void {
    [, , , $manual] = retentionContext();
    $old = retentionSucceededJob($manual, 'v1-old.mp4');
    retentionSucceededJob($manual, 'v2-new.mp4');

    // RefreshDatabase がテスト全体を包む base level を記録し、段 2 で増えていないことを検証する
    $baseLevel = DB::transactionLevel();
    $observedLevel = null;
    $storage = new class($observedLevel) extends RenderObjectStorage
    {
        /** @param  int|null  $observedLevel */
        public function __construct(private mixed &$observedLevel) {}

        public function delete(string $key): void
        {
            $this->observedLevel = DB::transactionLevel();
            parent::delete($key);
        }
    };

    runDeleteRenderOutputsJob($old->id, $storage);

    expect($observedLevel)->toBe($baseLevel); // job 自身は tx を張らない
    expect($old->refresh()->output_path)->toBeNull();
});

test('CAS: S3 削除後に output_path が変わっていたら NULL 化しない (検証時の値と一致する行のみ)', function (): void {
    [, , , $manual] = retentionContext();
    $old = retentionSucceededJob($manual, 'v1-old.mp4');
    retentionSucceededJob($manual, 'v2-new.mp4');

    $newPath = "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v9-replaced.mp4";
    $storage = new class($old->id, $newPath) extends RenderObjectStorage
    {
        public function __construct(private readonly int $jobId, private readonly string $newPath) {}

        public function delete(string $key): void
        {
            // 段 2 の最中に並行プロセスが output_path を書き換えた状況を細工
            RenderJob::query()->whereKey($this->jobId)->update(['output_path' => $this->newPath]);
            parent::delete($key);
        }
    };

    runDeleteRenderOutputsJob($old->id, $storage);

    expect($old->refresh()->output_path)->toBe($newPath); // CAS 不一致 → NULL 化しない
});

test('reconcileOutputs: 取り残し (dispatch 喪失) を検出して削除 job を再投入 + 集計', function (): void {
    Queue::fake();
    [, , , $manual] = retentionContext();
    $old = retentionSucceededJob($manual, 'v1-old.mp4');
    $latest = retentionSucceededJob($manual, 'v2-new.mp4');

    $result = app(RenderJobService::class)->reconcileOutputs();

    expect($result['dispatched'])->toBe(1); // 旧世代のみ
    expect($result['skipped'])->toBe(1);    // 最新世代
    Queue::assertPushed(
        DeleteRenderOutputsJob::class,
        fn (DeleteRenderOutputsJob $pushed): bool => $pushed->renderJobId === $old->id,
    );
    Queue::assertNotPushed(
        DeleteRenderOutputsJob::class,
        fn (DeleteRenderOutputsJob $pushed): bool => $pushed->renderJobId === $latest->id,
    );
});

test('render:reconcile-outputs command smoke (dispatched/skipped を出力する)', function (): void {
    Queue::fake();
    [, , , $manual] = retentionContext();
    retentionSucceededJob($manual, 'v1-old.mp4');
    retentionSucceededJob($manual, 'v2-new.mp4');

    $this->artisan('render:reconcile-outputs')
        ->expectsOutputToContain('dispatched 1 delete job(s), skipped 1')
        ->assertSuccessful();
});
