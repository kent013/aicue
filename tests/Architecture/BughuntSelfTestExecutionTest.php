<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * bug-hunt harness の**実行配線**ゲート。
 *
 * scripts/bug-hunt-shard.sh self-test は「実資源に触れない自己検証」で、
 * guard / 資源導出 / env 隔離 / worker 停止判定の **実行時挙動** を担う。
 * 既存の Architecture テスト (BughuntShardCapInvariantTest /
 * BughuntOrchestratorGateInvariantTest) は静的構造だけを見ており、self-test を
 * 「参照」はしていても **呼んではいなかった** = 二段防御の片側が自動実行されていなかった。
 * ここで composer test の配線に載せる。
 *
 * 隔離境界はテスト側が握る。self-test は BUGHUNT_SANDBOX が与えられていればそれを使い、
 * 未指定のときだけ mktemp -d する契約になっている。外部指定は「捨ててよい空き地」だけを
 * 受け付けるため専用マーカーを要求する (/ や リポジトリルートを渡す事故を構造的に防ぐ)。
 */

/** self-test へ渡す「捨ててよい空き地」を作る (マーカー必須)。 */
function makeSelfTestSandbox(): string
{
    $dir = sys_get_temp_dir().'/bughunt-selftest-pest-'.bin2hex(random_bytes(6));
    File::makeDirectory($dir, 0700, true);
    File::put($dir.'/.bughunt-selftest-sandbox', '');
    chmod($dir.'/.bughunt-selftest-sandbox', 0600);

    return $dir;
}

test('bug-hunt harness の self-test が通ること', function (): void {
    $script = base_path('scripts/bug-hunt-shard.sh');
    expect(is_readable($script))->toBeTrue();

    $tmp = makeSelfTestSandbox();   // ★ マーカー付き。無いと self-test が die 2 で落ちる

    try {
        // executable bit に依存せず bash 経由で起動する。
        // timeout は実測 ~4 秒に対し 120 秒 (CI の遅さを吸収しつつ無限待ちにしない)。
        $process = Process::timeout(120)
            ->env(['BUGHUNT_SANDBOX' => $tmp, 'TMPDIR' => $tmp])
            ->run(['bash', $script, 'self-test']);

        expect($process->exitCode())->toBe(
            0,
            "self-test が失敗した:\n".$process->output()."\n".$process->errorOutput(),
        );
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('self-test が外から与えた BUGHUNT_SANDBOX を尊重し削除しないこと', function (): void {
    // 「通ること」だけでは隔離境界の退行を検出できない。外から渡した sandbox が
    // 実際に使われ (= その配下に成果物ができ)、かつ **消されない** ことを見る。
    $tmp = makeSelfTestSandbox();

    try {
        $process = Process::timeout(120)
            ->env(['BUGHUNT_SANDBOX' => $tmp, 'TMPDIR' => $tmp])
            ->run(['bash', base_path('scripts/bug-hunt-shard.sh'), 'self-test']);

        expect($process->exitCode())->toBe(0, $process->errorOutput());
        expect(File::isDirectory($tmp))->toBeTrue('外部指定 sandbox が削除された (借り物を消してはならない)');
        expect(File::isDirectory($tmp.'/tmp/bug-hunt'))->toBeTrue(
            '外から与えた BUGHUNT_SANDBOX が使われていない (隔離境界をテストが握れていない)',
        );
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('外部 sandbox はマーカーが無ければ拒否されること', function (): void {
    // 「捨ててよい空き地」の証拠が無いディレクトリを受け付けると、/ や リポジトリルートを
    // 渡された時に実資源へ書き込みうる。拒否そのものを固定する。
    $dir = sys_get_temp_dir().'/bughunt-selftest-nomarker-'.bin2hex(random_bytes(6));
    File::makeDirectory($dir, 0700, true);

    try {
        $process = Process::timeout(120)
            ->env(['BUGHUNT_SANDBOX' => $dir, 'TMPDIR' => $dir])
            ->run(['bash', base_path('scripts/bug-hunt-shard.sh'), 'self-test']);

        expect($process->exitCode())->not->toBe(0, 'マーカー無しの外部 sandbox が受理された');
        expect($process->errorOutput())->toContain('.bughunt-selftest-sandbox');
    } finally {
        File::deleteDirectory($dir);
    }
});
