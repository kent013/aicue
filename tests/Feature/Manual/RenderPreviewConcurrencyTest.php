<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Exceptions\Manual\RenderConflictException;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\RenderJobService;
use Illuminate\Support\Facades\Queue;

/*
 * org 同時 preview 上限の直列化 (概念設計 §4 Round 2 Critical):
 * - 上限検査 + job 作成は Organization 行ロック下で行う (reserve の残高判定と同じ直列化点)
 * - 逐次境界: 上限ちょうどで 409、terminal 化で枠が空く、kind=render は数えない
 * - 並行実行主体を分けた実証 (subprocess) は専用インフラ前提のため既定 skip
 *   (docs/template-divergence.md 参照)
 */

/**
 * @return array{Organization, User, Project}
 */
function previewConcurrencyContext(): array
{
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    return [$organization, $owner, $project];
}

/** ready + cuts 付き manual を作る */
function previewableManual(Project $project): VideoManual
{
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
    ]);
    Cut::factory()->forManual($manual)->create();

    return $manual;
}

test('org 上限の逐次境界: 上限-1 は通り、上限ちょうどで 409 (異なる manual でも数える)', function (): void {
    config()->set('manual.render_max_inflight_previews_per_org', 2);
    [, , $project] = previewConcurrencyContext();
    $service = app(RenderJobService::class);

    $first = $service->triggerPreview($project, previewableManual($project));
    expect($first->status)->toBe(JobStatus::Queued);

    $second = $service->triggerPreview($project, previewableManual($project));
    expect($second->status)->toBe(JobStatus::Queued);

    // 3 本目 (別 manual) は org 上限で 409
    $third = previewableManual($project);
    expect(fn (): RenderJob => $service->triggerPreview($project, $third))
        ->toThrow(RenderConflictException::class, '上限');
});

test('in-flight が terminal 化すると枠が空く (上限は in-flight のみ数える)', function (): void {
    config()->set('manual.render_max_inflight_previews_per_org', 1);
    [, , $project] = previewConcurrencyContext();
    $service = app(RenderJobService::class);

    $first = $service->triggerPreview($project, previewableManual($project));
    $first->status = JobStatus::Failed;
    $first->save();

    $second = $service->triggerPreview($project, previewableManual($project));
    expect($second->status)->toBe(JobStatus::Queued);
});

test('kind=render の in-flight は preview 上限に数えない (別操作種別)', function (): void {
    config()->set('manual.render_max_inflight_previews_per_org', 1);
    [, , $project] = previewConcurrencyContext();
    $renderManual = previewableManual($project);
    RenderJob::factory()->forManual($renderManual)->running()->create(); // kind=render

    $job = app(RenderJobService::class)->triggerPreview($project, previewableManual($project));
    expect($job->status)->toBe(JobStatus::Queued);
});

test('並行実行主体を分けた直列化の実証 (subprocess。専用インフラ前提)', function (): void {
    // 実装は Organization 行ロック (triggerPreview) で直列化されており、逐次境界は上のテストで
    // 固定済み。subprocess での実証は RefreshDatabase のトランザクション分離により
    // 子プロセスから親のフィクスチャが見えないため、専用の非トランザクション pgsql 環境
    // (RUN_CONCURRENCY_TESTS=1 + 専用 DB セットアップ) が必要。導入時に本テストを実装する
    // (設計逸脱の記録: docs/template-divergence.md)
})->skip('subprocess 直列化実証は専用の非トランザクション pgsql 環境が必要 (docs/template-divergence.md 参照)');
