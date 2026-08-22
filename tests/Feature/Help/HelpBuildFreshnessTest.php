<?php

declare(strict_types=1);

use App\Services\Help\HelpBuildService;
use Tests\Support\Help\HelpTestTree;

/*
 * ヘルプ生成物の**鮮度ゲート本体** (I4 / I5)。
 *
 * 実リポジトリの `docs/help/` を **読み取りだけ** で検査する。生成物が実装から
 * ずれたまま気付かれない形を作らないための検査であり、これが `composer test`
 * (= CI) で赤くなることが正典 AG-100 の【必須条件】である。
 *
 * ★書き込みは一切しない (`--parallel` の他レーンと競合しないのはこのためである)。
 *   書き込みを伴う振る舞いの検査は `HelpBuildCommandTest` が一時ディレクトリで行う。
 * ★赤くなったら `php artisan help:build` を実行して差分をコミットすること。
 */

test('実リポジトリのヘルプ生成物は鮮度が保たれている (php artisan help:build --check が 0)', function (): void {
    $before = HelpTestTree::snapshot(base_path('docs/help'));

    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);

    // 検査モードが作業ツリーを 1 バイトも変えないことを実ツリーでも確かめる
    expect(HelpTestTree::snapshot(base_path('docs/help')))->toBe($before);
});

test('実リポジトリの観測はすべて up_to_date である (問題 0 件)', function (): void {
    $report = app(HelpBuildService::class)->check();

    expect($report->observations)->not->toBeEmpty()
        ->and($report->problems())->toBe([])
        ->and($report->isClean())->toBeTrue();
});
