<?php

declare(strict_types=1);

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Enums\Manual\TakeStatus;
use App\Enums\ProjectRole;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * PC テイク選択画面からのテイク操作。
 *
 * PC 面は**新しい API 面を持たない** — 採用・削除・アップロード・再生はすべて
 * 撮影 PWA と共用の capture.takes.* を叩く。本テストが固定するのは 2 つ:
 *
 *   1. 編集者 (org owner / project_admin) が capture.takes.* を実行できること
 *      (PC 導線でも認可が通る)
 *   2. **撮影者 (project_member) も capture.takes.adopt を実行できること**
 *      = 画面 (projects.manuals.cuts.takes.index) は 403 だが API は開いている、という
 *      意図的な非対称。**この test が消えたら非対称が事故で壊れたと分かる**
 *      (撮影者の採用は doc/10 §10.5 の確定仕様)。
 */

function pcTakePath(Organization $organization, Project $project, VideoManual $manual, Cut $cut, ?Take $take = null, string $suffix = ''): string
{
    $base = "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes";

    return $take === null ? $base.$suffix : "{$base}/{$take->id}{$suffix}";
}

test('編集者 (org owner) は adopt を実行でき、採用が反映される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();

    $this->actingAs($owner)
        ->postJson(pcTakePath($organization, $project, $manual, $cut, $take, '/adopt'))
        ->assertOk();

    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);
});

test('編集者 (project_admin) も adopt / destroy を実行できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);

    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();

    $this->actingAs($editor)
        ->postJson(pcTakePath($organization, $project, $manual, $cut, $take, '/adopt'))
        ->assertOk();
    $this->actingAs($editor)
        ->deleteJson(pcTakePath($organization, $project, $manual, $cut, $take))
        ->assertNoContent();

    expect(Take::query()->whereKey($take->id)->exists())->toBeFalse();
});

test('編集者は presigned upload-url を発行できる (PC からのファイル追加の入口)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();

    // S3 は叩かない (presign は fake 値を返す container mock に差し替える)
    $storage = Mockery::mock(TakeObjectStorage::class);
    $storage->shouldReceive('presignUpload')->andReturn(new PresignedUploadData(
        url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
        headers: ['Content-Type' => 'video/mp4', 'x-amz-checksum-sha256' => 'fake='],
        expiresAt: CarbonImmutable::now()->addMinutes(30),
    ));
    app()->instance(TakeObjectStorage::class, $storage);

    $this->actingAs($owner)
        ->postJson(pcTakePath($organization, $project, $manual, $cut, null, '/upload-url'), [
            'client_take_id' => (string) Str::ulid(),
            'size_bytes' => 1_000_000,
            'content_type' => 'video/mp4',
            'checksum_sha256' => base64_encode(hash('sha256', 'blob', true)),
        ])
        ->assertOk()
        ->assertJsonStructure(['upload_url', 'headers', 'ticket', 'client_take_id', 'expires_at']);
});

test('**撮影者 (project_member) も adopt を実行できる** (画面は 403 だが API は開いている)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $shooter = attachOrganizationMember($organization);
    attachProjectMember($project, $shooter, ProjectRole::Member);

    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();

    // 画面は編集者限定 (403)
    $this->actingAs($shooter)
        ->get("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes")
        ->assertForbidden();

    // API は撮影者にも開いている (PWA の採用導線。doc/10 §10.5)
    $this->actingAs($shooter)
        ->postJson(pcTakePath($organization, $project, $manual, $cut, $take, '/adopt'))
        ->assertOk();

    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);
});

test('rendering 中の adopt は 409 (画面の事前告知と同じ理由)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'rendering']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();

    $this->actingAs($owner)
        ->postJson(pcTakePath($organization, $project, $manual, $cut, $take, '/adopt'))
        ->assertStatus(409);
});

test('ready でないテイクの adopt は 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['status' => TakeStatus::Processing->value]);

    $this->actingAs($owner)
        ->postJson(pcTakePath($organization, $project, $manual, $cut, $take, '/adopt'))
        ->assertStatus(422);
});

test('DL 済みテイクの削除は 422 (画面はサーバ文言をそのまま出す)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->downloaded()->create();

    $this->actingAs($owner)
        ->deleteJson(pcTakePath($organization, $project, $manual, $cut, $take))
        ->assertStatus(422);

    expect(Take::query()->whereKey($take->id)->exists())->toBeTrue();
});

test('編集者は presigned 発行の続き (POST takes = 登録) まで通せる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();

    // upload-url が作る予約行と、それに対応する署名チケットを用意する
    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();
    $reservation->refresh(); // DB 保存後の秒精度 expires_at で claims を作る
    $ticket = app(UploadTicketCodec::class)->seal(UploadTicketClaims::fromReservation($reservation));

    // S3 は叩かない (HeadObject は予約行と一致する値を返す container mock)
    $storage = Mockery::mock(TakeObjectStorage::class);
    $storage->shouldReceive('headObject')->with($reservation->video_path)->andReturn(new ObjectMetadataData(
        contentLength: $reservation->size_bytes,
        contentType: $reservation->content_type,
        checksumSha256: $reservation->checksum_sha256,
    ));
    app()->instance(TakeObjectStorage::class, $storage);

    $this->actingAs($owner)
        ->postJson(pcTakePath($organization, $project, $manual, $cut), [
            'ticket' => $ticket,
            'client_take_id' => $reservation->client_take_id,
            'duration_ms' => 5_000,
            'captured_at' => now()->toIso8601String(),
        ])
        ->assertCreated()
        // PC 画面は署名 URL を受け取らない (再生は playback の 302 経由のみ)
        ->assertJsonPath('playback_url', null)
        ->assertJsonPath('download_ack_token', null);

    expect($cut->takes()->where('client_take_id', $reservation->client_take_id)->exists())->toBeTrue();
});

test('編集者は playback / thumbnail の 302 を受け取れる (画面の video と img の出所)', function (): void {
    enableFakeStorage();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->withThumbnail()->create();

    $playback = $this->actingAs($owner)->get(pcTakePath($organization, $project, $manual, $cut, $take, '/playback'));
    $playback->assertStatus(302);
    expect($playback->headers->get('Cache-Control'))->toContain('no-store');

    $thumbnail = $this->actingAs($owner)->get(pcTakePath($organization, $project, $manual, $cut, $take, '/thumbnail'));
    $thumbnail->assertStatus(302);
    expect($thumbnail->headers->get('Location'))->toContain(urlencode((string) $take->thumbnail_path));
});

test('analyzing 中の adopt も 409 (rendering と同じ扱い)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();

    $this->actingAs($owner)
        ->postJson(pcTakePath($organization, $project, $manual, $cut, $take, '/adopt'))
        ->assertStatus(409);
});
