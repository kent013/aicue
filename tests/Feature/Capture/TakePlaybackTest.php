<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;

/*
 * テイク単体プレビュー再生 (T050 / S1): GET .../takes/{take}/playback。
 * ready テイクのみ 302 で S3 署名 URL へリダイレクト (Cache-Control: no-store, private)。
 * 非 ready は 404 (状態秘匿) / 非 capture は 403 / IDOR は各 404。
 */

beforeEach(function (): void {
    enableFakeStorage();
});

/**
 * @return array{Organization, User, Project, VideoManual, Cut, Take}
 */
function takePlaybackContext(string $takeStatus = 'ready'): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['status' => $takeStatus]);

    return [$organization, $owner, $project, $manual, $cut, $take];
}

function playbackPath(Organization $organization, Project $project, VideoManual $manual, Cut $cut, Take $take): string
{
    return "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}/playback";
}

test('撮影者が ready テイクを GET playback すると 302 で署名 URL へリダイレクトし no-store かつ private を返す', function (): void {
    [$organization, $owner, $project, $manual, $cut, $take] = takePlaybackContext();

    $response = $this->actingAs($owner)->get(playbackPath($organization, $project, $manual, $cut, $take));

    $response->assertStatus(302);
    // 署名 URL は対象 take の video_path から生成される (別 take の path を使わない)
    $location = $response->headers->get('Location');
    expect($location)->not->toBeNull();
    expect($location)->toContain(urlencode($take->video_path));

    $cacheControl = $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('private');
});

test('署名 URL は別 take の path を使わない (対象 take の video_path が Location に載る)', function (): void {
    [$organization, $owner, $project, $manual, $cut, $take] = takePlaybackContext();
    // 同カットに別 take を作る (混入検知)
    $otherTake = Take::factory()->forCut($cut)->create(['status' => 'ready']);

    $location = $this->actingAs($owner)
        ->get(playbackPath($organization, $project, $manual, $cut, $take))
        ->headers->get('Location');

    expect($location)->toContain(urlencode($take->video_path));
    expect($location)->not->toContain(urlencode($otherTake->video_path));
});

test('非 ready テイク (uploading/processing/failed) は 404 (状態秘匿)', function (string $status): void {
    [$organization, $owner, $project, $manual, $cut, $take] = takePlaybackContext($status);

    $this->actingAs($owner)
        ->get(playbackPath($organization, $project, $manual, $cut, $take))
        ->assertNotFound();
})->with(['uploading', 'processing', 'failed']);

test('非 capture ユーザー (非 project member の org member) は 403', function (): void {
    [$organization, , $project, $manual, $cut, $take] = takePlaybackContext();
    $orgMember = attachOrganizationMember($organization);

    $this->actingAs($orgMember)
        ->get(playbackPath($organization, $project, $manual, $cut, $take))
        ->assertForbidden();
});

test('team 文脈: role が別 team で付与されている間は 403 / 正しい team で付与されると 302', function (): void {
    [$organization, , $project, $manual, $cut, $take] = takePlaybackContext();
    // 別組織 (別 laratrust team) を用意し、そちらの team_id で Member ロールを付与する
    [$otherOrg] = createOrganizationWithOwner('別組織');

    $user = User::factory()->create();
    $organization->users()->attach($user);
    attachProjectMember($project, $user, ProjectRole::Member);

    // 誤った team 文脈 (otherOrg の team_id) でロールを付与 → 対象 org では role null = 403
    $user->addRole(OrganizationRole::Member->value, $otherOrg->laratrust_team_id);
    $this->actingAs($user)
        ->get(playbackPath($organization, $project, $manual, $cut, $take))
        ->assertForbidden();

    // 正しい team 文脈で付与 → 302 (権限判定が team scope で効く)
    $user->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);
    $this->actingAs($user->fresh())
        ->get(playbackPath($organization, $project, $manual, $cut, $take))
        ->assertStatus(302);
});

test('IDOR: project mismatch は 404 (認可より前)', function (): void {
    [$organization, $owner, , $manual, $cut, $take] = takePlaybackContext();
    $otherProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)
        ->get(playbackPath($organization, $otherProject, $manual, $cut, $take))
        ->assertNotFound();
});

test('IDOR: manual mismatch は 404', function (): void {
    [$organization, $owner, $project, , $cut, $take] = takePlaybackContext();
    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    $this->actingAs($owner)
        ->get(playbackPath($organization, $project, $otherManual, $cut, $take))
        ->assertNotFound();
});

test('IDOR: cut mismatch は 404', function (): void {
    [$organization, $owner, $project, $manual, , $take] = takePlaybackContext();
    $otherCut = Cut::factory()->forManual($manual)->create();

    $this->actingAs($owner)
        ->get(playbackPath($organization, $project, $manual, $otherCut, $take))
        ->assertNotFound();
});

test('IDOR: take mismatch (別 cut 所属の take を別 cut の URL で) は 404', function (): void {
    [$organization, $owner, $project, $manual, $cut] = takePlaybackContext();
    $cutB = Cut::factory()->forManual($manual)->create();
    $takeB = Take::factory()->forCut($cutB)->create(['status' => 'ready']);

    $this->actingAs($owner)
        ->get(playbackPath($organization, $project, $manual, $cut, $takeB))
        ->assertNotFound();
});

test('IDOR: cross-org は 404', function (): void {
    [$organization, , $project, $manual, $cut, $take] = takePlaybackContext();
    [$otherOrg, $otherOwner] = createOrganizationWithOwner('別組織');

    $this->actingAs($otherOwner)
        ->get(playbackPath($organization, $project, $manual, $cut, $take))
        ->assertNotFound();
});
