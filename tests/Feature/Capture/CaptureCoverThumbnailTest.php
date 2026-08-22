<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use Illuminate\Support\Facades\Gate;

/*
 * T198: 撮影 PWA 一覧カードの代表サムネイル。
 *
 * 代表の決め方は「表示順 (cuts.sort_order 昇順 → cuts.id 昇順) で最初に来る、
 * 採用テイクの thumbnail_path が非 null のカット」の、その採用テイク 1 枚である。
 * 候補の絞り込み (thumbnail_path 非 null) は VideoManual::coverCut() が持ち、
 * 「採用済みかつ ready か」は AdoptedReadyTakeCoverage が決める (判定式を増やさない = T148)。
 *
 * 本ファイルが固定するのは 3 つ:
 *   選択規則 (#1-#5) / 契約 (i)(ii)(iii) (#6-#9) / 境界と props の形 (#10-#13)
 */

/**
 * @return array{Organization, User, Project}
 */
function coverContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    return [$organization, $owner, $project];
}

/**
 * 採用済みテイクを持つカットを 1 枚作る。
 *
 * 採用は既存の撮影テストと同じく forceFill で行う (adopt API は ready 以外を弾くため、
 * 「生成済みだが ready でない採用テイク」の配置が組めない)。
 *
 * @param  array<string, mixed>  $takeAttributes
 */
function coverCutWithAdoptedTake(
    VideoManual $manual,
    int $sortOrder,
    bool $withThumbnail = true,
    array $takeAttributes = [],
): Cut {
    $cut = Cut::factory()->forManual($manual)->withSortOrder($sortOrder)->create();
    $factory = Take::factory()->forCut($cut);
    if ($withThumbnail) {
        $factory = $factory->withThumbnail();
    }
    $take = $factory->create($takeAttributes);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    return $cut->refresh();
}

/**
 * 一覧 props から対象 manual の cover を取り出す。
 *
 * @return array{cut_id: int, take_id: int}|null
 */
function coverOf(Organization $organization, User $actor, Project $project, VideoManual $manual): ?array
{
    /** @var array<int, array<string, mixed>> $manuals */
    $manuals = test()->actingAs($actor)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals")
        ->assertOk()
        ->inertiaPage()['props']['manuals'];

    foreach ($manuals as $row) {
        if ($row['id'] === $manual->id) {
            /** @var array{cut_id: int, take_id: int}|null $cover */
            $cover = $row['cover'];

            return $cover;
        }
    }

    throw new RuntimeException("manual {$manual->id} が一覧に出ていません");
}

