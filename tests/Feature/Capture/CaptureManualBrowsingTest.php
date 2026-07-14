<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 撮影 PWA 画面 (施策7): home redirect / index 絞り込み / show の props shape。
 * 採用テイクのみ playback_url + download_ack_token (詳細 GET が唯一の設定経路)。
 */

/**
 * @return array{Organization, User, Project}
 */
function browsingContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    return [$organization, $owner, $project];
}

test('/app は current org の先頭 project の撮影一覧へ redirect する', function (): void {
    [, $owner, $project] = browsingContext();

    $this->actingAs($owner)->get('/app')
        ->assertRedirect("/app/projects/{$project->id}/manuals");
});

test('/app は project が無ければ 404', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/app')->assertNotFound();
});

test('index は ready/published のみ表示し draft/analyzing/rendering は隠す', function (): void {
    [, $owner, $project] = browsingContext();
    $ready = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $published = VideoManual::factory()->forProject($project)->create(['status' => 'published']);
    VideoManual::factory()->forProject($project)->create(['status' => 'draft']);
    VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
    VideoManual::factory()->forProject($project)->create(['status' => 'rendering']);

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Capture/Index')
            ->has('manuals', 2)
            ->where('manuals.0.id', fn ($id) => in_array($id, [$ready->id, $published->id], true))
        );
});

test('index は category / q で絞り込める + 進捗カウントを含む', function (): void {
    [, $owner, $project] = browsingContext();
    $category = Category::factory()->forProject($project)->create();
    $target = VideoManual::factory()->forProject($project)->forCategory($category)->create([
        'status' => 'ready', 'title' => 'ネジ締め作業',
    ]);
    VideoManual::factory()->forProject($project)->create(['status' => 'ready', 'title' => '清掃作業']);

    $cutWithTake = Cut::factory()->forManual($target)->create();
    Cut::factory()->forManual($target)->create(); // takes 無し
    $take = Take::factory()->forCut($cutWithTake)->create();
    // 採用 (行ロック規約準拠の Service 経由)
    $this->actingAs($owner)->postJson(
        "/app/projects/{$project->id}/manuals/{$target->id}/cuts/{$cutWithTake->id}/takes/{$take->id}/adopt",
    )->assertOk();

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?category={$category->id}&q=ネジ")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Capture/Index')
            ->has('manuals', 1)
            ->where('manuals.0.id', $target->id)
            ->where('manuals.0.category_id', $category->id)
            ->where('manuals.0.cuts_total', 2)
            ->where('manuals.0.cuts_adopted', 1)
            ->where('manuals.0.cuts_with_takes', 1)
        );

    // 不一致の絞り込みは 0 件
    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=存在しない")
        ->assertInertia(fn (Assert $page) => $page->has('manuals', 0));
});

test('index は mine=1 で自作シナリオのみに絞る (ready/published と AND)', function (): void {
    [$organization, $owner, $project] = browsingContext();
    $other = attachOrganizationMember($organization);
    $mine = VideoManual::factory()->forProject($project)->createdBy($owner)->create([
        'status' => 'ready', 'title' => '自作 ready',
    ]);
    // 他人作 (mine で除外) / 自作だが draft (status で除外)
    VideoManual::factory()->forProject($project)->createdBy($other)->create(['status' => 'ready']);
    VideoManual::factory()->forProject($project)->createdBy($owner)->create(['status' => 'draft']);

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?mine=1")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $mine->id)
            ->where('filters.mine', true));
});

test('index は manuals.*.creator_name と filters.mine を供給する', function (): void {
    [, $owner, $project] = browsingContext();
    VideoManual::factory()->forProject($project)->createdBy($owner)->create(['status' => 'ready']);

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.0.creator_name', $owner->name)
            ->where('filters.mine', false));
});

test('index の summary shape は TS CaptureManualSummary と対のキー集合 (PHP↔TS 契約)', function (): void {
    [, $owner, $project] = browsingContext();
    VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    $summary = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
        ->inertiaPage()['props']['manuals'][0];
    expect(array_keys($summary))->toBe([
        'id', 'title', 'status', 'category_id', 'category_name',
        'cuts_total', 'cuts_adopted', 'cuts_with_takes', 'updated_at', 'creator_name',
    ]);
});

