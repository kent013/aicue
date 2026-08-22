<?php

declare(strict_types=1);

use App\Enums\Manual\TakeStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/*
 * プレビューと完成生成の判断基準の同一性 (T148 / bug-hunt F-1-01)。
 *
 * 再現していた症状: 採用テイクが揃っていない状態で
 * - 完成動画生成 (render) は 422 で未充足カットを列挙してブロックする
 * - プレビュー (preview) は**何も知らせずに**黒背景だらけの動画を出す
 * 「同じ前提条件に対して片方は止め、片方は黙って壊れた成果物を出す」ことが finding の核。
 *
 * 本テストが固定する契約:
 * - preview は**ブロックしない** (未撮影は制作途中の正常な状態。ボタンも止めない)
 * - ただし詳細画面 props が render の 422 と**同じ述語・同じ件数**を事前告知する
 * - 判定は AdoptedReadyTakeCoverage 1 箇所を通る (件数が乖離しない)
 */

/**
 * 編集者 (owner) + ready manual + 採用済み ready テイク付きの step カット 1 枚。
 *
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function previewCoverageContext(int $tickets = 3): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
    ]);
    $cut = Cut::factory()->forManual($manual)->create();
    previewCoverageAdopt($cut);
    if ($tickets > 0) {
        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
    }

    return [$organization, $owner, $project, $manual, $cut];
}

/** cut にテイクを作成して採用する (status は指定可能 = 述語の 4 値差を作る) */
function previewCoverageAdopt(Cut $cut, TakeStatus $status = TakeStatus::Ready): Take
{
    $take = Take::factory()->forCut($cut)->create([
        'duration_ms' => 5_000,
        'status' => $status->value,
    ]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    return $take;
}

/** 詳細画面の render props を取り出す */
function previewCoverageRenderProps(Organization $organization, Project $project, VideoManual $manual, User $actor): array
{
    $props = [];
    test()->actingAs($actor)
        ->get("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            /** @var array<string, mixed> $render */
            $render = $page->toArray()['props']['render'];
            $props = $render;
        });

    return $props;
}

test('A-1: render は未充足カットがあると 422 で未充足カットを列挙する', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = previewCoverageContext();
    Cut::factory()->forManual($manual)->withSortOrder(1)->create(); // 未採用

    $response = $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render",
    );

    $response->assertUnprocessable()->assertJsonValidationErrors(['takes']);
    expect($response->json('errors.takes.0'))->toContain('手順2');
});

test('A-2: preview は未充足カットがあっても 201 で受け付ける (ブロックしない)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = previewCoverageContext();
    Cut::factory()->forManual($manual)->withSortOrder(1)->create(); // 未採用

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertCreated();
});

test('A-3: render 422 の列挙件数と詳細画面 coverage の missing_count が一致する', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = previewCoverageContext();
    // 未充足 3 件 (未採用 2 + 採用済みだが processing 1)
    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    Cut::factory()->forManual($manual)->withSortOrder(2)->create();
    previewCoverageAdopt(
        Cut::factory()->forManual($manual)->withSortOrder(3)->create(),
        TakeStatus::Processing,
    );

    $response = $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render",
    );
    $response->assertUnprocessable();
    $message = (string) $response->json('errors.takes.0');
    $enumerated = substr_count($message, '、') + 1;

    $render = previewCoverageRenderProps($organization, $project, $manual, $owner);

    expect($enumerated)->toBe(3);
    expect($render['coverage']['missing_count'])->toBe($enumerated);
    expect($render['coverage']['total_cuts'])->toBe(4);
});

test('A-4: 詳細画面 props に total_cuts / missing_count / missing_labels が載る', function (): void {
    [, $owner, $project, $manual] = previewCoverageContext();
    Cut::factory()->forManual($manual)->withSortOrder(1)->create();

    $render = previewCoverageRenderProps($organization, $project, $manual, $owner);

    expect($render['coverage'])->toBe([
        'total_cuts' => 2,
        'missing_count' => 1,
        'missing_labels' => ['手順2'],
    ]);
});

test('A-5: すべて充足なら missing_count は 0 でラベルは空になる', function (): void {
    [, $owner, $project, $manual] = previewCoverageContext();

    $render = previewCoverageRenderProps($organization, $project, $manual, $owner);

    expect($render['coverage']['missing_count'])->toBe(0);
    expect($render['coverage']['missing_labels'])->toBe([]);
    expect($render['coverage']['total_cuts'])->toBe(1);
});

test('A-6: 採用済みだが ready でないテイクも missing として数える', function (TakeStatus $status): void {
    [, $owner, $project, $manual] = previewCoverageContext();
    previewCoverageAdopt(
        Cut::factory()->forManual($manual)->withSortOrder(1)->create(),
        $status,
    );

    $render = previewCoverageRenderProps($organization, $project, $manual, $owner);

    expect($render['coverage']['missing_count'])->toBe(1);
    expect($render['coverage']['missing_labels'])->toBe(['手順2']);
})->with([
    'uploading' => TakeStatus::Uploading,
    'processing' => TakeStatus::Processing,
    'failed' => TakeStatus::Failed,
]);

test('A-7: missing が 11 件のとき missing_labels は 10 件で missing_count は 11 になる', function (): void {
    [, $owner, $project, $manual] = previewCoverageContext();
    foreach (range(1, 11) as $index) {
        Cut::factory()->forManual($manual)->withSortOrder($index)->create();
    }

    $render = previewCoverageRenderProps($organization, $project, $manual, $owner);

    expect($render['coverage']['missing_count'])->toBe(11);
    expect($render['coverage']['missing_labels'])->toHaveCount(10);
    expect($render['coverage']['total_cuts'])->toBe(12);
});

test('A-8: 撮影者にも coverage は返るが preview / render の起動は 403 のまま', function (): void {
    Queue::fake();
    [$organization, , $project, $manual] = previewCoverageContext();
    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);

    $render = previewCoverageRenderProps($organization, $project, $manual, $member);
    expect($render['coverage']['missing_count'])->toBe(1);

    $this->actingAs($member)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertForbidden();
    $this->actingAs($member)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertForbidden();
});