test('代表は表示順で最初の「採用テイク + サムネイル生成済み」カットになる', function (): void {
    [, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    // sort_order 0 は未採用 (テイクはあるが採用していない) = 候補にならない
    $unadopted = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
    Take::factory()->forCut($unadopted)->withThumbnail()->create();

    $cut = coverCutWithAdoptedTake($manual, sortOrder: 1);

    expect(coverOf($organization, $owner, $project, $manual))->toBe([
        'cut_id' => $cut->id,
        'take_id' => (int) $cut->adopted_take_id,
    ]);
});

test('sort_order が同値なら id 昇順で代表が決まる', function (): void {
    [, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    // 最小 id のカットを**最小 sort_order ではない**位置に置く。
    // 単一列 ['id' => 'min'] の実装ならこのカットが選ばれて必ず落ちる。
    coverCutWithAdoptedTake($manual, sortOrder: 5);
    $expected = coverCutWithAdoptedTake($manual, sortOrder: 1);
    $sameOrderLaterId = coverCutWithAdoptedTake($manual, sortOrder: 1);

    expect($expected->id)->toBeLessThan($sameOrderLaterId->id);
    expect(coverOf($organization, $owner, $project, $manual))->toBe([
        'cut_id' => $expected->id,
        'take_id' => (int) $expected->adopted_take_id,
    ]);
});

test('採用テイクが無ければ cover は null', function (): void {
    [, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
    Take::factory()->forCut($cut)->withThumbnail()->create(); // 採用していない

    expect(coverOf($organization, $owner, $project, $manual))->toBeNull();
});

test('採用テイクのサムネイルが未生成なら cover は null', function (): void {
    [, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    coverCutWithAdoptedTake($manual, sortOrder: 0, withThumbnail: false);

    expect(coverOf($organization, $owner, $project, $manual))->toBeNull();
});

test('生成済みだが ready でない採用テイクは cover にせず、次のカットも探さない', function (): void {
    [, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    // 候補条件 (サムネイル生成済み) は満たすが表示条件 (ready) を満たさない先頭カット。
    // 安全側 = 壊れた画像を出さない側へ倒すため、次のカットへ探しに行かない。
    coverCutWithAdoptedTake($manual, sortOrder: 0, takeAttributes: ['status' => 'processing']);
    coverCutWithAdoptedTake($manual, sortOrder: 1);

    expect(coverOf($organization, $owner, $project, $manual))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 契約 (i) 配信可能性 / (ii) 完全性 / (iii) 認可委譲
|--------------------------------------------------------------------------
*/

test('契約 (i): cover の id で組んだ thumbnail URL は 302 と no-store を返す', function (): void {
    [$organization, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    coverCutWithAdoptedTake($manual, sortOrder: 0);

    $storage = Mockery::mock(TakeObjectStorage::class);
    $storage->shouldReceive('temporaryThumbnailUrl')
        ->once()
        ->andReturn('https://s3.fake.test/signed-thumbnail-url');
    app()->instance(TakeObjectStorage::class, $storage);

    $cover = coverOf($organization, $owner, $project, $manual);
    expect($cover)->not->toBeNull();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}"
            ."/cuts/{$cover['cut_id']}/takes/{$cover['take_id']}/thumbnail")
        ->assertRedirect('https://s3.fake.test/signed-thumbnail-url')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('契約 (ii): 3 条件が揃えば候補が複数あっても cover は非 null', function (): void {
    [, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $first = coverCutWithAdoptedTake($manual, sortOrder: 0);
    coverCutWithAdoptedTake($manual, sortOrder: 1);
    coverCutWithAdoptedTake($manual, sortOrder: 2);

    expect(coverOf($organization, $owner, $project, $manual))->toBe([
        'cut_id' => $first->id,
        'take_id' => (int) $first->adopted_take_id,
    ]);
});

test('権限: org member (非 project member) は cover が全行 null で同 URL は 403', function (): void {
    [$organization, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    coverCutWithAdoptedTake($manual, sortOrder: 0);

    // cover の id は権限を持つ利用者の props から取る
    $cover = coverOf($organization, $owner, $project, $manual);
    expect($cover)->not->toBeNull();

    $orgMember = attachOrganizationMember($organization);

    // 一覧そのものは 200 のまま (画面ごと 403 にしない = 行き先のない詰みを作らない)
    expect(coverOf($organization, $orgMember, $project, $manual))->toBeNull();

    $this->actingAs($orgMember)
        ->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}"
            ."/cuts/{$cover['cut_id']}/takes/{$cover['take_id']}/thumbnail")
        ->assertForbidden();
});

test('契約 (iii): preview 認可と capture 認可は 4 者すべてで同値 (relation ロード有無を問わず)', function (): void {
    [$organization, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = coverCutWithAdoptedTake($manual, sortOrder: 0);
    $takeId = (int) $cut->adopted_take_id;

    $projectMember = attachOrganizationMember($organization);
    attachProjectMember($project, $projectMember);
    $orgMember = attachOrganizationMember($organization);
    [, $foreignUser] = createOrganizationWithOwner('別組織');

    foreach ([$owner, $projectMember, $orgMember, $foreignUser] as $actor) {
        // 再取得インスタンス (relation 未ロード。policy が cut→manual→project を辿る)
        $fresh = Take::query()->findOrFail($takeId);
        // eager load 済みインスタンス (一覧経路と同じ形)
        $loaded = Take::query()->with('cut.videoManual.project')->findOrFail($takeId);

        $expected = Gate::forUser($actor)->allows('capture', $project);
        expect(Gate::forUser($actor)->allows('preview', $fresh))->toBe(
            $expected,
            '未ロード instance で preview と capture の判定が乖離しました'
        );
        expect(Gate::forUser($actor)->allows('preview', $loaded))->toBe(
            $expected,
            'eager load 済み instance で preview と capture の判定が乖離しました'
        );
    }

    // 負のコントロール: 4 者が全員同じ結果なら「同値」に意味が無いため、差があることを確かめる
    expect(Gate::forUser($owner)->allows('capture', $project))->toBeTrue();
    expect(Gate::forUser($orgMember)->allows('capture', $project))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 境界 (テナント境界 404 は認可より前) と props の形
|--------------------------------------------------------------------------
*/

test('境界: cover の id を別 org の URL に嵌めると 404', function (): void {
    [$organization, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    coverCutWithAdoptedTake($manual, sortOrder: 0);
    $cover = coverOf($organization, $owner, $project, $manual);
    expect($cover)->not->toBeNull();

    [, $foreignUser] = createOrganizationWithOwner('別組織');

    $this->actingAs($foreignUser)
        ->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}"
            ."/cuts/{$cover['cut_id']}/takes/{$cover['take_id']}/thumbnail")
        ->assertNotFound();
});

test('境界: cover の id を別 project / 別 manual の URL に嵌めると 404', function (): void {
    [$organization, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    coverCutWithAdoptedTake($manual, sortOrder: 0);
    $cover = coverOf($organization, $owner, $project, $manual);
    expect($cover)->not->toBeNull();

    $otherProject = Project::factory()->forOrganization($organization)->create();
    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    // 別 project 配下の URL (manual が project に属さない)
    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/app/projects/{$otherProject->id}/manuals/{$manual->id}"
            ."/cuts/{$cover['cut_id']}/takes/{$cover['take_id']}/thumbnail")
        ->assertNotFound();

    // 別 manual 配下の URL (cut が manual に属さない)
    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$otherManual->id}"
            ."/cuts/{$cover['cut_id']}/takes/{$cover['take_id']}/thumbnail")
        ->assertNotFound();
});

test('cover の cut / take は必ずその manual 配下のもの (取り違えない)', function (): void {
    [, $owner, $project] = coverContext();
    $first = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $second = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $firstCut = coverCutWithAdoptedTake($first, sortOrder: 0);
    $secondCut = coverCutWithAdoptedTake($second, sortOrder: 0);

    expect(coverOf($organization, $owner, $project, $first))->toBe([
        'cut_id' => $firstCut->id,
        'take_id' => (int) $firstCut->adopted_take_id,
    ]);
    expect(coverOf($organization, $owner, $project, $second))->toBe([
        'cut_id' => $secondCut->id,
        'take_id' => (int) $secondCut->adopted_take_id,
    ]);
});

test('props に URL 文字列を載せない (cover のキーは cut_id / take_id の 2 つで値は int)', function (): void {
    [, $owner, $project] = coverContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    coverCutWithAdoptedTake($manual, sortOrder: 0);

    $cover = coverOf($organization, $owner, $project, $manual);
    expect($cover)->not->toBeNull();
    expect(array_keys($cover))->toBe(['cut_id', 'take_id']);
    expect($cover['cut_id'])->toBeInt();
    expect($cover['take_id'])->toBeInt();
});
