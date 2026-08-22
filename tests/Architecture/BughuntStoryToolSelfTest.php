<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Bughunt\StoryFrontMatterPins;

/*
 * Architecture invariant: シナリオカードの書式契約の自己テスト (Python) を
 * `composer test` の下で実走させる。
 *
 * 対象は 1 モジュール:
 *   - test_story_front_matter … 前付けの制限文法・番号規約・表 A / 表 B との突合
 *
 * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない (禁止事項 1)。
 *
 * 先例は BughuntCoverageToolSelfTest: python3 の不在は **skip ではなく fail** で
 * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
 *
 * 保証しないもの: 本ファイルが見るのは自己テストの**実走と件数と中核負例の成功表示**だけである。
 * 契約の中身 (何を検査しているか) は Python 側の docstring が正本で、ここには写さない。
 */

/** カードの書式契約の自己テストの置き場 (作業ディレクトリ)。 */
function bstStoriesDir(): string
{
    return base_path('.claude/skills/app-bug-hunt/stories');
}

/**
 * stories ディレクトリで `python3 -m unittest -v <modules...>` を実走し [exitCode, output] を返す。
 *
 * @param  list<string>  $modules
 * @return array{0: int|null, 1: string}
 */
function bstRunUnittest(array $modules): array
{
    $process = new Process(
        ['python3', '-m', 'unittest', '-v', ...$modules],
        bstStoriesDir(),
        ['PYTHONDONTWRITEBYTECODE' => '1'],
    );
    $process->setTimeout(120);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
    expect((new Process(['which', 'python3']))->run())->toBe(
        0,
        'python3 が PATH に無い。カードの書式契約の自己テストは python3 必須 (stdlib のみ)。'
    );
});

test('カードの書式契約の自己テストが composer test の下で通ること', function (): void {
    expect(is_dir(bstStoriesDir()))->toBeTrue('stories ディレクトリが見つからない: '.bstStoriesDir());

    [$code, $out] = bstRunUnittest(['test_story_front_matter']);

    expect($code)->toBe(0, "カードの書式契約の自己テストが失敗した:\n".$out);
});

test('負の対照: 存在しないモジュール名を渡すと非 0 になること (空振り gate を作らない)', function (): void {
    [$code] = bstRunUnittest(['test_no_such_module_exists']);

    expect($code)->not->toBe(0, '存在しないモジュールでも 0 が返る = 実走していない疑い');
});

test('件数の下限が実測値へ差し替えられていること (0 のままだと検査が無効化される)', function (): void {
    // MIN_TESTS = 0 の置き忘れは、件数 pin を常に成功させて機構ごと無効にする。
    // PHPDoc の positive-int だけでは実行時の 0 を防げないので assert で固定する。
    expect(StoryFrontMatterPins::MIN_TESTS)->toBeGreaterThan(0);
});

test('活きている検査の件数が下限を下回らないこと (検査を飛ばして緑に見せない)', function (): void {
    // 件数の下限を実測値で pin する。検査を削って緑にする道を塞ぐ。
    [$code, $out] = bstRunUnittest(['test_story_front_matter']);

    expect($code)->toBe(0, $out);
    expect((int) (preg_match('/^Ran (\d+) tests?/m', $out, $m) === 1 ? $m[1] : 0))
        ->toBeGreaterThanOrEqual(
            StoryFrontMatterPins::MIN_TESTS,
            '活きている検査が下限 ('.StoryFrontMatterPins::MIN_TESTS.") を下回った:\n".$out,
        );
});

test('中核の負例が名前と成功表示の両方で実在すること (skip 逃げを塞ぐ)', function (): void {
    // 名前だけを見ると skip でも緑になる。`... ok` まで照合する。
    // ★ 終了コードもここで確認する。別テストが確認していても**実行は別プロセス**であり、
    //   同一結果とは限らない。
    [$code, $out] = bstRunUnittest(['test_story_front_matter']);

    expect($code)->toBe(0, $out);

    foreach (StoryFrontMatterPins::CORE_NEGATIVES as $name) {
        expect($out)->toMatch('/'.preg_quote($name, '/').'.*\.\.\. ok$/m', "負例 {$name} が ok で実行されていない");
    }
});
