<?php

declare(strict_types=1);

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Enums\Capture\TakeUploadReservationStatus;
use App\Enums\Manual\TakeStatus;
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
use App\Services\Capture\UploadTicketCodec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/*
 * テイク登録 (施策5): チケット検証 + HeadObject 三点照合 + (cut_id, client_take_id) 冪等。
 * POST /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes
 */

/**
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function registrationContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();

    return [$organization, $owner, $project, $manual, $cut];
}

/**
 * @return array{TakeUploadReservation, string}
 */
function reservationWithTicket(Cut $cut, array $attributes = []): array
{
    $reservation = TakeUploadReservation::factory()->forCut($cut)->create($attributes);
    $reservation->refresh(); // DB 保存後の秒精度 expires_at で claims を作る
    $ticket = app(UploadTicketCodec::class)->seal(UploadTicketClaims::fromReservation($reservation));

    return [$reservation, $ticket];
}

/** 予約行と一致する HeadObject 応答を返す storage mock */
function mockHeadObjectMatching(TakeUploadReservation $reservation): MockInterface
{
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('headObject')
        ->with($reservation->video_path)
        ->andReturn(new ObjectMetadataData(
            contentLength: $reservation->size_bytes,
            contentType: $reservation->content_type,
            checksumSha256: $reservation->checksum_sha256,
        ))
        ->byDefault();
    app()->instance(TakeObjectStorage::class, $mock);

    return $mock;
}

function takesPath(Project $project, VideoManual $manual, Cut $cut): string
{
    return "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes";
}

/**
 * @return array<string, mixed>
 */
function takesPayload(TakeUploadReservation $reservation, string $ticket, array $overrides = []): array
{
    return array_merge([
        'ticket' => $ticket,
        'client_take_id' => $reservation->client_take_id,
        'duration_ms' => 5_000,
        'captured_at' => now()->toIso8601String(),
    ], $overrides);
}

test('正常登録: 201・status=ready・sort_order 先頭 (既存 +1)・予約 completed・bytes_pending 減', function (): void {
    [$organization, $owner, $project, $manual, $cut] = registrationContext();
    $existingTake = Take::factory()->forCut($cut)->create(['sort_order' => 0]);
    [$reservation, $ticket] = reservationWithTicket($cut);
    mockHeadObjectMatching($reservation);

    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe($reservation->size_bytes);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertCreated();
    $response->assertJsonPath('client_take_id', $reservation->client_take_id);
    $response->assertJsonPath('status', 'ready');
    $response->assertJsonPath('sort_order', 0);
    $response->assertJsonPath('playback_url', null);
    $response->assertJsonPath('download_ack_token', null);

    $take = $cut->takes()->where('client_take_id', $reservation->client_take_id)->sole();
    expect($take->status)->toBe(TakeStatus::Ready);
    expect($take->video_path)->toBe($reservation->video_path);
    expect($take->size_bytes)->toBe($reservation->size_bytes);
    expect($take->sort_order)->toBe(0);
    expect($existingTake->fresh()?->sort_order)->toBe(1);
    expect($reservation->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed);
    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe(0);
});

test('チケット改竄 (復号不能な文字列) は 422', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation] = reservationWithTicket($cut);
    mockHeadObjectMatching($reservation);

    $response = $this->actingAs($owner)->postJson(
        takesPath($project, $manual, $cut),
        takesPayload($reservation, 'tampered-ticket-value'),
    );

    $response->assertStatus(422);
    expect($cut->takes()->count())->toBe(0);
});

test('別 cut への流用 (cut A で発行 → cut B の URL に POST) は 404', function (): void {
    [, $owner, $project, $manual, $cutA] = registrationContext();
    $cutB = Cut::factory()->forManual($manual)->create();
    [$reservation, $ticket] = reservationWithTicket($cutA);
    mockHeadObjectMatching($reservation);

    $this->actingAs($owner)
        ->postJson(takesPath($project, $manual, $cutB), takesPayload($reservation, $ticket))
        ->assertNotFound();
});

