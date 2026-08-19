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
    // 制作状態 (status) は載せない (T197: 撮影 PWA の進捗はカットの採用状況から導出する別の量)
    expect(array_keys($summary))->toBe([
        'id', 'title', 'category_id', 'category_name',
        'cuts_total', 'cuts_adopted', 'cuts_with_takes', 'updated_at', 'creator_name',
        // 代表サムネイルの座標 (T198)。無ければ null で、内側のキーと型は
        // CaptureCoverThumbnailTest が固定する
        'cover',
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
        'id', 'client_take_id', 'status', 'material_type', 'size_bytes', 'duration_ms', 'comment',
        'captured_at', 'sort_order', 'downloaded', 'has_thumbnail', 'playback_url',
        'download_ack_token',
    ]);
    $cutShape = $response->inertiaPage()['props']['manual']['cuts'][0];
    expect(array_keys($cutShape))->toBe([
        'id', 'type', 'parent_cut_id', 'scene', 'shot_type', 'shooting_point',
        'narration', 'subtitle_primary', 'subtitle_secondary', 'material_type',
        'adopted_take_id', 'adopted_ready_take_id', 'takes',
    ]);
});

/*
 * メタ情報 (施策3): カテゴリ名 / 作成者名 / 更新日時 / 合計時間 (doc/05 §5.2)。
 * 合計時間は「いま尺が確定している分」の合計であって完成動画の見込み尺ではない。
 */

test('show の manual 直下キー集合は TS CaptureManualDetail と対 (PHP↔TS 契約)', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");

    $props = $response->inertiaPage()['props']['manual'];
    expect(array_keys($props))->toBe([
        'id', 'title', 'status', 'category_name', 'creator_name', 'updated_at',
        'total_duration_ms', 'undetermined_cut_count', 'cuts',
    ]);
});

test('show はカテゴリ名・作成者名・更新日時 (ISO 8601) を返す', function (): void {
    [, $owner, $project] = browsingContext();
    $category = Category::factory()->forProject($project)->create(['name' => '組立作業']);
    $manual = VideoManual::factory()->forProject($project)->forCategory($category)
        ->createdBy($owner)->create(['status' => 'ready']);

    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");

    $response->assertInertia(fn (Assert $page) => $page
        ->where('manual.category_name', '組立作業')
        ->where('manual.creator_name', $owner->name)
        ->where('manual.updated_at', $manual->fresh()?->updated_at?->toIso8601String()));
});

test('show は未分類なら category_name が null', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertInertia(fn (Assert $page) => $page->where('manual.category_name', null));
});

test('show の合計時間は静止画カット (未撮影) + 動画カット (採用 ready) の合算で未確定 0 件', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    Cut::factory()->forManual($manual)->withSortOrder(0)->create([
        'material_type' => 'still',
        'static_display_seconds' => 4, // → 4,000ms (撮影前でも確定)
    ]);
    $videoCut = Cut::factory()->forManual($manual)->withSortOrder(1)->create(['material_type' => 'video']);
    $take = Take::factory()->forCut($videoCut)->create(['duration_ms' => 6_000]);
    $videoCut->forceFill(['adopted_take_id' => $take->id])->save();

    $storage = Mockery::mock(TakeObjectStorage::class);
    $storage->shouldReceive('temporaryPlaybackUrl')->andReturn('https://s3.fake.test/signed-get-url');
    app()->instance(TakeObjectStorage::class, $storage);

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manual.total_duration_ms', 10_000)
            ->where('manual.undetermined_cut_count', 0));
});

test('show は未撮影の動画カットを未確定として数え、合計からは除く', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    Cut::factory()->forManual($manual)->withSortOrder(0)->create(['material_type' => 'still', 'static_display_seconds' => 3]);
    Cut::factory()->forManual($manual)->withSortOrder(1)->create(['material_type' => 'video']); // 未撮影

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manual.total_duration_ms', 3_000)
            ->where('manual.undetermined_cut_count', 1));
});

