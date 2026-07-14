<?php

declare(strict_types=1);

use App\Enums\Manual\CutType;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Support\Manual\ScenarioLimits;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * シナリオ document 一括保存 (PUT .../manuals/{manual}/scenario)。
 * - 楽観ロック (expected_version) + rendering/analyzing guard は 409 (code=scenario_conflict)
 * - parent_cut_id / adopted_take_id / sort_order / type はサーバ導出 (payload では 422)
 * - payload の cut id は照合専用 (他 manual の id は 404、階層/型不一致・重複は 422)
 */

/**
 * 編集画面 + 編集者 (owner) 一式のセットアップ。
 *
 * @return array{Organization, User, Project, VideoManual}
 */
function scenarioTestContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    return [$organization, $owner, $project, $manual];
}

/**
 * 保存 payload の step 1 行 (points 込み)。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scenarioStepPayload(array $overrides = []): array
{
    return array_merge([
        'id' => null,
        'scene' => '作業台を準備する',
        'shot_type' => 'hiki',
        'shooting_point' => null,
        'narration' => '作業台の準備を行います',
        'subtitle_primary' => null,
        'subtitle_secondary' => '作業台を準備',
        'material_type' => null,
        'static_display_seconds' => null,
        'points' => [],
    ], $overrides);
}

/**
 * 保存 payload の point 1 行。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scenarioPointPayload(array $overrides = []): array
{
    return array_merge([
        'id' => null,
        'scene' => '工具の持ち方',
        'shot_type' => 'yori',
        'shooting_point' => '手元を大きく写す',
        'narration' => '工具はこのように持ちます',
        'subtitle_primary' => null,
        'subtitle_secondary' => '持ち方に注意',
        'material_type' => null,
        'static_display_seconds' => null,
    ], $overrides);
}

/** 既存 Cut を payload 行 (現在値そのまま) に変換する (no-op 保存テスト用)。 */
function scenarioRowFromCut(Cut $cut): array
{
    return [
        'id' => $cut->id,
        'scene' => $cut->scene,
        'shot_type' => $cut->shot_type->value,
        'shooting_point' => $cut->shooting_point,
        'narration' => $cut->narration,
        'subtitle_primary' => $cut->subtitle_primary,
        'subtitle_secondary' => $cut->subtitle_secondary,
        'material_type' => $cut->material_type?->value,
        'static_display_seconds' => $cut->static_display_seconds,
    ];
}

test('編集者は steps+points を一括保存できる (type/parent/sort はサーバ採番・version+1)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();

    $response = $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 0,
            'steps' => [
                scenarioStepPayload([
                    'scene' => '手順1',
                    'points' => [
                        scenarioPointPayload(['scene' => '急所1-1']),
                        scenarioPointPayload(['scene' => '急所1-2']),
                    ],
                ]),
                scenarioStepPayload(['scene' => '手順2']),
            ],
        ],
    );

    $response->assertOk();
    $response->assertJsonPath('scenario_version', 1);
    $response->assertJsonPath('steps.0.scene', '手順1');
    $response->assertJsonPath('steps.0.points.0.scene', '急所1-1');
    $response->assertJsonPath('steps.0.points.1.scene', '急所1-2');
    $response->assertJsonPath('steps.1.scene', '手順2');
    $response->assertJsonPath('steps.1.points', []);

    $manual->refresh();
    expect($manual->scenario_version)->toBe(1);

    $steps = $manual->cuts()->where('type', CutType::Step->value)->orderBy('sort_order')->get();
    expect($steps)->toHaveCount(2);
    expect($steps[0]?->parent_cut_id)->toBeNull();
    expect($steps[0]?->sort_order)->toBe(0);
    expect($steps[1]?->sort_order)->toBe(1);

    $points = $manual->cuts()->where('type', CutType::Point->value)->orderBy('sort_order')->get();
    expect($points)->toHaveCount(2);
    expect($points[0]?->parent_cut_id)->toBe($steps[0]?->id);
    expect($points[0]?->sort_order)->toBe(0);
    expect($points[1]?->parent_cut_id)->toBe($steps[0]?->id);
    expect($points[1]?->sort_order)->toBe(1);
});

test('既存 cut の本文更新が反映される (fill 対象フィールドのみ変化)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $step = Cut::factory()->forManual($manual)->create(['scene' => '元のシーン']);

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 0,
            'steps' => [
                array_merge(scenarioRowFromCut($step), [
                    'scene' => '更新後のシーン',
                    'subtitle_primary' => '字幕①',
                    'points' => [],
                ]),
            ],
        ],
    )->assertOk();

    $step->refresh();
    expect($step->scene)->toBe('更新後のシーン');
    expect($step->subtitle_primary)->toBe('字幕①');
    expect($step->type)->toBe(CutType::Step);
    expect($step->parent_cut_id)->toBeNull();
});

