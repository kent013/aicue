<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: bug-hunt 目録の生成器 (Python) の自己テストを
 * `composer test` の下で実走させる。
 *
 * 対象は 1 モジュール:
 *   - test_bug_hunt_inventory … 生成器兼検査器の段 1..4 と fail-closed の作法
 *
 * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない
 * (禁止事項 1)。生成器が沈黙して緑を返すようになっても気づけないため。
 *
 * 先例は BughuntCoverageToolSelfTest: python3 の不在は **skip ではなく fail** で
 * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
 */

/** 生成器の自己テストの置き場 (作業ディレクトリ)。 */
function bitsTestsDir(): string
{
    return base_path('scripts/tests');
}

/**
 * scripts/tests で `python3 -m unittest <modules...>` を実走し [exitCode, output] を返す。
 *
 * @param  list<string>  $modules
 * @return array{0: int|null, 1: string}
 */
function bitsRunUnittest(array $modules): array
{
    // PYTHONDONTWRITEBYTECODE: __pycache__ を作らせない (scripts/ 配下に git 管理外の
    // 生成物を残さないため。scripts/README.md の台帳の突合は git 追跡下を数えるので
    // 母集団には入らないが、実ディレクトリと目視の一覧を汚す)。
    $process = new Process(
        ['python3', '-m', 'unittest', ...$modules],
        bitsTestsDir(),
        ['PYTHONDONTWRITEBYTECODE' => '1'],
    );
    $process->setTimeout(120);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
    expect((new Process(['which', 'python3']))->run())->toBe(
        0,
        'python3 が PATH に無い。bug-hunt 目録の生成器は python3 必須 (stdlib のみ)。'
    );
});

test('生成器の Python 自己テストが composer test の下で通ること', function (): void {
    expect(is_dir(bitsTestsDir()))->toBeTrue('scripts/tests が見つからない: '.bitsTestsDir());

    [$code, $out] = bitsRunUnittest(['test_bug_hunt_inventory']);

    expect($code)->toBe(0, "bug-hunt 目録の生成器の自己テストが失敗しました:\n".$out);
});

test('負の対照: 存在しないモジュール名を渡すと非 0 になること (空振り gate を作らない)', function (): void {
    [$code] = bitsRunUnittest(['test_no_such_module_exists']);

    expect($code)->not->toBe(0, '存在しないモジュールでも 0 が返る = 実走していない疑い');
});
