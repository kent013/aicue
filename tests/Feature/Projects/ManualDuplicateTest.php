<?php

declare(strict_types=1);

use App\Enums\Manual\CutType;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\AnalysisJob;
use App\Models\Category;
use App\Models\Cut;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\SourceDocument;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Manual\CutSequencer;
use Illuminate\Support\Facades\Log;

/*
 * VideoManual 複製 (別名保存)。保存済み cuts を雛形に新タイトル・カテゴリで新規作成する。
 * - takes / adopted_take_id / render 成果物 / source_documents / analysis_jobs は複製しない
 * - status=draft・scenario_version=0 にリセット、created_by はサーバ導出
 * - 認可: 編集者 = 複製可、撮影者 = 403
 * - IDOR: cross-org / cross-project は 404、他 project category は 422、保護キー category_id は 422
 */

/** 元 manual に step2 + 各 step 配下 point1 の 6 cut シナリオを作る */
function seedScenario(VideoManual $manual): void
{
    $step1 = Cut::factory()->forManual($manual)->withSortOrder(0)->create(['scene' => '手順1本文']);
    Cut::factory()->forManual($manual)->asPointOf($step1)->withSortOrder(0)->create(['scene' => '急所1-1本文']);
    $step2 = Cut::factory()->forManual($manual)->withSortOrder(1)->create(['scene' => '手順2本文']);
    Cut::factory()->forManual($manual)->asPointOf($step2)->withSortOrder(0)->create(['scene' => '急所2-1本文']);
}

test('編集者は保存済みシナリオを新タイトル・カテゴリで複製できる (親子 sort_order・本文・parent 張り替え)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $srcCategory = Category::factory()->forProject($project)->create();
    $newCategory = Category::factory()->forProject($project)->create(['name' => '複製先カテゴリ']);
    $source = VideoManual::factory()->forProject($project)->forCategory($srcCategory)->create(['title' => '元マニュアル']);
    seedScenario($source);
    // 元 cuts の id を保持することを後で明示検証する (複製が元を破壊しないこと)
    $sourceCutIdsBefore = $source->cuts()->orderBy('id')->pluck('id')->all();

    $response = $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '複製後マニュアル',
        'category' => $newCategory->id,
    ]);

    /** @var VideoManual $copy */
    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
    $response->assertRedirect("/projects/{$project->id}/manuals/{$copy->id}");
    $response->assertSessionHas('success');

    expect($copy->title)->toBe('複製後マニュアル');
    expect($copy->category_id)->toBe($newCategory->id);
    expect($copy->created_by)->toBe($owner->id);

    // cuts が二層 (step2 + point2 = 4 件) で複製され、sort_order・本文が一致
    $copyCuts = $copy->cuts()->orderBy('sort_order')->orderBy('id')->get();
    expect($copyCuts)->toHaveCount(4);

    $steps = $copyCuts->where('type', CutType::Step)->values();
    expect($steps->pluck('scene')->all())->toBe(['手順1本文', '手順2本文']);

    // point の parent_cut_id が**新** step id を指す (元 id ではない)
    $points = $copyCuts->where('type', CutType::Point)->values();
    $newStepIds = $steps->pluck('id')->all();
    foreach ($points as $point) {
        expect($point->parent_cut_id)->toBeIn($newStepIds);
    }
    // 急所1-1 は 手順1 に、急所2-1 は 手順2 に紐づく
    $step1 = $steps->firstWhere('scene', '手順1本文');
    $step2 = $steps->firstWhere('scene', '手順2本文');
    expect($points->firstWhere('scene', '急所1-1本文')?->parent_cut_id)->toBe($step1?->id);
    expect($points->firstWhere('scene', '急所2-1本文')?->parent_cut_id)->toBe($step2?->id);

    // 元 manual の cuts は不変 (件数・id 保持)
    expect($source->cuts()->count())->toBe(4);
    expect($source->cuts()->orderBy('id')->pluck('id')->all())->toBe($sourceCutIdsBefore);
});

test('複製先は status=draft・scenario_version=0、step/point 両層で adopted_take_id・cut_length_ms がリセットされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $source = VideoManual::factory()->forProject($project)->create([
        'title' => '公開済み元',
        'status' => VideoManualStatus::Published->value,
        'scenario_version' => 7,
    ]);
    // step / point の両層に adopted_take_id・cut_length_ms を持たせる
    $step = Cut::factory()->forManual($source)->withSortOrder(0)->create(['cut_length_ms' => 5000]);
    $stepTake = Take::factory()->forCut($step)->create();
    $step->forceFill(['adopted_take_id' => $stepTake->id])->save();
    $point = Cut::factory()->forManual($source)->asPointOf($step)->withSortOrder(0)->create(['cut_length_ms' => 3000]);
    $pointTake = Take::factory()->forCut($point)->create();
    $point->forceFill(['adopted_take_id' => $pointTake->id])->save();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => 'リセット確認',
    ])->assertSessionHas('success');

    /** @var VideoManual $copy */
    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
    expect($copy->status)->toBe(VideoManualStatus::Draft);
    expect($copy->scenario_version)->toBe(0);

    foreach ($copy->cuts()->get() as $cut) {
        expect($cut->adopted_take_id)->toBeNull();
        expect($cut->cut_length_ms)->toBeNull();
    }
});

