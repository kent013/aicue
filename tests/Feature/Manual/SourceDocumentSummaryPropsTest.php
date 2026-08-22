<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * F-1-01b: Manuals/Show の analysis.document (現在登録されている手順書の現況)。
 * 「最新」の決定規則 (created_at max → tie-break id max) と PII 境界を固定する。
 */

/**
 * @return array{Organization, User, Project, VideoManual}
 */
function summaryPropsContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'draft']);

    return [$organization, $owner, $project, $manual];
}

function showManual(Organization $organization, User $actor, Project $project, VideoManual $manual): TestResponse
{
    return test()->actingAs($actor)->get("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}");
}

test('show: created_at が異なるとき新しい日時の SOP が document に載る', function (): void {
    [, $owner, $project, $manual] = summaryPropsContext();
    SourceDocument::factory()->forManual($manual)->create([
        'original_name' => 'old.pdf',
        'created_at' => now()->subDay(),
    ]);
    $newer = SourceDocument::factory()->forManual($manual)->create([
        'original_name' => 'new.pdf',
        'created_at' => now(),
    ]);

    showManual($organization, $owner, $project, $manual)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manuals/Show')
            ->where('analysis.document.name', 'new.pdf')
            ->where('analysis.hasDocument', true)
        );
    expect($newer->original_name)->toBe('new.pdf');
});

test('show: created_at が同一のとき id が大きい SOP が document に載る', function (): void {
    [, $owner, $project, $manual] = summaryPropsContext();
    $sameTime = now();
    SourceDocument::factory()->forManual($manual)->create([
        'original_name' => 'first.pdf',
        'created_at' => $sameTime,
    ]);
    SourceDocument::factory()->forManual($manual)->create([
        'original_name' => 'second.pdf',
        'created_at' => $sameTime,
    ]);

    showManual($organization, $owner, $project, $manual)
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.document.name', 'second.pdf')
        );
});

test('show: SOP 添付済みなら document に name/sizeBytes/uploadedAt が載る', function (): void {
    [, $owner, $project, $manual] = summaryPropsContext();
    $uploadedAt = now()->subHours(3);
    SourceDocument::factory()->forManual($manual)->create([
        'original_name' => '作業手順.pdf',
        'size_bytes' => 12345,
        'created_at' => $uploadedAt,
    ]);

    showManual($organization, $owner, $project, $manual)
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.document.name', '作業手順.pdf')
            ->where('analysis.document.sizeBytes', 12345)
            // uploadedAt は ISO 8601 (TZ 付き) 固定 = created_at と 1:1 (存在確認だけにしない)
            ->where('analysis.document.uploadedAt', $uploadedAt->toIso8601String())
        );
});

test('show: SOP 未添付なら document=null かつ hasDocument=false', function (): void {
    [, $owner, $project, $manual] = summaryPropsContext();

    showManual($organization, $owner, $project, $manual)
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.document', null)
            ->where('analysis.hasDocument', false)
        );
});

test('show: hasDocument === (document !== null) が常に成り立つ (添付あり)', function (): void {
    [, $owner, $project, $manual] = summaryPropsContext();
    SourceDocument::factory()->forManual($manual)->create();

    $response = showManual($organization, $owner, $project, $manual);
    $response->assertInertia(function (Assert $page): void {
        $document = $page->toArray()['props']['analysis']['document'] ?? null;
        $hasDocument = $page->toArray()['props']['analysis']['hasDocument'] ?? null;
        expect($hasDocument)->toBe($document !== null);
    });
});

test('show: 同一組織・別 manual の SOP は当該 manual の analysis.document に出ない', function (): void {
    [$organization, $owner, $project, $manual] = summaryPropsContext();
    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'draft']);
    SourceDocument::factory()->forManual($otherManual)->create(['original_name' => 'sentinel-other-manual.pdf']);

    showManual($organization, $owner, $project, $manual)
        ->assertInertia(fn (Assert $page) => $page->where('analysis.document', null));
});

test('show: 別組織の SOP sentinel が現在閲覧中の manual の props に混ざらない', function (): void {
    // 組織 A の manual に sentinel SOP を置く
    [, , , $manualA] = summaryPropsContext();
    SourceDocument::factory()->forManual($manualA)->create(['original_name' => 'sentinel-cross-org.pdf']);

    // 組織 B の owner が組織 B 自身の manual (SOP 未添付) を閲覧する
    [$orgB, $ownerB] = createOrganizationWithOwner();
    $projectB = Project::factory()->forOrganization($orgB)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create(['status' => 'draft']);

    // 組織 A の sentinel が組織 B の props へ混入しない (relation 境界の構造的分離)
    showManual($organization, $ownerB, $projectB, $manualB)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.document', null)
            ->where('analysis.hasDocument', false)
        );
});

test('show: 別組織 manual を直接 show すると 404 (本 finding の DTO 追加で退行しない)', function (): void {
    [$organization, , , $manual] = summaryPropsContext();
    SourceDocument::factory()->forManual($manual)->create(['original_name' => 'sentinel-cross-org.pdf']);

    [$otherOrg, $otherOwner] = createOrganizationWithOwner();
    $otherProject = Project::factory()->forOrganization($otherOrg)->create();

    // 別組織 owner が別組織の project 経由で当該 manual を直接 show → cross-org 404
    test()->actingAs($otherOwner)
        ->get("/organizations/{$organization->slug}/projects/{$otherProject->id}/manuals/{$manual->id}")
        ->assertNotFound();
});