test('採用済みだが ready でないテイクは URL も尺も未確定として扱う', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->withSortOrder(0)->create(['material_type' => 'video']);
    $notReadyTake = Take::factory()->forCut($cut)->create(['status' => 'processing', 'duration_ms' => 9_000]);
    $cut->forceFill(['adopted_take_id' => $notReadyTake->id])->save();

    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");

    $response->assertInertia(fn (Assert $page) => $page
        ->where('manual.cuts.0.takes.0.playback_url', null)
        ->where('manual.cuts.0.takes.0.download_ack_token', null)
        ->where('manual.total_duration_ms', null)
        ->where('manual.undetermined_cut_count', 1));
});

test('同一 org 内の別 project の manual を URL に差し込むと 404 (認可より前)', function (): void {
    [$organization, $owner, $projectA] = browsingContext();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $manualOfB = VideoManual::factory()->forProject($projectB)->create(['status' => 'ready']);

    $this->actingAs($owner)
        ->get("/app/projects/{$projectA->id}/manuals/{$manualOfB->id}")
        ->assertNotFound();
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

/*
|--------------------------------------------------------------------------
| has_thumbnail (T183 / S8)
|--------------------------------------------------------------------------
|
| props の述語は **GET .../thumbnail が 302 を返す条件と 1 対 1** である
| (ready でないテイクで true を返すと、必ず 404 になる <img> を描画してしまう)。
*/

test('has_thumbnail は「ready かつ生成済み」のときだけ true になる', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $generated = Take::factory()->forCut($cut)->withThumbnail()->create(['sort_order' => 0]);
    $pending = Take::factory()->forCut($cut)->create(['sort_order' => 1]);
    // 生成済みだが ready ではない = endpoint は 404 を返すので false でなければならない
    $notReady = Take::factory()->forCut($cut)->withThumbnail()->create([
        'status' => 'processing',
        'sort_order' => 2,
    ]);

    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");
    $takes = collect($response->inertiaPage()['props']['manual']['cuts'][0]['takes'])
        ->keyBy('id');

    expect($takes[$generated->id]['has_thumbnail'])->toBeTrue();
    expect($takes[$pending->id]['has_thumbnail'])->toBeFalse();
    expect($takes[$notReady->id]['has_thumbnail'])->toBeFalse();
});

/*
 * T202: 撮影 PWA 一覧の検索もカット本文 (scene / narration / subtitle_*) を対象にし、
 * 検索語の正規化 (trim + 先頭 200 文字) が PC 一覧と**同じ関数**を通ること。
 * 正規化は本改善で撮影 PWA に**新しく入る契約**である (従来は trim も上限も無かった)。
 */

/** 本文 (指定列) にだけ検索語を持つカットを 1 本ぶら下げた manual を作る */
function captureManualWithBody(Project $project, string $column, string $word, string $status = 'ready'): VideoManual
{
    $manual = VideoManual::factory()->forProject($project)->create([
        'title' => "{$column} の手順", 'status' => $status,
    ]);
    Cut::factory()->forManual($manual)->create([
        'scene' => '既定のシーン',
        'narration' => '既定のナレーション',
        'subtitle_primary' => '既定の字幕',
        'subtitle_secondary' => '既定の補助字幕',
        $column => "作業で{$word}を使う",
    ]);

    return $manual;
}

test('index の q は narration に部分一致する (撮影 PWA でも本文で当たる)', function (): void {
    [, $owner, $project] = browsingContext();
    $target = captureManualWithBody($project, 'narration', 'トルクレンチ');
    captureManualWithBody($project, 'narration', 'ホウキ');

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('トルクレンチ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $target->id));
});

test('index の q は scene / narration / subtitle_primary / subtitle_secondary のいずれでも hit する', function (): void {
    [, $owner, $project] = browsingContext();

    $columns = [
        'scene' => 'ゴウセイ',
        'narration' => 'ナレゴ',
        'subtitle_primary' => 'ジマクイチ',
        'subtitle_secondary' => 'ジマクニ',
    ];
    $ids = [];
    foreach ($columns as $column => $word) {
        $ids[$column] = captureManualWithBody($project, $column, $word)->id;
    }

    foreach ($columns as $column => $word) {
        $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode($word))
            ->assertInertia(fn (Assert $page) => $page
                ->has('manuals', 1)
                ->where('manuals.0.id', $ids[$column]));
    }
});

test('index の q は shooting_point には一致しない (対象外列)', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create([
        'title' => '構図の手順', 'status' => 'ready',
    ]);
    Cut::factory()->forManual($manual)->create([
        'scene' => '既定のシーン',
        'narration' => '既定のナレーション',
        'subtitle_primary' => null,
        'subtitle_secondary' => '既定の補助字幕',
        'shooting_point' => '手元をヨリデトルコト',
    ]);

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('ヨリデトルコト'))
        ->assertInertia(fn (Assert $page) => $page->has('manuals', 0));
});

