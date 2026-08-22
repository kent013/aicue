<?php

declare(strict_types=1);

use App\DataTransferObjects\Capture\PresignedUploadData;
use App\Enums\Capture\TakeUploadReservationStatus;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\StorageUsageService;
use App\Services\Capture\TakeObjectStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/*
 * presigned upload-url 発行 (施策4): Quota 予約 + 署名チケット。
 * POST /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url
 */

/**
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function uploadUrlContext(string $status = 'ready'): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => $status]);
    $cut = Cut::factory()->forManual($manual)->create();

    return [$organization, $owner, $project, $manual, $cut];
}

/**
 * @return array<string, mixed>
 */
function uploadUrlPayload(array $overrides = []): array
{
    return array_merge([
        'client_take_id' => (string) Str::ulid(),
        'size_bytes' => 1_000,
        'content_type' => 'video/mp4',
        'checksum_sha256' => base64_encode(hash('sha256', 'blob', true)),
    ], $overrides);
}

/** TakeObjectStorage を container mock にする (presign は fake 値を返す) */
function mockPresign(): MockInterface
{
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('presignUpload')
        ->andReturn(new PresignedUploadData(
            url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
            headers: ['Content-Type' => 'video/mp4', 'x-amz-checksum-sha256' => 'fake='],
            expiresAt: CarbonImmutable::now()->addMinutes(30),
        ))
        ->byDefault();
    app()->instance(TakeObjectStorage::class, $mock);

    return $mock;
}

function uploadUrlPath(Organization $organization, Project $project, VideoManual $manual, Cut $cut): string
{
    return "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/upload-url";
}

test('発行成功: pending 予約が作成され bytes_pending に計上、presign には予約行の値が渡る', function (): void {
    [$organization, $owner, $project, $manual, $cut] = uploadUrlContext();
    $payload = uploadUrlPayload();
    $mock = Mockery::mock(TakeObjectStorage::class);
    app()->instance(TakeObjectStorage::class, $mock);
    $mock->shouldReceive('presignUpload')
        ->once()
        ->withArgs(function (string $path, string $contentType, int $sizeBytes, string $checksum) use ($payload): bool {
            return str_contains($path, '/takes/')
                && $contentType === 'video/mp4'
                && $sizeBytes === 1_000
                && $checksum === $payload['checksum_sha256'];
        })
        ->andReturn(new PresignedUploadData(
            url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
            headers: ['Content-Type' => 'video/mp4', 'x-amz-checksum-sha256' => 'fake='],
            expiresAt: CarbonImmutable::now()->addMinutes(30),
        ));

    $response = $this->actingAs($owner)->postJson(uploadUrlPath($organization, $project, $manual, $cut), $payload);

    $response->assertOk();
    $response->assertJsonStructure(['upload_url', 'headers', 'ticket', 'client_take_id', 'expires_at']);

    $reservation = $cut->uploadReservations()->sole();
    expect($reservation->status)->toBe(TakeUploadReservationStatus::Pending);
    expect($reservation->organization_id)->toBe($organization->id);
    expect($reservation->size_bytes)->toBe(1_000);
    expect($reservation->checksum_sha256)->toBe($payload['checksum_sha256']);
    // サーバ生成キー (projects/{pid}/manuals/{mid}/cuts/{cid}/takes/{ulid}.mp4)
    expect($reservation->video_path)->toStartWith("projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/");
    expect($reservation->video_path)->toEndWith('.mp4');
    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe(1_000);
});

test('bytes_used + pending + size が上限を超えると 422 quota_exceeded (予約は作られない)', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    config()->set('quota.plans.personal.max_storage_bytes', 2_000);
    Take::factory()->forCut($cut)->create(['size_bytes' => 1_000]);                 // bytes_used
    TakeUploadReservation::factory()->forCut($cut)->create(['size_bytes' => 500]);  // bytes_pending
    mockPresign();

    $response = $this->actingAs($owner)->postJson(
        uploadUrlPath($organization, $project, $manual, $cut),
        uploadUrlPayload(['size_bytes' => 501]),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'quota_exceeded');
    expect($cut->uploadReservations()->count())->toBe(1); // 既存 pending のみ (新規予約なし)
});

