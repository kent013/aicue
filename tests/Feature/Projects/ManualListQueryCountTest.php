<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use Illuminate\Support\Facades\DB;

/*
 * T182: 一覧描画のクエリ数が**行数に比例しない**ことを固定する。
 *
 * 行ごとに ability を評価したり現行世代の render を引いたりすると、
 * per_page=10 の一覧で権限解決と render 取得が 10 倍になる。
 * 計測は「GET 1 回ぶん」に限る (fixture 生成は flushQueryLog で計測外にする)。
 * 初回リクエスト固有の初期化を計測に混ぜないよう、計測前に暖機の GET を 1 回撃つ。
 */

test('一覧のクエリ数は行数に比例しない (1 行のページと 10 行のページで同数)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $singleRowProject = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($singleRowProject)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/1.mp4')->create();

    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
    foreach (range(1, 10) as $i) {
        $row = VideoManual::factory()->forProject($tenRowsProject)->published(60_000)->create();
        RenderJob::factory()->forManual($row)->succeeded("renders/{$i}.mp4")->create();
    }

    /** @return list<string> 実行された SQL */
    $measure = function (Project $project) use ($owner): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return array_map(fn (array $entry): string => (string) $entry['query'], $log);
    };

    // 暖機 (初回リクエストだけに出る初期化を計測から外す)
    $measure($singleRowProject);

    $singleQueries = $measure($singleRowProject);
    $tenQueries = $measure($tenRowsProject);

    expect($singleQueries)->not->toBeEmpty();
    expect(count($tenQueries))->toBe(
        count($singleQueries),
        '一覧のクエリ数が行数に比例しました (1 行: '.count($singleQueries).' 件 / 10 行: '
        .count($tenQueries)." 件)。\n10 行ページの SQL:\n".implode("\n", $tenQueries)
    );
});