test('index の q は draft / analyzing を拾わない (ready/published の母集団が保たれる)', function (): void {
    [, $owner, $project] = browsingContext();
    $ready = captureManualWithBody($project, 'narration', 'ボゴタイ', 'ready');
    captureManualWithBody($project, 'narration', 'ボゴタイ', 'draft');
    captureManualWithBody($project, 'narration', 'ボゴタイ', 'analyzing');

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('ボゴタイ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $ready->id));
});

test('index の q は mine=1 / category と AND で効く (カット本文一致でも)', function (): void {
    [$organization, $owner, $project] = browsingContext();
    $other = attachOrganizationMember($organization);
    $category = Category::factory()->forProject($project)->create();

    $target = VideoManual::factory()->forProject($project)->forCategory($category)
        ->createdBy($owner)->create(['title' => '対象', 'status' => 'ready']);
    Cut::factory()->forManual($target)->create(['narration' => 'ここでフクゴウゴを使う']);

    // 他人作 (mine で外れる)
    $byOther = VideoManual::factory()->forProject($project)->forCategory($category)
        ->createdBy($other)->create(['title' => '他人作', 'status' => 'ready']);
    Cut::factory()->forManual($byOther)->create(['narration' => 'ここでフクゴウゴを使う']);

    // 自作だが未分類 (category で外れる)
    $uncategorized = VideoManual::factory()->forProject($project)
        ->createdBy($owner)->create(['title' => '未分類', 'status' => 'ready']);
    Cut::factory()->forManual($uncategorized)->create(['narration' => 'ここでフクゴウゴを使う']);

    $this->actingAs($owner)
        ->get("/app/projects/{$project->id}/manuals?mine=1&category={$category->id}&q=".urlencode('フクゴウゴ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $target->id));
});

test('index の q は前後の空白を trim する (filters.q も trim 後を返す)', function (): void {
    [, $owner, $project] = browsingContext();
    $target = captureManualWithBody($project, 'narration', 'ネジシメ');

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('  ネジシメ  '))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $target->id)
            ->where('filters.q', 'ネジシメ'));
});

test('index の q が空白のみなら絞り込まない (filters.q は null)', function (): void {
    [, $owner, $project] = browsingContext();
    captureManualWithBody($project, 'narration', 'ネジシメ');
    captureManualWithBody($project, 'narration', 'ホウキ');

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('   '))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 2)
            ->where('filters.q', null));
});

test('index の q は先頭 200 文字 (文字数) で切られ filters.q も切り詰め後を返す', function (): void {
    [, $owner, $project] = browsingContext();
    $body = str_repeat('あ', 200);
    $manual = VideoManual::factory()->forProject($project)->create([
        'title' => '長文本文', 'status' => 'ready',
    ]);
    Cut::factory()->forManual($manual)->create(['narration' => $body.'ZZZ']);

    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode($body.'YYY'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $manual->id)
            ->where('filters.q', fn (mixed $q): bool => is_string($q) && mb_strlen($q) === 200 && $q === $body));
});
