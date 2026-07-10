<?php

declare(strict_types=1);

use App\DataTransferObjects\Capture\DownloadAckClaims;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Enums\Manual\TakeStatus;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\UploadTicketCodec;
use Illuminate\Support\Facades\Queue;

/*
 * テイク管理 (施策6): adopt / PATCH / DELETE / DL 済み ACK。
 * adopted_take_id の書き込みは VideoManual 行ロック tx 内のみ (検出 4 が固定)。
 */

/**
 * @return array{Organization, User, Project, VideoManual, Cut, Take}
 */
function takeManagementContext(string $manualStatus = 'ready'): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => $manualStatus]);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();

    return [$organization, $owner, $project, $manual, $cut, $take];
}

function takePath(Project $project, VideoManual $manual, Cut $cut, Take $take, string $suffix = ''): string
{
    return "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}{$suffix}";
}

/** 署名 ACK トークン (概念設計 D6) を発行する */
function sealAckToken(Take $take, User $user, ?int $expiresAt = null): string
{
    return app(UploadTicketCodec::class)->sealAck(new DownloadAckClaims(
        takeId: $take->id,
        userId: $user->id,
        expiresAtTimestamp: $expiresAt ?? now()->addHour()->getTimestamp(),
    ));
}

// ---------- adopt ----------

test('adopt: ready テイクを採用でき adopted_take_id が反映される', function (): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();

    $response = $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'));

    $response->assertOk();
    $response->assertJsonPath('adopted_take_id', $take->id);
    $response->assertJsonPath('id', $cut->id);
    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);
});

test('adopt: cross-cut (cut B の take を cut A の URL で) は 404', function (): void {
    [, $owner, $project, $manual, $cut] = takeManagementContext();
    $cutB = Cut::factory()->forManual($manual)->create();
    $takeB = Take::factory()->forCut($cutB)->create();

    $this->actingAs($owner)
        ->postJson(takePath($project, $manual, $cut, $takeB, '/adopt'))
        ->assertNotFound();
    expect($cut->fresh()?->adopted_take_id)->toBeNull();
});

test('adopt: ready 前 (uploading/processing/failed) のテイクは 422', function (string $status): void {
    [, $owner, $project, $manual, $cut] = takeManagementContext();
    $take = Take::factory()->forCut($cut)->create(['status' => $status]);

    $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'))->assertStatus(422);
})->with(['uploading', 'processing', 'failed']);

test('adopt: manual が analyzing / rendering 中は 409 scenario_conflict', function (string $status): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext($status);

    $response = $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'));

    $response->assertStatus(409);
    $response->assertJsonPath('code', 'scenario_conflict');
})->with(['analyzing', 'rendering']);

test('adopt: cross-org は 404', function (): void {
    [, , $project, $manual, $cut, $take] = takeManagementContext();
    [, $otherOwner] = createOrganizationWithOwner();

    $this->actingAs($otherOwner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'))->assertNotFound();
});

// ---------- PATCH (comment / position) ----------

test('PATCH: comment を更新できる (null 送信でクリア)', function (): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();

    $this->actingAs($owner)
        ->patchJson(takePath($project, $manual, $cut, $take), ['comment' => '手ブレあり'])
        ->assertOk()
        ->assertJsonPath('comment', '手ブレあり');
    expect($take->fresh()?->comment)->toBe('手ブレあり');

    $this->actingAs($owner)
        ->patchJson(takePath($project, $manual, $cut, $take), ['comment' => null])
        ->assertOk()
        ->assertJsonPath('comment', null);
    expect($take->fresh()?->comment)->toBeNull();
});

test('PATCH: position でサーバ再採番の並べ替えができる', function (): void {
    [, $owner, $project, $manual, $cut, $first] = takeManagementContext();
    $first->forceFill(['sort_order' => 0])->save();
    $second = Take::factory()->forCut($cut)->create(['sort_order' => 1]);
    $third = Take::factory()->forCut($cut)->create(['sort_order' => 2]);

    // 末尾の take を先頭へ
    $response = $this->actingAs($owner)->patchJson(takePath($project, $manual, $cut, $third), ['position' => 0]);

    $response->assertOk();
    $response->assertJsonPath('sort_order', 0);
    expect($third->fresh()?->sort_order)->toBe(0);
    expect($first->fresh()?->sort_order)->toBe(1);
    expect($second->fresh()?->sort_order)->toBe(2);
});

test('PATCH: position が末尾超過なら末尾に丸める', function (): void {
    [, $owner, $project, $manual, $cut, $first] = takeManagementContext();
    $first->forceFill(['sort_order' => 0])->save();
    $second = Take::factory()->forCut($cut)->create(['sort_order' => 1]);

    $this->actingAs($owner)->patchJson(takePath($project, $manual, $cut, $first), ['position' => 99])->assertOk();

    expect($second->fresh()?->sort_order)->toBe(0);
    expect($first->fresh()?->sort_order)->toBe(1);
});

test('PATCH: sort_order の直送は 422 (サーバ採番)', function (): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();

    $response = $this->actingAs($owner)->patchJson(takePath($project, $manual, $cut, $take), ['sort_order' => 0]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['sort_order']);
});

// ---------- DELETE ----------

test('DELETE: 204 + DeleteTakeObjectsJob が media queue へ dispatch される', function (): void {
    Queue::fake();
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();

    $response = $this->actingAs($owner)->deleteJson(takePath($project, $manual, $cut, $take));

    $response->assertNoContent();
    expect($cut->takes()->count())->toBe(0);
    Queue::assertPushed(DeleteTakeObjectsJob::class, function (DeleteTakeObjectsJob $job) use ($take): bool {
        return $job->paths === [$take->video_path] && $job->connection === 'database-media';
    });
});

