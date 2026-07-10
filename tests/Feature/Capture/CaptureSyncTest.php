<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Support\Str;

/*
 * 一括同期 (施策8): 照合専用 (書き込みゼロ)。未登録 fingerprint を pending_upload で返す。
 * POST /app/projects/{project}/manuals/{manual}/sync
 */

/**
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function syncContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();

    return [$organization, $owner, $project, $manual, $cut];
}

function syncPath(Project $project, VideoManual $manual): string
{
    return "/app/projects/{$project->id}/manuals/{$manual->id}/sync";
}

test('未登録 fingerprint のみ pending_upload で返り、登録済みは返らない', function (): void {
    [, $owner, $project, $manual, $cut] = syncContext();
    $registered = Take::factory()->forCut($cut)->create();
    $unregisteredId = (string) Str::ulid();

    $response = $this->actingAs($owner)->postJson(syncPath($project, $manual), [
        'takes' => [
            ['cut' => $cut->id, 'client_take_id' => $registered->client_take_id],
            ['cut' => $cut->id, 'client_take_id' => $unregisteredId],
        ],
    ]);

    $response->assertOk();
    $response->assertJsonCount(1, 'pending_upload');
    $response->assertJsonPath('pending_upload.0.cut', $cut->id);
    $response->assertJsonPath('pending_upload.0.client_take_id', $unregisteredId);
    $response->assertJsonPath('manual.id', $manual->id);
});

test('冪等: 同 payload 連続 2 回で同一応答・DB 書き込みゼロ', function (): void {
    [, $owner, $project, $manual, $cut] = syncContext();
    Take::factory()->forCut($cut)->create();
    $payload = ['takes' => [['cut' => $cut->id, 'client_take_id' => (string) Str::ulid()]]];

    $first = $this->actingAs($owner)->postJson(syncPath($project, $manual), $payload);
    $second = $this->actingAs($owner)->postJson(syncPath($project, $manual), $payload);

    $first->assertOk();
    $second->assertOk();
    expect($second->json('pending_upload'))->toBe($first->json('pending_upload'));
    $this->assertDatabaseCount('takes', 1);
    $this->assertDatabaseCount('take_upload_reservations', 0);
});

test('他 manual の cut id 混入は 404 (tenant キー不信・存在を漏らさない)', function (): void {
    [, $owner, $project, $manual] = syncContext();
    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $foreignCut = Cut::factory()->forManual($otherManual)->create();

    $this->actingAs($owner)->postJson(syncPath($project, $manual), [
        'takes' => [['cut' => $foreignCut->id, 'client_take_id' => (string) Str::ulid()]],
    ])->assertNotFound();
});

test('ネスト位置の保護キー直送 (takes.*.cut_id) は 422', function (): void {
    [, $owner, $project, $manual, $cut] = syncContext();

    $response = $this->actingAs($owner)->postJson(syncPath($project, $manual), [
        'takes' => [['cut' => $cut->id, 'cut_id' => $cut->id, 'client_take_id' => (string) Str::ulid()]],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['takes.0.cut_id']);
});

test('空 takes 配列は全量スナップショットのみ返す', function (): void {
    [, $owner, $project, $manual, $cut] = syncContext();
    Take::factory()->forCut($cut)->create();

    $response = $this->actingAs($owner)->postJson(syncPath($project, $manual), ['takes' => []]);

    $response->assertOk();
    $response->assertJsonCount(0, 'pending_upload');
    $response->assertJsonCount(1, 'manual.cuts');
    $response->assertJsonCount(1, 'manual.cuts.0.takes');
});

test('cross-org 404 / org member (読み取り権限) は同期可', function (): void {
    [$organization, , $project, $manual] = syncContext();

    [, $otherOwner] = createOrganizationWithOwner();
    $this->actingAs($otherOwner)->postJson(syncPath($project, $manual), ['takes' => []])->assertNotFound();

    $orgMember = attachOrganizationMember($organization);
    $orgMember->forceFill(['current_organization_id' => $organization->id])->save();
    $this->actingAs($orgMember)->postJson(syncPath($project, $manual), ['takes' => []])->assertOk();
});

test('500 件超の takes は 422 (分割送信を促す)', function (): void {
    [, $owner, $project, $manual, $cut] = syncContext();
    $takes = [];
    for ($i = 0; $i < 501; $i++) {
        $takes[] = ['cut' => $cut->id, 'client_take_id' => (string) Str::ulid()];
    }

    $this->actingAs($owner)->postJson(syncPath($project, $manual), ['takes' => $takes])
        ->assertStatus(422);
});