test('HeadObject 不存在 (期限内) は 422 + 予約は pending へ戻る (再送可能・Quota 占有継続)', function (): void {
    [$organization, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('headObject')->andReturnNull();
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertStatus(422);
    expect($reservation->fresh()?->status)->toBe(TakeUploadReservationStatus::Pending);
    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe($reservation->size_bytes);
});

test('HeadObject 不存在 + claim 後に期限超過した予約は released へ倒す', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $mock = Mockery::mock(TakeObjectStorage::class);
    // claim 成功後・照合前に期限が切れたケースを clock 前進で再現する
    $mock->shouldReceive('headObject')->andReturnUsing(function () {
        Carbon::setTestNow(now()->addHours(2));

        return null;
    });
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertStatus(422);
    expect($reservation->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
    Carbon::setTestNow();
});

test('三点照合 (size / content_type / checksum) 不一致は削除 + 予約 released + 422', function (array $meta): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('headObject')->andReturn(new ObjectMetadataData(
        contentLength: $meta['size'] ?? $reservation->size_bytes,
        contentType: array_key_exists('type', $meta) ? $meta['type'] : $reservation->content_type,
        checksumSha256: array_key_exists('checksum', $meta) ? $meta['checksum'] : $reservation->checksum_sha256,
    ));
    $mock->shouldReceive('delete')->once()->with($reservation->video_path);
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertStatus(422);
    expect($reservation->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
    expect($cut->takes()->count())->toBe(0);
})->with([
    'size 不一致' => [['size' => 999_999]],
    'content_type 不一致' => [['type' => 'video/webm']],
    'checksum 不一致' => [['checksum' => base64_encode(hash('sha256', 'different', true))]],
]);

test('ContentType が HeadObject で欠落 (null) する互換実装では照合をスキップして登録できる', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('headObject')->andReturn(new ObjectMetadataData(
        contentLength: $reservation->size_bytes,
        contentType: null,
        checksumSha256: $reservation->checksum_sha256,
    ));
    app()->instance(TakeObjectStorage::class, $mock);

    $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))->assertCreated();
});

test('completed チケット再送 (同一 path の既存 Take): 200 既存返却 + delete は呼ばれない', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $reservation->forceFill(['status' => TakeUploadReservationStatus::Completed])->save();
    $existing = Take::factory()->forCut($cut)->create([
        'client_take_id' => $reservation->client_take_id,
        'video_path' => $reservation->video_path,
    ]);
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldNotReceive('delete');
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertOk();
    $response->assertJsonPath('id', $existing->id);
    expect($cut->takes()->count())->toBe(1);
});

test('別 pending 予約による重複 (既存 Take と別 path): 200 既存 + その予約のみ released + そのオブジェクトのみ削除', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    $clientTakeId = (string) Str::ulid();
    $existing = Take::factory()->forCut($cut)->create([
        'client_take_id' => $clientTakeId,
        'video_path' => 'takes/existing.mp4',
    ]);
    [$reservation, $ticket] = reservationWithTicket($cut, ['client_take_id' => $clientTakeId]);
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('delete')->once()->with($reservation->video_path);
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertOk();
    $response->assertJsonPath('id', $existing->id);
    expect($reservation->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
    expect($existing->fresh())->not->toBeNull(); // 既存 Take は無傷
});

test('completed なのに Take 不在は 409 reservation_inconsistent (削除なし)', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $reservation->forceFill(['status' => TakeUploadReservationStatus::Completed])->save();
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldNotReceive('delete');
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertStatus(409);
    $response->assertJsonPath('code', 'capture_conflict');
    $response->assertJsonPath('conflict_type', 'reservation_inconsistent');
});

test('completed だが既存 Take と path 矛盾は 409 (削除なし)', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    $clientTakeId = (string) Str::ulid();
    Take::factory()->forCut($cut)->create([
        'client_take_id' => $clientTakeId,
        'video_path' => 'takes/other-path.mp4',
    ]);
    [$reservation, $ticket] = reservationWithTicket($cut, ['client_take_id' => $clientTakeId]);
    $reservation->forceFill(['status' => TakeUploadReservationStatus::Completed])->save();
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldNotReceive('delete');
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertStatus(409);
    $response->assertJsonPath('conflict_type', 'reservation_inconsistent');
});

test('fresh verifying への再送は 409 registration_in_flight (処理中・リトライ可能)', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $reservation->forceFill(['status' => TakeUploadReservationStatus::Verifying])->save();
    mockHeadObjectMatching($reservation);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertStatus(409);
    $response->assertJsonPath('conflict_type', 'registration_in_flight');
});

test('released 予約 (Take 不在) への再送は 422 (upload-url 再取得を促す)', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $reservation->forceFill(['status' => TakeUploadReservationStatus::Released])->save();
    mockHeadObjectMatching($reservation);

    $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))->assertStatus(422);
});

