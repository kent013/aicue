<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Support\Facades\DB;

/*
 * T232: 撮影詳細のクエリ数が**カット数・テイク数のどちらにも比例しない**ことを固定する。
 *
 * メタ情報 (カテゴリ名 / 作成者名) は controller の loadMissing で 1 行あたり最大 2 クエリ、
 * 合計時間は既に取得済みのカット列と採用テイクから作るので追加クエリを持たない。
 * カットごとに adoptedTake / takes を lazy load する形へ戻ると、ここで検出できる。
 *
 * 2 軸を**独立に**検証する (どちらか一方だけを変えたケース):
 *   1. カット数を変える (1 本 / 10 本)。各カットのテイク数は揃える。
 *   2. カット数を揃え、1 カットあたりのテイク数を変える (1 本 / 5 本)。
 *
 * 計測は「GET 1 回ぶん」に限り、fixture 生成は flushQueryLog で計測外にする。
 * 初回リクエスト固有の初期化を混ぜないよう、計測前に暖機の GET を 1 回撃つ。
 */

/** 指定本数のカットを持つ manual を作り、各カットに指定本数のテイクをぶら下げる */
function manualWithCutsAndTakes(Project $project, int $cutCount, int $takesPerCut): VideoManual
{
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    foreach (range(1, $cutCount) as $index) {
        $cut = Cut::factory()->forManual($manual)->withSortOrder($index)->create();
        foreach (range(1, $takesPerCut) as $takeIndex) {
            Take::factory()->forCut($cut)->create(['sort_order' => $takeIndex]);
        }
    }

    return $manual;
}

/**
 * 撮影詳細 GET 1 回ぶんに実行された SQL。
 *
 * @return list<string>
 */
function measureCaptureShowQueries(User $actor, Project $project, VideoManual $manual): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    test()->actingAs($actor)->get("/app/projects/{$project->id}/manuals/{$manual->id}")->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
}

/**
 * @param  list<string>  $single
 * @param  list<string>  $many
 */
function expectSameShowQueryCount(array $single, array $many): void
{
    expect($single)->not->toBeEmpty();
    expect(count($many))->toBe(
        count($single),
        '撮影詳細のクエリ数が比例しました (基準: '.count($single).' 件 / 比較対象: '
        .count($many)." 件)。\n比較対象の SQL:\n".implode("\n", $many)
    );
}

test('撮影詳細のクエリ数はカット数に比例しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $singleCutManual = manualWithCutsAndTakes($project, cutCount: 1, takesPerCut: 2);
    $tenCutsManual = manualWithCutsAndTakes($project, cutCount: 10, takesPerCut: 2);

    measureCaptureShowQueries($owner, $project, $singleCutManual); // 暖機

    expectSameShowQueryCount(
        measureCaptureShowQueries($owner, $project, $singleCutManual),
        measureCaptureShowQueries($owner, $project, $tenCutsManual),
    );
});

test('撮影詳細のクエリ数はカット 1 本あたりのテイク数に比例しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $fewTakesManual = manualWithCutsAndTakes($project, cutCount: 3, takesPerCut: 1);
    $manyTakesManual = manualWithCutsAndTakes($project, cutCount: 3, takesPerCut: 5);

    measureCaptureShowQueries($owner, $project, $fewTakesManual); // 暖機

    expectSameShowQueryCount(
        measureCaptureShowQueries($owner, $project, $fewTakesManual),
        measureCaptureShowQueries($owner, $project, $manyTakesManual),
    );
});