test('複製は category 未指定なら未分類で作成される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    $source = VideoManual::factory()->forProject($project)->forCategory($category)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '未分類で複製',
        'category' => null,
    ])->assertSessionHas('success');

    /** @var VideoManual $copy */
    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
    expect($copy->category_id)->toBeNull();
});

test('takes / source_documents / render_jobs / analysis_jobs は複製されない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $source = VideoManual::factory()->forProject($project)->create();
    $step = Cut::factory()->forManual($source)->create();
    Take::factory()->forCut($step)->create();
    SourceDocument::factory()->forManual($source)->create();
    RenderJob::factory()->forManual($source)->create();
    AnalysisJob::factory()->forManual($source)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '非複製検証',
    ])->assertSessionHas('success');

    /** @var VideoManual $copy */
    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
    expect($copy->sourceDocuments()->count())->toBe(0);
    expect($copy->renderJobs()->count())->toBe(0);
    expect($copy->analysisJobs()->count())->toBe(0);
    // 複製 cut 配下の takes も 0
    $takeCount = Take::query()->whereIn('cut_id', $copy->cuts()->select('id'))->count();
    expect($takeCount)->toBe(0);
});

test('親不明の急所カットは複製されず warning ログが出る (step とその正常 point は複製される)', function (): void {
    Log::spy();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $source = VideoManual::factory()->forProject($project)->create();
    $step = Cut::factory()->forManual($source)->withSortOrder(0)->create(['scene' => '正常手順']);
    Cut::factory()->forManual($source)->asPointOf($step)->withSortOrder(0)->create(['scene' => '正常急所']);
    // 親を持たない孤児 point (通常発生しない異常データ)。parent_cut_id を別 manual の step へ向け複製対象外にする
    $foreignStep = Cut::factory()->forManual(VideoManual::factory()->forProject($project)->create())->create();
    Cut::factory()->forManual($source)->withSortOrder(1)->create([
        'type' => CutType::Point->value,
        'parent_cut_id' => $foreignStep->id,
        'scene' => '孤児急所',
    ]);

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '孤児あり複製',
    ])->assertSessionHas('success');

    /** @var VideoManual $copy */
    $copy = $project->manuals()->where('id', '!=', $source->id)->where('id', '!=', $foreignStep->video_manual_id)->firstOrFail();
    $scenes = $copy->cuts()->pluck('scene')->all();
    expect($scenes)->toContain('正常手順');
    expect($scenes)->toContain('正常急所');
    expect($scenes)->not->toContain('孤児急所');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, '親不明の急所カット'))
        ->once();
});

test('複製直後の CutSequencer が全 cuts をラベル付きで順序どおり返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $source = VideoManual::factory()->forProject($project)->create();
    seedScenario($source);

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '後続接続検証',
    ])->assertSessionHas('success');

    /** @var VideoManual $copy */
    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
    $ordered = CutSequencer::orderedWithLabels($copy);

    expect($ordered)->toHaveCount(4);
    $labels = array_map(fn ($o): string => $o->label, $ordered);
    expect($labels)->toBe(['手順1', '急所1-1', '手順2', '急所2-1']);
    $scenes = array_map(fn ($o): string => $o->cut->scene, $ordered);
    expect($scenes)->toBe(['手順1本文', '急所1-1本文', '手順2本文', '急所2-1本文']);
});

test('撮影者は複製で 403、編集者 (owner) は成功する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $source = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($member)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '撮影者の複製',
    ])->assertForbidden();
    expect($project->manuals()->count())->toBe(1);

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '編集者の複製',
    ])->assertSessionHas('success');
    expect($project->manuals()->count())->toBe(2);
});

test('cross-org / cross-project の複製は 404 (存在を漏らさない)', function (): void {
    [, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create();
    // {manual} が別 project (同一 org B) の場合 = scopeBindings で 404
    $otherProjectB = Project::factory()->forOrganization($orgB)->create();

    // cross-org: 組織A の owner が組織B の manual を複製 → 404
    $this->actingAs($ownerA)->post("/projects/{$projectB->id}/manuals/{$manualB->id}/duplicate", [
        'title' => 'x',
    ])->assertNotFound();

    // cross-project (scopeBindings): {manual} ∈ {project} 不整合 → 404
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $otherProject = Project::factory()->forOrganization($organization)->create();
    $manualInOther = VideoManual::factory()->forProject($otherProject)->create();
    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$manualInOther->id}/duplicate", [
        'title' => 'x',
    ])->assertNotFound();

    expect($otherProjectB->manuals()->count())->toBe(0);
});

test('複製で他 project の category は 422・保護キー category_id 直送は 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $otherProject = Project::factory()->forOrganization($organization)->create();
    $foreignCategory = Category::factory()->forProject($otherProject)->create();
    $ownCategory = Category::factory()->forProject($project)->create();
    $source = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => 'x',
        'category' => $foreignCategory->id,
    ])->assertSessionHasErrors('category');

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => 'x',
        'category_id' => $ownCategory->id,
    ])->assertSessionHasErrors('category_id');

    // どちらも複製されていない
    expect($project->manuals()->where('id', '!=', $source->id)->count())->toBe(0);
});

test('複製の title は必須・max:200', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $source = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '',
    ])->assertSessionHasErrors('title');

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => str_repeat('あ', 201),
    ])->assertSessionHasErrors('title');

    expect($project->manuals()->where('id', '!=', $source->id)->count())->toBe(0);
});