test('並べ替えが反映される (sort_order 0..N-1 の gap 除去)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $first = Cut::factory()->forManual($manual)->withSortOrder(3)->create(['scene' => 'A']);
    $second = Cut::factory()->forManual($manual)->withSortOrder(7)->create(['scene' => 'B']);

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 0,
            'steps' => [
                array_merge(scenarioRowFromCut($second), ['points' => []]),
                array_merge(scenarioRowFromCut($first), ['points' => []]),
            ],
        ],
    )->assertOk();

    expect($second->refresh()->sort_order)->toBe(0);
    expect($first->refresh()->sort_order)->toBe(1);
});

test('payload から外した cut は削除される (step 削除で配下 point も)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $keep = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
    $removedStep = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    $removedPoint = Cut::factory()->asPointOf($removedStep)->create();

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 0,
            'steps' => [array_merge(scenarioRowFromCut($keep), ['points' => []])],
        ],
    )->assertOk();

    expect(Cut::query()->whereKey($keep->id)->exists())->toBeTrue();
    expect(Cut::query()->whereKey($removedStep->id)->exists())->toBeFalse();
    expect(Cut::query()->whereKey($removedPoint->id)->exists())->toBeFalse();
});

test('expected_version 不一致は 409 (code 厳格一致) で保存されない', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $manual->forceFill(['scenario_version' => 2])->save();

    $response = $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 1,
            'steps' => [scenarioStepPayload()],
        ],
    );

    $response->assertStatus(409);
    $response->assertJsonPath('code', 'scenario_conflict');
    $response->assertJsonPath('conflict_type', 'version_mismatch');
    $response->assertJsonPath('current_version', 2);

    $manual->refresh();
    expect($manual->scenario_version)->toBe(2);
    expect($manual->cuts()->count())->toBe(0);
});

test('rendering 中の保存は 409 (conflict_type=rendering) で DB 不変', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $manual->forceFill(['status' => VideoManualStatus::Rendering])->save();

    $response = $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => 0, 'steps' => [scenarioStepPayload()]],
    );

    $response->assertStatus(409);
    $response->assertJsonPath('code', 'scenario_conflict');
    $response->assertJsonPath('conflict_type', 'rendering');
    expect($manual->refresh()->scenario_version)->toBe(0);
    expect($manual->cuts()->count())->toBe(0);
});

test('analyzing 中の保存は 409 (conflict_type=analyzing)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $manual->forceFill(['status' => VideoManualStatus::Analyzing])->save();

    $response = $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => 0, 'steps' => [scenarioStepPayload()]],
    );

    $response->assertStatus(409);
    $response->assertJsonPath('conflict_type', 'analyzing');
});

test('実変更があると published→ready へ戻る (version も +1)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $manual->forceFill(['status' => VideoManualStatus::Published, 'scenario_version' => 5])->save();
    $step = Cut::factory()->forManual($manual)->create(['scene' => '元のシーン']);

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 5,
            'steps' => [array_merge(scenarioRowFromCut($step), ['scene' => '変更', 'points' => []])],
        ],
    )->assertOk();

    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Ready);
    expect($manual->scenario_version)->toBe(6);
});

test('実変更なしの no-op 保存は published を維持し version は +1', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $manual->forceFill(['status' => VideoManualStatus::Published, 'scenario_version' => 5])->save();
    $step = Cut::factory()->forManual($manual)->withSortOrder(0)->create();

    $response = $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 5,
            'steps' => [array_merge(scenarioRowFromCut($step), ['points' => []])],
        ],
    );

    $response->assertOk();
    $response->assertJsonPath('scenario_version', 6);
    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Published);
    expect($manual->scenario_version)->toBe(6);
});

test('初回保存 (cuts>=1) で draft→ready へ遷移する (自作シナリオ経路)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    expect($manual->status)->toBe(VideoManualStatus::Draft);

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => 0, 'steps' => [scenarioStepPayload()]],
    )->assertOk();

    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
});

test('draft のまま空 steps を保存しても draft を維持する (version は +1)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => 0, 'steps' => []],
    )->assertOk();

    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Draft);
    expect($manual->scenario_version)->toBe(1);
});

test('保護キー・サーバ導出キーのネスト送出は 422', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";

    // steps.0.parent_cut_id
    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [scenarioStepPayload(['parent_cut_id' => 1])],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.parent_cut_id');

    // steps.0.points.0.adopted_take_id
    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [scenarioStepPayload([
            'points' => [scenarioPointPayload(['adopted_take_id' => 1])],
        ])],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.points.0.adopted_take_id');

    // steps.0.sort_order / steps.0.type (サーバ導出)
    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [scenarioStepPayload(['sort_order' => 0])],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.sort_order');

    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [scenarioStepPayload(['type' => 'step'])],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.type');

    // トップレベル video_manual_id
    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [],
        'video_manual_id' => $manual->id,
    ])->assertStatus(422)->assertJsonValidationErrors('video_manual_id');

    expect($manual->cuts()->count())->toBe(0);
});

test('expected_version 欠落は 422', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['steps' => []],
    )->assertStatus(422)->assertJsonValidationErrors('expected_version');
});