test('DELETE: DL 済み (downloaded_at 非 null) のテイクは 422 で削除不可', function (): void {
    Queue::fake();
    [, $owner, $project, $manual, $cut] = takeManagementContext();
    $take = Take::factory()->forCut($cut)->downloaded()->create();

    $this->actingAs($owner)->deleteJson(takePath($project, $manual, $cut, $take))->assertStatus(422);

    expect($take->fresh())->not->toBeNull();
    Queue::assertNothingPushed();
});

test('DELETE: 採用中テイクの削除で adopted_take_id が null 化される', function (): void {
    Queue::fake();
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();
    $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'))->assertOk();
    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);

    $this->actingAs($owner)->deleteJson(takePath($project, $manual, $cut, $take))->assertNoContent();

    expect($cut->fresh()?->adopted_take_id)->toBeNull();
});

test('DELETE: 削除後に残テイクの sort_order が詰め直される', function (): void {
    Queue::fake();
    [, $owner, $project, $manual, $cut, $first] = takeManagementContext();
    $first->forceFill(['sort_order' => 0])->save();
    $second = Take::factory()->forCut($cut)->create(['sort_order' => 1]);
    $third = Take::factory()->forCut($cut)->create(['sort_order' => 2]);

    $this->actingAs($owner)->deleteJson(takePath($project, $manual, $cut, $first))->assertNoContent();

    expect($second->fresh()?->sort_order)->toBe(0);
    expect($third->fresh()?->sort_order)->toBe(1);
});

// ---------- DL 済み ACK ----------

test('downloaded: 有効な ACK トークンで打刻され、再送は冪等 (timestamp 不変)', function (): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();
    $token = sealAckToken($take, $owner);

    $response = $this->actingAs($owner)->postJson(
        takePath($project, $manual, $cut, $take, '/downloaded'),
        ['ack_token' => $token],
    );

    $response->assertOk();
    $response->assertJsonPath('downloaded', true);
    $firstStamp = $take->fresh()?->downloaded_at;
    expect($firstStamp)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->actingAs($owner)->postJson(
        takePath($project, $manual, $cut, $take, '/downloaded'),
        ['ack_token' => $token],
    )->assertOk();

    expect($take->fresh()?->downloaded_at?->equalTo($firstStamp))->toBeTrue();
});

test('downloaded: トークン不正 / 期限切れ / take 不一致 / 別ユーザ / チケット流用は 422', function (): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();
    $path = takePath($project, $manual, $cut, $take, '/downloaded');

    // 復号不能
    $this->actingAs($owner)->postJson($path, ['ack_token' => 'garbage'])->assertStatus(422);

    // 期限切れ
    $expired = sealAckToken($take, $owner, now()->subMinute()->getTimestamp());
    $this->actingAs($owner)->postJson($path, ['ack_token' => $expired])->assertStatus(422);

    // 別 take のトークン
    $otherTake = Take::factory()->forCut($cut)->create();
    $mismatch = sealAckToken($otherTake, $owner);
    $this->actingAs($owner)->postJson($path, ['ack_token' => $mismatch])->assertStatus(422);

    // 別ユーザ宛てのトークン
    $someoneElse = User::factory()->create();
    $foreign = sealAckToken($take, $someoneElse);
    $this->actingAs($owner)->postJson($path, ['ack_token' => $foreign])->assertStatus(422);

    // アップロードチケットの流用 (payload 種別キーで拒否)
    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();
    $uploadTicket = app(UploadTicketCodec::class)->seal(UploadTicketClaims::fromReservation($reservation->refresh()));
    $this->actingAs($owner)->postJson($path, ['ack_token' => $uploadTicket])->assertStatus(422);

    expect($take->fresh()?->downloaded_at)->toBeNull();
});

test('downloaded: 採用変更後も DL 時トークンで ACK できる (race 解消)', function (): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();
    $token = sealAckToken($take, $owner); // DL URL 発行時のトークン

    // DL 完了までの間に別テイクが採用される
    $newlyAdopted = Take::factory()->forCut($cut)->create();
    $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $newlyAdopted, '/adopt'))->assertOk();

    $this->actingAs($owner)
        ->postJson(takePath($project, $manual, $cut, $take, '/downloaded'), ['ack_token' => $token])
        ->assertOk();
    expect($take->fresh()?->downloaded_at)->not->toBeNull();
});

// ---------- 権限 ----------

test('update/delete/adopt/downloaded: 非 project member の org member は 403', function (): void {
    [$organization, , $project, $manual, $cut, $take] = takeManagementContext();
    $outsider = attachOrganizationMember($organization);
    $outsider->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($outsider)->patchJson(takePath($project, $manual, $cut, $take), ['comment' => 'x'])->assertForbidden();
    $this->actingAs($outsider)->deleteJson(takePath($project, $manual, $cut, $take))->assertForbidden();
    $this->actingAs($outsider)->postJson(takePath($project, $manual, $cut, $take, '/adopt'))->assertForbidden();
    $this->actingAs($outsider)->postJson(
        takePath($project, $manual, $cut, $take, '/downloaded'),
        ['ack_token' => sealAckToken($take, $outsider)],
    )->assertForbidden();
});

test('adopt 済み状態は CaptureCutResource の takes にも反映される', function (): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();

    $response = $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'));

    $response->assertOk();
    $response->assertJsonPath('takes.0.id', $take->id);
    $response->assertJsonPath('takes.0.status', TakeStatus::Ready->value);
    // adopt 応答では playback_url / download_ack_token は常に null (詳細 GET のみ設定)
    $response->assertJsonPath('takes.0.playback_url', null);
    $response->assertJsonPath('takes.0.download_ack_token', null);
});