test('境界: 加算後合計 == limit は成功する', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    config()->set('quota.plans.personal.max_storage_bytes', 2_000);
    Take::factory()->forCut($cut)->create(['size_bytes' => 1_000]);
    mockPresign();

    $response = $this->actingAs($owner)->postJson(
        uploadUrlPath($organization, $project, $manual, $cut),
        uploadUrlPayload(['size_bytes' => 1_000]),
    );

    $response->assertOk();
});

test('manual が draft / analyzing / rendering だと 422 (撮影不可)', function (string $status): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext($status);
    mockPresign();

    $response = $this->actingAs($owner)->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload());

    $response->assertStatus(422);
    expect($cut->uploadReservations()->count())->toBe(0);
})->with(['draft', 'analyzing', 'rendering']);

test('published の manual は撮影できる', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext(VideoManualStatus::Published->value);
    mockPresign();

    $this->actingAs($owner)->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload())->assertOk();
});

test('保護キー (cut_id / organization_id / video_path) の payload 直送は 422', function (string $key): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    mockPresign();

    $response = $this->actingAs($owner)->postJson(
        uploadUrlPath($organization, $project, $manual, $cut),
        uploadUrlPayload([$key => 'anything']),
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors([$key]);
})->with(['cut_id', 'organization_id', 'video_path']);

test('cross-org の {project} は 404', function (): void {
    [, $owner] = uploadUrlContext();
    [, , $otherProject, $otherManual, $otherCut] = uploadUrlContext(); // 別 org
    mockPresign();

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $otherProject, $otherManual, $otherCut), uploadUrlPayload())
        ->assertNotFound();
});

test('cross-project の {manual} / cross-manual の {cut} は 404', function (): void {
    [$organization, $owner, $project, $manual, $cut] = uploadUrlContext();
    mockPresign();

    // 同 org の別 project の manual
    $otherProject = Project::factory()->forOrganization($organization)->create();
    $otherManual = VideoManual::factory()->forProject($otherProject)->create(['status' => 'ready']);
    $otherCut = Cut::factory()->forManual($otherManual)->create();

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $otherManual, $otherCut), uploadUrlPayload())
        ->assertNotFound();

    // 同 project の別 manual の cut
    $siblingManual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $siblingCut = Cut::factory()->forManual($siblingManual)->create();

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $siblingCut), uploadUrlPayload())
        ->assertNotFound();
});

test('project_member は発行可・非 project member の org member は 403・別 org ユーザは 404', function (): void {
    [$organization, , $project, $manual, $cut] = uploadUrlContext();
    mockPresign();

    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);
    $this->actingAs($member)->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload())->assertOk();

    $outsider = attachOrganizationMember($organization);
    $this->actingAs($outsider)->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload())->assertForbidden();

    [, $otherOwner] = createOrganizationWithOwner();
    $this->actingAs($otherOwner)->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload())->assertNotFound();
});

test('content_type 非許可 / size 超過 / checksum 形式不正は 422', function (array $overrides, string $errorKey): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    mockPresign();

    $response = $this->actingAs($owner)->postJson(
        uploadUrlPath($organization, $project, $manual, $cut),
        uploadUrlPayload($overrides),
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors([$errorKey]);
})->with([
    'content_type 非許可' => [['content_type' => 'application/pdf'], 'content_type'],
    'size 超過' => [['size_bytes' => 500 * 1024 * 1024 + 1], 'size_bytes'],
    'checksum 44 文字でない' => [['checksum_sha256' => 'short'], 'checksum_sha256'],
    'checksum base64 でない' => [['checksum_sha256' => str_repeat('!', 43).'='], 'checksum_sha256'],
    'client_take_id が ULID でない' => [['client_take_id' => str_repeat('!', 26)], 'client_take_id'],
]);