test('本文検証: subtitle_primary 101 文字 / scene 空 / points キー欠落は 422', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";

    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [scenarioStepPayload(['subtitle_primary' => str_repeat('あ', 101)])],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.subtitle_primary');

    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [scenarioStepPayload(['scene' => ''])],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.scene');

    // points キー欠落は行単位の明示エラー (クライアント直列化バグの早期検出)
    $step = scenarioStepPayload();
    unset($step['points']);
    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [$step],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0');
});

test('narration / subtitle_secondary の null は空文字へ正規化され保存できる (下書き許容)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 0,
            'steps' => [scenarioStepPayload(['narration' => null, 'subtitle_secondary' => null])],
        ],
    )->assertOk();

    /** @var Cut $cut */
    $cut = $manual->cuts()->firstOrFail();
    expect($cut->narration)->toBe('');
    expect($cut->subtitle_secondary)->toBe('');
});

test('他 manual の cut id 混入は 404 で DB 不変 (tenant キー不信)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $otherManual = VideoManual::factory()->forProject($project)->create();
    $foreignCut = Cut::factory()->forManual($otherManual)->create(['scene' => '元のシーン']);

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 0,
            'steps' => [array_merge(scenarioRowFromCut($foreignCut), ['scene' => '改竄', 'points' => []])],
        ],
    )->assertNotFound();

    expect($foreignCut->refresh()->scene)->toBe('元のシーン');
    expect($manual->refresh()->scenario_version)->toBe(0);
    expect($manual->cuts()->count())->toBe(0);
});

test('payload 内の cut id 重複は 422', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $step = Cut::factory()->forManual($manual)->create();

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        [
            'expected_version' => 0,
            'steps' => [
                array_merge(scenarioRowFromCut($step), ['points' => []]),
                array_merge(scenarioRowFromCut($step), ['points' => []]),
            ],
        ],
    )->assertStatus(422)->assertJsonValidationErrors('steps.1.id');
});

test('既存 cut の階層/型変更は 422 (step→point 降格・point→step 昇格)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $step = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
    $point = Cut::factory()->asPointOf($step)->create();

    $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";

    // step の id を points 配下に置く (降格)
    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [
            scenarioStepPayload([
                'points' => [array_merge(scenarioRowFromCut($step))],
            ]),
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.points.0.id');

    // point の id をトップレベルに置く (昇格)
    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [array_merge(scenarioRowFromCut($point), ['points' => []])],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.id');
});

test('撮影者 (project_member) は 403', function (): void {
    [$organization, , $project, $manual] = scenarioTestContext();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => 0, 'steps' => []],
    )->assertForbidden();
});

test('未ログインは 401 (JSON)', function (): void {
    [, , $project, $manual] = scenarioTestContext();

    $this->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => 0, 'steps' => []],
    )->assertUnauthorized();
});

test('cross-org の manual への PUT は 404 (存在オラクル封じ)', function (): void {
    [, $owner] = createOrganizationWithOwner('自組織');
    [, , $otherProject, $otherManual] = scenarioTestContext();

    $this->actingAs($owner)->putJson(
        "/projects/{$otherProject->id}/manuals/{$otherManual->id}/scenario",
        ['expected_version' => 0, 'steps' => []],
    )->assertNotFound();
});

test('cross-project の manual への PUT は 404 (scopeBindings)', function (): void {
    [$organization, $owner, , $manual] = scenarioTestContext();
    $otherProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->putJson(
        "/projects/{$otherProject->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => 0, 'steps' => []],
    )->assertNotFound();
});

test('steps / points の上限超過は 422 (有界入力)', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";

    // top-level 上限は MAX_TOP_LEVEL_CUTS(=102。生成 100 + 導入/総括 2)。超過 (103) で 422
    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => array_fill(0, ScenarioLimits::MAX_TOP_LEVEL_CUTS + 1, scenarioStepPayload()),
    ])->assertStatus(422)->assertJsonValidationErrors('steps');

    $this->actingAs($owner)->putJson($url, [
        'expected_version' => 0,
        'steps' => [scenarioStepPayload([
            'points' => array_fill(0, 21, scenarioPointPayload()),
        ])],
    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.points');
});

test('edit 画面 props に scenario ツリーと version / manual.status が載る', function (): void {
    [, $owner, $project, $manual] = scenarioTestContext();
    $manual->forceFill(['scenario_version' => 3])->save();
    $step = Cut::factory()->forManual($manual)->withSortOrder(0)->create(['scene' => '手順シーン']);
    $point = Cut::factory()->asPointOf($step)->create(['scene' => '急所シーン']);

    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manuals/Edit')
            ->where('manual.status', 'draft')
            ->where('scenario.scenario_version', 3)
            ->where('scenario.steps.0.id', $step->id)
            ->where('scenario.steps.0.scene', '手順シーン')
            ->where('scenario.steps.0.points.0.id', $point->id)
            ->where('scenario.steps.0.points.0.scene', '急所シーン'),
        );
});
