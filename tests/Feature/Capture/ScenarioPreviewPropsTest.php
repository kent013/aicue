<?php

declare(strict_types=1);

use App\Enums\Manual\TakeStatus;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use Illuminate\Support\Facades\DB;

/*
 * 撮影 PWA の通し再生 (全体連結プレビュー / T191) が依存する props の契約。
 *
 * 固定するのは 3 点:
 *  1. cuts.*.adopted_ready_take_id は「使用できる採用テイク」の id そのものである
 *     (述語の実体は AdoptedReadyTakeCoverage::readyTakeId() 1 箇所。TakeStatus の 4 値すべてを個別に固定する)
 *  2. 署名 playback URL / DL ACK トークンの発行条件が**同じ述語**に揃っている
 *     (非 ready の採用テイクへは 1 度も署名 URL を作らない = takes.playback の 404 と同じゲート)
 *  3. previewPlaceholderSeconds がページ props に載り、サーバ生成プレビューと同じ設定値を指す
 */

/**
 * 撮影者 (org owner) + ready manual + step カット 1 枚。
 *
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function scenarioPreviewContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();

    return [$organization, $owner, $project, $manual, $cut];
}

/** cut にテイクを作って採用状態にする (status を変えて述語の 4 値差を作る) */
function scenarioPreviewAdopt(Cut $cut, TakeStatus $status = TakeStatus::Ready): Take
{
    $take = Take::factory()->forCut($cut)->create(['status' => $status->value]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    return $take;
}

/** 署名 URL を返す storage mock を container へ差し込む (発行回数も固定できる) */
function scenarioPreviewFakeStorage(?string $url = 'https://s3.fake.test/signed-get-url'): void
{
    $storage = Mockery::mock(TakeObjectStorage::class);
    if ($url === null) {
        $storage->shouldNotReceive('temporaryPlaybackUrl');
    } else {
        $storage->shouldReceive('temporaryPlaybackUrl')->andReturn($url);
    }
    app()->instance(TakeObjectStorage::class, $storage);
}

/**
 * 撮影詳細 props を取り出す。
 *
 * @return array<string, mixed>
 */
function scenarioPreviewProps(Organization $organization, Project $project, VideoManual $manual, User $actor): array
{
    /** @var array<string, mixed> $props */
    $props = test()->actingAs($actor)
        ->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->inertiaPage()['props'];

    return $props;
}

test('採用済み + ready の cut は adopted_ready_take_id にそのテイク id を持つ', function (): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    $take = scenarioPreviewAdopt($cut);
    scenarioPreviewFakeStorage();

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBe($take->id);
});

test('採用済みでも ready でない cut の adopted_ready_take_id は null (uploading/processing/failed)', function (TakeStatus $status): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    scenarioPreviewAdopt($cut, $status);
    scenarioPreviewFakeStorage(null); // 署名 URL を 1 度も作らないことを併せて固定する

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBeNull();
})->with([
    'uploading' => TakeStatus::Uploading,
    'processing' => TakeStatus::Processing,
    'failed' => TakeStatus::Failed,
]);

test('未採用 (テイクはあるが adopted_take_id が null) の adopted_ready_take_id は null', function (): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    Take::factory()->forCut($cut)->create();
    scenarioPreviewFakeStorage(null);

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    expect($props['manual']['cuts'][0]['adopted_take_id'])->toBeNull();
    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBeNull();
});

test('テイクが 1 件も無い cut の adopted_ready_take_id は null', function (): void {
    [$organization, $owner, $project, $manual] = scenarioPreviewContext();
    scenarioPreviewFakeStorage(null);

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    expect($props['manual']['cuts'][0]['takes'])->toBe([]);
    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBeNull();
});

test('adopted_take_id と adopted_ready_take_id は別の意味である (採用済み非 ready で前者だけ非 null)', function (): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    $take = scenarioPreviewAdopt($cut, TakeStatus::Processing);
    scenarioPreviewFakeStorage(null);

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    expect($props['manual']['cuts'][0]['adopted_take_id'])->toBe($take->id);
    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBeNull();
});

test('採用済み非 ready のテイクには playback_url も download_ack_token も出さない (S2b)', function (): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    $take = scenarioPreviewAdopt($cut, TakeStatus::Processing);
    // 署名 URL 発行そのものが起きないことを直接固定する (呼ばれたら Mockery が落とす)
    scenarioPreviewFakeStorage(null);

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    $takeProps = $props['manual']['cuts'][0]['takes'][0];
    expect($takeProps['id'])->toBe($take->id);
    expect($takeProps['playback_url'])->toBeNull();
    expect($takeProps['download_ack_token'])->toBeNull();
});

test('採用済み + ready のテイクには従来どおり playback_url と download_ack_token が出る', function (): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    scenarioPreviewAdopt($cut);
    scenarioPreviewFakeStorage();

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    $takeProps = $props['manual']['cuts'][0]['takes'][0];
    expect($takeProps['playback_url'])->toBe('https://s3.fake.test/signed-get-url');
    expect($takeProps['download_ack_token'])->toBeString();
});

test('previewPlaceholderSeconds は config の値と一致する 1 以上の int である', function (): void {
    [$organization, $owner, $project, $manual] = scenarioPreviewContext();
    scenarioPreviewFakeStorage(null);

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    expect($props['previewPlaceholderSeconds'])->toBeInt();
    expect($props['previewPlaceholderSeconds'])->toBe(config()->integer('manual.preview_placeholder_seconds'));
    expect($props['previewPlaceholderSeconds'])->toBeGreaterThanOrEqual(1);
});

test('adopt 応答の adopted_ready_take_id は採用したテイク id になる (relation 鮮度)', function (): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    $take = Take::factory()->forCut($cut)->create();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}/adopt",
    )->assertOk()
        ->assertJsonPath('adopted_take_id', $take->id)
        ->assertJsonPath('adopted_ready_take_id', $take->id);
});

test('採用を付け替えると adopt 応答の adopted_ready_take_id は新しい方になる (relation 鮮度)', function (): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    $first = scenarioPreviewAdopt($cut);
    $second = Take::factory()->forCut($cut)->create(['sort_order' => 1]);

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$second->id}/adopt",
    )->assertOk()
        ->assertJsonPath('adopted_ready_take_id', $second->id);

    expect($second->id)->not->toBe($first->id);
});

test('cuts を増やしても採用テイクの取得クエリは 1 本のまま (N+1 を作らない)', function (): void {
    [$organization, $owner, $project, $manual, $cut] = scenarioPreviewContext();
    scenarioPreviewAdopt($cut);
    foreach (range(1, 4) as $index) {
        $extra = Cut::factory()->forManual($manual)->withSortOrder($index)->create();
        scenarioPreviewAdopt($extra);
    }
    scenarioPreviewFakeStorage();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $props = scenarioPreviewProps($organization, $project, $manual, $owner);

    expect($props['manual']['cuts'])->toHaveCount(5);
    $takeQueries = array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'from "takes"') && str_contains($sql, '"takes"."id" in'),
    ));
    expect($takeQueries)->toHaveCount(1);
});