test('show は cuts+takes を返し、採用テイクのみ playback_url / download_ack_token を持つ', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $step = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
    $point = Cut::factory()->asPointOf($step)->withSortOrder(0)->create();
    $adopted = Take::factory()->forCut($step)->create(['sort_order' => 0]);
    $other = Take::factory()->forCut($step)->create(['sort_order' => 1]);
    $this->actingAs($owner)->postJson(
        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$step->id}/takes/{$adopted->id}/adopt",
    )->assertOk();

    $storage = Mockery::mock(TakeObjectStorage::class);
    $storage->shouldReceive('temporaryPlaybackUrl')
        ->with($adopted->video_path)
        ->once()
        ->andReturn('https://s3.fake.test/signed-get-url');
    app()->instance(TakeObjectStorage::class, $storage);

    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Capture/Show')
        ->where('manual.id', $manual->id)
        ->has('manual.cuts', 2)
        ->where('manual.cuts.0.id', $step->id)
        ->where('manual.cuts.1.id', $point->id)
        ->where('manual.cuts.1.parent_cut_id', $step->id)
        ->where('manual.cuts.0.adopted_take_id', $adopted->id)
        ->where('manual.cuts.0.takes.0.id', $adopted->id)
        ->where('manual.cuts.0.takes.0.playback_url', 'https://s3.fake.test/signed-get-url')
        ->where('manual.cuts.0.takes.1.id', $other->id)
        ->where('manual.cuts.0.takes.1.playback_url', null)
        ->where('manual.cuts.0.takes.1.download_ack_token', null)
    );

    // ACK トークンは採用テイクにのみ付与され、openAck で検証可能 (PHP↔TS 契約)
    $props = $response->inertiaPage()['props'];
    $ackToken = $props['manual']['cuts'][0]['takes'][0]['download_ack_token'];
    expect($ackToken)->toBeString();
    $claims = app(UploadTicketCodec::class)->openAck($ackToken);
    expect($claims?->takeId)->toBe($adopted->id);
    expect($claims?->userId)->toBe($owner->id);
});

test('show の take shape は TS CaptureTake と対のキー集合 (PHP↔TS 契約)', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    Take::factory()->forCut($cut)->create();

    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");

    $take = $response->inertiaPage()['props']['manual']['cuts'][0]['takes'][0];
    expect(array_keys($take))->toBe([
        'id', 'client_take_id', 'status', 'size_bytes', 'duration_ms', 'comment',
        'captured_at', 'sort_order', 'downloaded', 'playback_url', 'download_ack_token',
    ]);
    $cutShape = $response->inertiaPage()['props']['manual']['cuts'][0];
    expect(array_keys($cutShape))->toBe([
        'id', 'type', 'parent_cut_id', 'scene', 'shot_type', 'shooting_point',
        'narration', 'subtitle_primary', 'subtitle_secondary', 'adopted_take_id', 'takes',
    ]);
});

test('cross-org の project は index / show とも 404', function (): void {
    [, $owner] = createOrganizationWithOwner();
    [, , $otherProject] = browsingContext();
    $otherManual = VideoManual::factory()->forProject($otherProject)->create(['status' => 'ready']);

    $this->actingAs($owner)->get("/app/projects/{$otherProject->id}/manuals")->assertNotFound();
    $this->actingAs($owner)->get("/app/projects/{$otherProject->id}/manuals/{$otherManual->id}")->assertNotFound();
});

test('撮影者 (project_member) も org member (非 project member) も閲覧はできる', function (): void {
    [$organization, , $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member);
    $this->actingAs($member)->get("/app/projects/{$project->id}/manuals/{$manual->id}")->assertOk();

    $orgMember = attachOrganizationMember($organization);
    $orgMember->forceFill(['current_organization_id' => $organization->id])->save();
    $this->actingAs($orgMember)->get("/app/projects/{$project->id}/manuals/{$manual->id}")->assertOk();
});

test('PC ルート (/projects/...) の manual 詳細は影響を受けない (回帰)', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}")->assertOk();
});
