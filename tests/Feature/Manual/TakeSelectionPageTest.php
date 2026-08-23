<?php

declare(strict_types=1);

use App\Enums\Manual\MaterialType;
use App\Enums\Manual\TakeStatus;
use App\Enums\ProjectRole;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;

/*
 * テイク選択・採用画面 (GET /projects/{project}/manuals/{manual}/cuts/{cut}/takes)。
 * 読み取り専用の画面 props のみを返し、採用・削除・アップロード・再生は
 * capture.takes.* (撮影 PWA と共用の API 面) が担う。
 *
 * 権限境界は**意図的な非対称**である:
 *   - 本画面は編集者のみ (VideoManualPolicy::update)。撮影者は 403
 *   - テイク操作 API は撮影者にも開いている (PcTakeOperationTest が固定する)
 */

/**
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function takeSelectionContext(string $manualStatus = 'ready'): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => $manualStatus]);
    $cut = Cut::factory()->forManual($manual)->withSortOrder(0)->create();

    return [$organization, $owner, $project, $manual, $cut];
}

function takeSelectionPath(Organization $organization, Project $project, VideoManual $manual, Cut $cut): string
{
    return "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes";
}

test('org owner (編集者) は 200 で Manuals/Takes を受け取り cut.label が 手順1 になる', function (): void {
    [$organization, $owner, $project, $manual, $cut] = takeSelectionContext();

    $response = $this->actingAs($owner)->get(takeSelectionPath($organization, $project, $manual, $cut));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Manuals/Takes')
        ->where('project.id', $project->id)
        ->where('manual.id', $manual->id)
        ->where('manual.status', 'ready')
        ->where('cut.id', $cut->id)
        ->where('cut.type', 'step')
        ->where('cut.label', '手順1')
        ->where('cut.adopted', null)
        ->where('takes', []));
});

test('point の cut は 急所1-1 のラベルになる (CutSequencer と同じ導出元)', function (): void {
    [$organization, $owner, $project, $manual, $step] = takeSelectionContext();
    $point = Cut::factory()->asPointOf($step)->withSortOrder(0)->create();

    $this->actingAs($owner)
        ->get(takeSelectionPath($organization, $project, $manual, $point))
        ->assertInertia(fn ($page) => $page->where('cut.label', '急所1-1'));
});

test('親を持たない急所 (データ異常) でも画面は開き中立ラベルへ倒れる', function (): void {
    [$organization, $owner, $project, $manual] = takeSelectionContext();
    // parent_cut_id が null の point は CutSequencer の列に現れない
    $orphan = Cut::factory()->forManual($manual)->create(['type' => 'point']);

    $this->actingAs($owner)
        ->get(takeSelectionPath($organization, $project, $manual, $orphan))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('cut.label', 'カット'));
});

test('project_admin (編集者) も 200 で閲覧できる', function (): void {
    [$organization, , $project, $manual, $cut] = takeSelectionContext();
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);

    $this->actingAs($editor)->get(takeSelectionPath($organization, $project, $manual, $cut))->assertOk();
});

test('project_member (撮影者) は 403 (PWA 側に採用導線があるため詰みではない)', function (): void {
    [$organization, , $project, $manual, $cut] = takeSelectionContext();
    $shooter = attachOrganizationMember($organization);
    attachProjectMember($project, $shooter, ProjectRole::Member);

    $this->actingAs($shooter)->get(takeSelectionPath($organization, $project, $manual, $cut))->assertForbidden();
});

test('cross-org の {project} は認可より前に 404', function (): void {
    [$organization, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB, $ownerB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create();
    $cutB = Cut::factory()->forManual($manualB)->create();
    expect($ownerB)->not->toBeNull();

    $this->actingAs($ownerA)->get(takeSelectionPath($organization, $projectB, $manualB, $cutB))->assertNotFound();
});

test('cross-project の {manual} は 404', function (): void {
    [$organization, $owner, , $manual, $cut] = takeSelectionContext();
    $otherProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get(takeSelectionPath($organization, $otherProject, $manual, $cut))->assertNotFound();
});

test('cross-manual の {cut} は 404', function (): void {
    [$organization, $owner, $project, , $cut] = takeSelectionContext();
    $otherManual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->get(takeSelectionPath($organization, $project, $otherManual, $cut))->assertNotFound();
});

test('takes は sort_order 昇順で並び downloaded / has_thumbnail が反映される', function (): void {
    [$organization, $owner, $project, $manual, $cut] = takeSelectionContext();
    $second = Take::factory()->forCut($cut)->downloaded()->create(['sort_order' => 1]);
    $first = Take::factory()->forCut($cut)->withThumbnail()->create(['sort_order' => 0]);

    $this->actingAs($owner)
        ->get(takeSelectionPath($organization, $project, $manual, $cut))
        ->assertInertia(fn ($page) => $page
            ->where('takes.0.id', $first->id)
            ->where('takes.0.downloaded', false)
            ->where('takes.0.has_thumbnail', true)
            ->where('takes.1.id', $second->id)
            ->where('takes.1.downloaded', true)
            ->where('takes.1.has_thumbnail', false));
});

test('採用テイクは cut.adopted に id と status で載る', function (): void {
    [$organization, $owner, $project, $manual, $cut] = takeSelectionContext();
    $take = Take::factory()->forCut($cut)->create(['status' => TakeStatus::Ready->value]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    $this->actingAs($owner)
        ->get(takeSelectionPath($organization, $project, $manual, $cut))
        ->assertInertia(fn ($page) => $page
            ->where('cut.adopted.id', $take->id)
            ->where('cut.adopted.status', 'ready'));
});

test('props に署名 URL / 保存パス / ACK トークンのスロットが一切現れない', function (): void {
    [$organization, $owner, $project, $manual, $cut] = takeSelectionContext();
    Take::factory()->forCut($cut)->withThumbnail()->create();

    $response = $this->actingAs($owner)->get(takeSelectionPath($organization, $project, $manual, $cut));

    $response->assertOk();
    $props = $response->viewData('page')['props'];
    $encoded = json_encode($props, JSON_UNESCAPED_UNICODE);
    expect($encoded)->toBeString();
    foreach (['playback_url', 'video_path', 'thumbnail_path', 'download_ack_token', 'adopted_take_id'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
});

test('未契約組織は onboarding へ遮断される (課金ゲートの中にある)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();

    $this->actingAs($owner)
        ->get(takeSelectionPath($organization, $project, $manual, $cut))
        ->assertRedirect(route('onboarding.checkout', ['organization' => $organization->slug]));
});

test('cut の計画 (material_type) と採用テイクの実体が props に載る', function (): void {
    // cut 側は**計画** (未指定あり。ファイル選択の accept 切替に使う) /
    // take 側は**実体** (NOT NULL。<video> と <img> の出し分けに使う)。別のキーである。
    [$organization, $owner, $project, $manual, $cut] = takeSelectionContext();
    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
    $take = Take::factory()->forCut($cut)->still()->create();
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    $this->actingAs($owner)
        ->get(takeSelectionPath($organization, $project, $manual, $cut))
        ->assertInertia(fn ($page) => $page
            ->where('cut.material_type', 'still')
            ->where('cut.adopted.material_type', 'still')
            ->where('takes.0.material_type', 'still'));
});

test('計画未指定 + 動画テイクでは cut.material_type が null / take は video', function (): void {
    [$organization, $owner, $project, $manual, $cut] = takeSelectionContext();
    Take::factory()->forCut($cut)->create();

    $this->actingAs($owner)
        ->get(takeSelectionPath($organization, $project, $manual, $cut))
        ->assertInertia(fn ($page) => $page
            ->where('cut.material_type', null)
            ->where('takes.0.material_type', 'video'));
});
