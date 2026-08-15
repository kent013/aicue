<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: bug-hunt のカバレッジ道具 (Python) の自己テストを
 * `composer test` の下で実走させる。
 *
 * 対象は 3 モジュール:
 *   - test_correlate      … 照合器の fail-closed 契約 (主入力が揃わない走行を成功にしない)
 *   - test_build_executed … 実行済み route の記録の集約器 (同上)
 *   - test_naming_no_stale … 旧 fail-open 文言・旧語彙の再混入検知
 *
 * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない
 * (禁止事項 1)。禁止語が戻っても、照合器が fail-open へ戻っても、緑のままになるため。
 * `test_merge_pcov` はコード到達カバレッジ (別 feature) の担当なので本目録には入れない。
 *
 * 先例は BugHuntInventoryCheckInvariantTest: python3 の不在は **skip ではなく fail** で
 * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
 */

/** カバレッジ道具の置き場 (作業ディレクトリ)。 */
function bctCoverageDir(): string
{
    return base_path('.claude/skills/app-bug-hunt/coverage');
}

/**
 * coverage ディレクトリで `python3 -m unittest <modules...>` を実走し [exitCode, output] を返す。
 *
 * @param  list<string>  $modules
 * @return array{0: int|null, 1: string}
 */
function bctRunUnittest(array $modules): array
{
    $process = new Process(['python3', '-m', 'unittest', ...$modules], bctCoverageDir());
    $process->setTimeout(120);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
    expect((new Process(['which', 'python3']))->run())->toBe(
        0,
        'python3 が PATH に無い。bug-hunt のカバレッジ道具は python3 必須 (stdlib のみ)。'
    );
});

test('カバレッジ道具の Python 自己テスト 3 本が composer test の下で通ること', function (): void {
    expect(is_dir(bctCoverageDir()))->toBeTrue('coverage ディレクトリが見つからない: '.bctCoverageDir());

    [$code, $out] = bctRunUnittest(['test_correlate', 'test_build_executed', 'test_naming_no_stale']);

    expect($code)->toBe(0, "bug-hunt カバレッジ道具の自己テストが失敗しました:\n".$out);
});

test('負の対照: 存在しないモジュール名を渡すと非 0 になること (空振り gate を作らない)', function (): void {
    [$code] = bctRunUnittest(['test_no_such_module_exists']);

    expect($code)->not->toBe(0, '存在しないモジュールでも 0 が返る = 実走していない疑い');
});