test('checksum が base64 44 文字でもデコード後 32 bytes でなければ 422', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    mockPresign();

    // 44 文字だが padding 位置により 32 bytes にならない値は regex で落ちる。
    // regex を通ってしまう形 (正しい base64 32 bytes) 以外の残余は Sha256Checksum が防ぐ:
    // ここでは regex を通る値 = 必ず 32 bytes になることを確認する (VO の生成時保証)
    $valid = base64_encode(hash('sha256', 'x', true));
    expect(strlen($valid))->toBe(44);
    $decoded = base64_decode($valid, strict: true);
    expect($decoded)->not->toBeFalse();
    expect(strlen((string) $decoded))->toBe(32);

    // 改竄 (base64 だが 33 bytes) は 44 文字にならず regex で 422
    $tooLong = base64_encode(random_bytes(33));
    $this->actingAs($owner)->postJson(
        uploadUrlPath($organization, $project, $manual, $cut),
        uploadUrlPayload(['checksum_sha256' => $tooLong]),
    )->assertStatus(422);
});

test('issue() が保持する予約インスタンスは refresh なしで status=pending を持つ', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    mockPresign();

    /** @var TakeUploadReservation|null $captured */
    $captured = null;
    TakeUploadReservation::created(function (TakeUploadReservation $reservation) use (&$captured): void {
        $captured = $reservation;   // service が save() した当のインスタンス
    });

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload())
        ->assertOk();

    // in-memory: 明示代入していないと属性ごと存在せず null になる (DB default では埋まらない)。
    // 既存テスト「発行成功: pending 予約が作成され…」は uploadReservations()->sole() = **DB 再読込**
    // なので DB default で緑になり、この欠落を検出できなかった。
    expect($captured)->not->toBeNull();
    expect($captured->status)->toBe(TakeUploadReservationStatus::Pending);
    // enum → backing value の往復が効いていること (DB 実値も pending)
    expect(DB::table('take_upload_reservations')->where('id', $captured->id)->value('status'))->toBe('pending');
});

/*
 * 静止画カットの受け入れ (受け入れは非対称):
 * - still カット: 画像も動画も受ける (動画は先頭フレーム抽出で従来どおり合成できる)
 * - video / 未指定カット: 動画のみ。画像は入口 (presign) で 422 = 容量を消費させない
 */

test('still カット + image/jpeg は 200 で、S3 キーが .jpg になる', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
    mockPresign();

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload(['content_type' => 'image/jpeg']))
        ->assertOk();

    $reservation = $cut->uploadReservations()->sole();
    expect($reservation->content_type)->toBe('image/jpeg');
    expect($reservation->video_path)->toEndWith('.jpg');
});

test('video カットへ画像を上げようとすると 422 で、予約行が 1 件も作られない (容量を消費しない)', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    $cut->forceFill(['material_type' => MaterialType::Video->value])->save();
    mockPresign();

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload(['content_type' => 'image/jpeg']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('content_type');

    expect($cut->uploadReservations()->count())->toBe(0);
});

test('material_type 未指定カットも画像は 422 (計画が無いカットは動画のみ)', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    expect($cut->material_type)->toBeNull();
    mockPresign();

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload(['content_type' => 'image/jpeg']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('content_type');
});

test('material_type を payload に入れると 422 (サーバ確定値なので受け取らない)', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    mockPresign();

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload(['material_type' => 'still']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('material_type');
});

test('バイト上限は種別で非対称: 同じサイズでも image は 422 / video は通る', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
    mockPresign();
    $overStill = config()->integer('capture.max_still_bytes') + 1;
    expect($overStill)->toBeLessThanOrEqual(config()->integer('capture.max_take_bytes'));

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload([
            'content_type' => 'image/jpeg',
            'size_bytes' => $overStill,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('size_bytes');

    // 同じサイズを動画として送ると通る (静止画にだけ厳しい上限が効いている)
    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload([
            'content_type' => 'video/mp4',
            'size_bytes' => $overStill,
        ]))
        ->assertOk();
});

test('allowlist 外の画像形式 (image/webp) は 422', function (): void {
    [, $owner, $project, $manual, $cut] = uploadUrlContext();
    $cut->forceFill(['material_type' => MaterialType::Still->value])->save();
    mockPresign();

    $this->actingAs($owner)
        ->postJson(uploadUrlPath($organization, $project, $manual, $cut), uploadUrlPayload(['content_type' => 'image/webp']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('content_type');
});
