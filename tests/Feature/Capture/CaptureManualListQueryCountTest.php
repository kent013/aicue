<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Support\Facades\DB;

/*
 * T198: 撮影 PWA 一覧のクエリ数が**行数に比例しない**ことを固定する。
 *
 * 代表サムネイルは行ごとにカットを走査すると即 N+1 になる。守るのは 2 段で、
 * (1) coverCut を eager load すること / (2) その入れ子の adoptedTake まで張ること。
 * (2) を落とすと readyTakeId() が行ごとに lazy load して復活するため、
 * 「代表を持つ行が 10 件ある」配置で測る。
 *
 * 計測は「GET 1 回ぶん」に限り、fixture 生成は flushQueryLog で計測外にする。
 * 初回リクエスト固有の初期化を混ぜないよう、計測前に暖機の GET を 1 回撃つ。
 * 利用者ごとにクエリ数は変わる (権限が無ければ eager load を積まない) ので、
 * 比較は**同一利用者の行数違い**でのみ行う。
 */

/** 代表サムネイルを持つ manual を 1 本作る */
function manualWithCover(Project $project): VideoManual
{
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
    $take = Take::factory()->forCut($cut)->withThumbnail()->create();
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    return $manual;
}

/**
 * 一覧 GET 1 回ぶんに実行された SQL。
 *
 * @return list<string>
 */
function measureCaptureIndexQueries(User $actor, Project $project): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    test()->actingAs($actor)->get("/app/projects/{$project->id}/manuals")->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
}

/**
 * @param  list<string>  $single
 * @param  list<string>  $ten
 */
function expectSameQueryCount(array $single, array $ten): void
{
    expect($single)->not->toBeEmpty();
    expect(count($ten))->toBe(
        count($single),
        '撮影一覧のクエリ数が行数に比例しました (1 行: '.count($single).' 件 / 10 行: '
        .count($ten)." 件)。\n10 行ページの SQL:\n".implode("\n", $ten)
    );
}

test('撮影一覧のクエリ数は行数に比例しない (全行が代表を持つ配置)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $singleRowProject = Project::factory()->forOrganization($organization)->create();
    manualWithCover($singleRowProject);

    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
    foreach (range(1, 10) as $ignored) {
        manualWithCover($tenRowsProject);
    }

    measureCaptureIndexQueries($owner, $singleRowProject); // 暖機

    expectSameQueryCount(
        measureCaptureIndexQueries($owner, $singleRowProject),
        measureCaptureIndexQueries($owner, $tenRowsProject),
    );
});

test('代表の有無が混在してもクエリ数は行数に比例しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $singleRowProject = Project::factory()->forOrganization($organization)->create();
    manualWithCover($singleRowProject);

    // 候補 0 件 / 1 件 / 複数件が混ざった 10 行
    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
    foreach (range(1, 10) as $index) {
        if ($index % 3 === 0) {
            // 候補 0 件 (カットはあるが採用テイクなし)
            $manual = VideoManual::factory()->forProject($tenRowsProject)->create(['status' => 'ready']);
            Cut::factory()->forManual($manual)->withSortOrder(0)->create();

            continue;
        }

        $manual = manualWithCover($tenRowsProject);
        if ($index % 3 === 1) {
            continue; // 候補 1 件
        }

        // 候補 複数件
        $extra = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
        $extraTake = Take::factory()->forCut($extra)->withThumbnail()->create();
        $extra->forceFill(['adopted_take_id' => $extraTake->id])->save();
    }

    measureCaptureIndexQueries($owner, $singleRowProject); // 暖機

    expectSameQueryCount(
        measureCaptureIndexQueries($owner, $singleRowProject),
        measureCaptureIndexQueries($owner, $tenRowsProject),
    );
});

test('代表を見られない利用者でもクエリ数は行数に比例しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $orgMember = attachOrganizationMember($organization);
    $orgMember->forceFill(['current_organization_id' => $organization->id])->save();

    $singleRowProject = Project::factory()->forOrganization($organization)->create();
    manualWithCover($singleRowProject);

    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
    foreach (range(1, 10) as $ignored) {
        manualWithCover($tenRowsProject);
    }

    measureCaptureIndexQueries($orgMember, $singleRowProject); // 暖機

    // resolveCover の早期 return が relation に触れていないこと (触れば行ごとの lazy load になる)
    expectSameQueryCount(
        measureCaptureIndexQueries($orgMember, $singleRowProject),
        measureCaptureIndexQueries($orgMember, $tenRowsProject),
    );
});
