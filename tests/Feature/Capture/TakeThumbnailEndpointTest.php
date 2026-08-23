<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;

/*
 * テイクのサムネイル配信 (T183 / S7): GET .../takes/{take}/thumbnail。
 * 生成済み + ready のみ 302 で S3 署名 URL へ (Cache-Control: no-store, private)。
 * 未生成 / 非 ready は 404 (状態秘匿) / 非 capture は 403 / IDOR は各 404。
 */

beforeEach(function (): void {
    enableFakeStorage();
});

/**
 * @return array{Organization, User, Project, VideoManual, Cut, Take}
 */
function takeThumbnailContext(string $takeStatus = 'ready', bool $withThumbnail = true): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $factory = Take::factory()->forCut($cut);
    if ($withThumbnail) {
        $factory = $factory->withThumbnail();
    }
    $take = $factory->create(['status' => $takeStatus]);

    return [$organization, $owner, $project, $manual, $cut, $take];
}

function thumbnailPath(Organization $organization, Project $project, VideoManual $manual, Cut $cut, Take $take): string
{
    return "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}/thumbnail";
}

test('生成済み ready テイクは 302 で署名 URL へリダイレクトし no-store かつ private を返す', function (): void {
    [$organization, $owner, $project, $manual, $cut, $take] = takeThumbnailContext();

    $response = $this->actingAs($owner)->get(thumbnailPath($organization, $project, $manual, $cut, $take));

    $response->assertStatus(302);
    $location = $response->headers->get('Location');
    expect($location)->not->toBeNull();
    // 動画ではなくサムネイルのキーが載る (video_path を誤って渡していないこと)
    expect($location)->toContain(urlencode((string) $take->thumbnail_path));
    expect($location)->not->toContain(urlencode($take->video_path));

    $cacheControl = $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('private');
});

test('署名 URL は別 take のサムネイルを使わない', function (): void {
    [$organization, $owner, $project, $manual, $cut, $take] = takeThumbnailContext();
    $other = Take::factory()->forCut($cut)->withThumbnail()->create(['status' => 'ready']);

    $location = $this->actingAs($owner)
        ->get(thumbnailPath($organization, $project, $manual, $cut, $take))
        ->headers->get('Location');

    expect($location)->toContain(urlencode((string) $take->thumbnail_path));
    expect($location)->not->toContain(urlencode((string) $other->thumbnail_path));
});

test('未生成 (thumbnail_path=null) は 404', function (): void {
    [$organization, $owner, $project, $manual, $cut, $take] = takeThumbnailContext(withThumbnail: false);

    $this->actingAs($owner)
        ->get(thumbnailPath($organization, $project, $manual, $cut, $take))
        ->assertNotFound();
});

test('非 ready テイクは生成済みでも 404 (状態秘匿)', function (string $status): void {
    [$organization, $owner, $project, $manual, $cut, $take] = takeThumbnailContext($status);

    $this->actingAs($owner)
        ->get(thumbnailPath($organization, $project, $manual, $cut, $take))
        ->assertNotFound();
})->with(['uploading', 'processing', 'failed']);

test('非 capture ユーザー (非 project member の org member) は 403', function (): void {
    [$organization, , $project, $manual, $cut, $take] = takeThumbnailContext();
    $orgMember = attachOrganizationMember($organization);

    $this->actingAs($orgMember)
        ->get(thumbnailPath($organization, $project, $manual, $cut, $take))
        ->assertForbidden();
});

test('未認証はログインへリダイレクトする', function (): void {
    [$organization, , $project, $manual, $cut, $take] = takeThumbnailContext();

    $this->get(thumbnailPath($organization, $project, $manual, $cut, $take))->assertRedirect('/login');
});

test('IDOR: project mismatch は 404 (認可より前)', function (): void {
    [$organization, $owner, , $manual, $cut, $take] = takeThumbnailContext();
    $otherProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)
        ->get(thumbnailPath($organization, $otherProject, $manual, $cut, $take))
        ->assertNotFound();
});

test('IDOR: manual mismatch は 404', function (): void {
    [$organization, $owner, $project, , $cut, $take] = takeThumbnailContext();
    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    $this->actingAs($owner)
        ->get(thumbnailPath($organization, $project, $otherManual, $cut, $take))
        ->assertNotFound();
});

test('IDOR: cut mismatch は 404', function (): void {
    [$organization, $owner, $project, $manual, , $take] = takeThumbnailContext();
    $otherCut = Cut::factory()->forManual($manual)->create();

    $this->actingAs($owner)
        ->get(thumbnailPath($organization, $project, $manual, $otherCut, $take))
        ->assertNotFound();
});

test('IDOR: take mismatch (別 cut 所属の take を別 cut の URL で) は 404', function (): void {
    [$organization, $owner, $project, $manual, $cut] = takeThumbnailContext();
    $cutB = Cut::factory()->forManual($manual)->create();
    $takeB = Take::factory()->forCut($cutB)->withThumbnail()->create(['status' => 'ready']);

    $this->actingAs($owner)
        ->get(thumbnailPath($organization, $project, $manual, $cut, $takeB))
        ->assertNotFound();
});

test('IDOR: cross-org は 404', function (): void {
    [$organization, , $project, $manual, $cut, $take] = takeThumbnailContext();
    [$otherOrg, $otherOwner] = createOrganizationWithOwner('別組織');

    $this->actingAs($otherOwner)
        ->get(thumbnailPath($organization, $project, $manual, $cut, $take))
        ->assertNotFound();
});