test('期限切れチケットは 422', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    Carbon::setTestNow(now()->addHours(2)); // チケット/予約とも期限切れに

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertStatus(422);
    Carbon::setTestNow();
});

test('cut_id の payload 直送は 422 (protected)', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    mockHeadObjectMatching($reservation);

    $response = $this->actingAs($owner)->postJson(
        takesPath($project, $manual, $cut),
        takesPayload($reservation, $ticket, ['cut_id' => $cut->id]),
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['cut_id']);
});

test('cross-org 404 / 非 project member 403 / project_member は登録可', function (): void {
    [$organization, , $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    mockHeadObjectMatching($reservation);

    [, $otherOwner] = createOrganizationWithOwner();
    $this->actingAs($otherOwner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))->assertNotFound();

    $outsider = attachOrganizationMember($organization);
    $outsider->forceFill(['current_organization_id' => $organization->id])->save();
    $this->actingAs($outsider)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))->assertForbidden();

    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);
    $this->actingAs($member)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket))->assertCreated();
});

test('並行二重送信 (insert の unique 衝突) は冪等分岐へフォールバックし 200 既存を返す', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $mock = Mockery::mock(TakeObjectStorage::class);
    // HeadObject 中に並行リクエストが同 (cut, client_take_id) の Take を先に登録したケースを再現
    $mock->shouldReceive('headObject')->andReturnUsing(function () use ($cut, $reservation): ObjectMetadataData {
        Take::factory()->forCut($cut)->create([
            'client_take_id' => $reservation->client_take_id,
            'video_path' => 'takes/registered-by-concurrent-request.mp4',
        ]);

        return new ObjectMetadataData(
            contentLength: $reservation->size_bytes,
            contentType: $reservation->content_type,
            checksumSha256: $reservation->checksum_sha256,
        );
    });
    // rollback 後の冪等分岐: 本予約 (別 path・verifying) は released + そのオブジェクトのみ削除
    $mock->shouldReceive('delete')->once()->with($reservation->video_path);
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertOk();
    expect($cut->takes()->count())->toBe(1);
    expect($reservation->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
});

test('確定 CAS と sweeper の競合: 予約が released 済みなら Take は作成されず 422', function (): void {
    [, $owner, $project, $manual, $cut] = registrationContext();
    [$reservation, $ticket] = reservationWithTicket($cut);
    $mock = Mockery::mock(TakeObjectStorage::class);
    // claim 後・確定 tx 前に sweeper が released 化したケースを再現 (HeadObject 中に横取り)
    $mock->shouldReceive('headObject')->andReturnUsing(function () use ($reservation): ObjectMetadataData {
        TakeUploadReservation::query()->whereKey($reservation->id)
            ->update(['status' => TakeUploadReservationStatus::Released]);

        return new ObjectMetadataData(
            contentLength: $reservation->size_bytes,
            contentType: $reservation->content_type,
            checksumSha256: $reservation->checksum_sha256,
        );
    });
    app()->instance(TakeObjectStorage::class, $mock);

    $response = $this->actingAs($owner)->postJson(takesPath($project, $manual, $cut), takesPayload($reservation, $ticket));

    $response->assertStatus(422);
    expect($cut->takes()->count())->toBe(0);
    expect($reservation->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
});

test('claim 中 (verifying) の予約は bytesPending に計上され続け並行 upload-url が上限を超えない', function (): void {
    [$organization, $owner, $project, $manual, $cut] = registrationContext();
    config()->set('quota.plans.personal.max_storage_bytes', 1_000);
    [$reservation] = reservationWithTicket($cut, ['size_bytes' => 800]);
    $reservation->forceFill(['status' => TakeUploadReservationStatus::Verifying])->save();

    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe(800);

    // claim 中でも占有は継続 → 追加 300 bytes の upload-url は quota_exceeded
    $presign = Mockery::mock(TakeObjectStorage::class);
    app()->instance(TakeObjectStorage::class, $presign);
    $response = $this->actingAs($owner)->postJson(
        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/upload-url",
        [
            'client_take_id' => (string) Str::ulid(),
            'size_bytes' => 300,
            'content_type' => 'video/mp4',
            'checksum_sha256' => base64_encode(hash('sha256', 'x', true)),
        ],
    );

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'quota_exceeded');
});
