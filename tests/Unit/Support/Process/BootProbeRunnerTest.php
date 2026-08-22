<?php

declare(strict_types=1);

use Tests\Support\Process\BootProbeRunner;

/*
| 起動 probe の共通 runner (`Tests\Support\Process\BootProbeRunner`) の自己検査
| (lctl feature: subprocess-boot-probe-harness の正典 v1 (6) = 「自己検査を持つ」)。
|
| runner は「何を観測するか」を持たない道具なので、道具そのものの契約 —
| 環境変数の 3 段合成 / 予約鍵 / 終了コードの保持 / 制限時間と強制終了 / 出力の逐次読み /
| 書き出し先の退避と後片付け — をここで固定する。
|
| **この自己検査が測らないこと** (詳細設計 P2 と同じ粒度):
|
|  1. runner 自身の途中失敗 (`stream_select` の失敗など) を**注入した**経路は測らない。
|     注入の継ぎ目を公開面へ足す方が害が大きい
|  2. 起動そのものが失敗する経路 (`proc_open` の失敗) も測らない。移植性のある誘発手段が無い
|     (常に `PHP_BINARY` と実在する作業ディレクトリで起こすため)
|  3. 「回収不能なら一時ディレクトリを残す」経路も測らない。子は `SIGKILL` を無視できないので、
|     移植性のある形でこの状態を作れない
|
| 測るのは 2 方向である: 「落とせない子を確実に落とす」(S12 / S14) と
| 「起動前の fail-closed で残骸を残さない」(S11)。
*/

/** 親 env の漏れを見るための番兵 (S1)。 */
const BOOT_PROBE_SENTINEL_KEY = 'BOOT_PROBE_SENTINEL';

/**
 * 子が受け取った環境変数を**丸ごと** JSON で報告させる probe (S1 / S2 / S3 / S4)。
 *
 * 鍵を列挙して問い合わせる形にすると「列挙に無い鍵が増えても緑」になる (基底に 1 本足しても
 * 気づけない)。集合そのものを持ち帰らせて完全一致で測る。
 */
const BOOT_PROBE_ENV_REPORT = <<<'PHP'
    echo json_encode(getenv());
    PHP;

/** アプリを起こして書き出し先を JSON で報告させる probe (S9 / S10)。 */
const BOOT_PROBE_PATH_REPORT = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Log::info('boot-probe self check');
    echo json_encode([
        'php_binary' => PHP_BINARY,
        'storage' => $app->storagePath(),
        'config_cache' => $app->getCachedConfigPath(),
        'routes_cache' => $app->getCachedRoutesPath(),
        'services_cache' => $app->getCachedServicesPath(),
        'packages_cache' => $app->getCachedPackagesPath(),
        'events_cache' => $app->getCachedEventsPath(),
        'view_compiled' => (string) config('view.compiled'),
        'log_path' => (string) config('logging.channels.single.path'),
    ]);
    PHP;

/**
 * 子の JSON 報告を配列で受け取る。
 *
 * @param  array<non-empty-string, string>  $env
 * @return array<string, mixed>
 */
function bootProbeDecodeReport(string $code, array $env = []): array
{
    $result = BootProbeRunner::run(['-r', $code], $env);

    expect($result->exitCode)->toBe(0, "probe が異常終了した: {$result->stderr}");

    /** @var mixed $decoded */
    $decoded = json_decode(trim($result->stdout), true);
    expect($decoded)->toBeArray("probe の出力が JSON でない: {$result->stdout} / {$result->stderr}");
    assert(is_array($decoded));

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

test('S1: 親の環境変数は子に現れない', function (): void {
    putenv(BOOT_PROBE_SENTINEL_KEY.'=leaked');

    try {
        $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);

        expect($report)->not->toHaveKey(BOOT_PROBE_SENTINEL_KEY, '親の env が子へ漏れている');
    } finally {
        putenv(BOOT_PROBE_SENTINEL_KEY);
    }
});

test('S2: 許可した継承は規則どおり届く (親に無い鍵は子にも無い)', function (): void {
    // 継承する鍵の一覧そのものをリテラルで pin する。実装と定数を同時に削っても緑になる形
    // (期待値を実装側の定数から組み立てるだけの検査) を避ける。
    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR']);

    $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);

    foreach (BootProbeRunner::INHERITED_ENV_KEYS as $key) {
        $parent = getenv($key);
        // runner と同じ規則 (文字列かつ非空のときだけ継承する) で期待値を組む。
        // 環境によって HOME / TMPDIR が無くても偽レッドにしない。
        if (is_string($parent) && $parent !== '') {
            expect($report[$key] ?? null)->toBe($parent, "継承鍵 {$key} が子へ届いていない");

            continue;
        }

        expect($report)->not->toHaveKey($key, "親に無い継承鍵 {$key} を子が持っている");
    }
});

test('S3: ケース別上書きが基底に勝つ (正典 v1 の順序)', function (): void {
    $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT, ['CACHE_STORE' => 'file']);

    expect($report['CACHE_STORE'])->toBe('file', 'ケース別上書きが基底に負けている');
});

test('S4: 子が受け取る env の集合が完全一致する (基底 3 本 + 予約 7 本 + 継承分だけ)', function (): void {
    $result = BootProbeRunner::run(['-r', BOOT_PROBE_ENV_REPORT]);
    expect($result->exitCode)->toBe(0, $result->stderr);

    /** @var array<string, string> $report */
    $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);

    // (a) 集合の完全一致。「以下」ではないので、基底に 1 本足しただけでも赤くなる。
    $inherited = array_values(array_filter(
        BootProbeRunner::INHERITED_ENV_KEYS,
        static function (string $key): bool {
            $value = getenv($key);

            return is_string($value) && $value !== '';
        },
    ));
    $expectedKeys = array_merge(
        $inherited,
        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
        BootProbeRunner::RESERVED_ENV_KEYS,
    );
    sort($expectedKeys);
    $actualKeys = array_keys($report);
    sort($actualKeys);

    expect($actualKeys)->toBe($expectedKeys, '子が受け取る env の集合が契約と違う');

    // (b) 基底 3 本の値
    expect($report['APP_KEY'])->not->toBe('')
        ->and($report['QUEUE_CONNECTION'])->toBe('database')
        ->and($report['CACHE_STORE'])->toBe('array');

    // (c) 予約鍵 7 本は一時ディレクトリ配下を指す
    foreach (BootProbeRunner::RESERVED_ENV_KEYS as $key) {
        expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
            ->toBeTrue("予約鍵 {$key} が一時ディレクトリの外を指している: {$report[$key]}");
    }

    // (d) 集合一致の系として、APP_ENV とロケール系が入っていないことを名指しでも書く
    // (「渡さない実行は素の .env を読む」は BughuntFakeWiringTest の複数ケースの前提である)。
    expect($report)->not->toHaveKey('APP_ENV')
        ->and($report)->not->toHaveKey('LANG')
        ->and($report)->not->toHaveKey('LC_ALL');
});

test('S5: 予約鍵は呼び出し側から渡せない', function (): void {
    expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], ['LARAVEL_STORAGE_PATH' => '/tmp/x']))
        ->toThrow(RuntimeException::class);
});

test('S6: 終了コードを保持する', function (): void {
    $result = BootProbeRunner::run(['-r', 'exit(7);']);

    expect($result->exitCode)->toBe(7, '終了コードが proc_close の戻り値で潰れている')
        ->and($result->timedOut)->toBeFalse();
});

test('S7: 制限時間を超えた子を強制終了する', function (): void {
    $result = BootProbeRunner::run(['-r', 'sleep(30);'], timeoutSeconds: 1);

    expect($result->timedOut)->toBeTrue('制限時間を超えた子が落ちていない')
        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
        // 実時間で狭く測らない (CI の負荷で偽レッドにしない)。上限は
        // 制限時間 + 猶予 + 最終期限 + 余裕で見る。
        ->and($result->elapsedSeconds)->toBeGreaterThanOrEqual(1.0)
        ->and($result->elapsedSeconds)->toBeLessThan(
            1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS + BootProbeRunner::KILL_WAIT_SECONDS + 5.0,
        );
});

test('S8: 大量出力で詰まらない', function (): void {
    // パイプバッファは 64KiB 程度なので、逐次読みでなければ子が固まって S7 経路に落ちる。
    $result = BootProbeRunner::run(['-r', 'echo str_repeat("x", 1048576);']);

    expect($result->exitCode)->toBe(0, $result->stderr)
        ->and(strlen($result->stdout))->toBe(1048576)
        ->and($result->timedOut)->toBeFalse();
});

test('S9: 書き出し先の退避が効いている (向き) / 親と同じ実行体で起きる', function (): void {
    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['LOG_CHANNEL' => 'single']);
    expect($result->exitCode)->toBe(0, $result->stderr);

    /** @var array<string, string> $report */
    $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);

    // 正典 (1): 親と同じ実行体で起こす。
    expect($report['php_binary'])->toBe(PHP_BINARY);

    foreach (['storage', 'config_cache', 'routes_cache', 'services_cache', 'packages_cache',
        'events_cache', 'view_compiled', 'log_path'] as $key) {
        expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
            ->toBeTrue("書き出し先 {$key} がリポジトリ側を指している: {$report[$key]}");
    }
});

test('S10: 書き出し先の退避が効いている (実体) と後片付け', function (): void {
    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['LOG_CHANNEL' => 'single']);

    expect($result->exitCode)->toBe(0, $result->stderr)
        ->and($result->writtenRelativePaths)->toContain('storage/logs/laravel.log')
        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');
});

test('S11: 一時ディレクトリがリポジトリ内なら起動前に失敗し残骸を残さない', function (): void {
    $base = base_path('storage/framework/testing');
    if (! is_dir($base)) {
        mkdir($base, 0o755, true);
    }

    $before = glob($base.'/boot-probe-*');
    expect($before)->toBeArray();
    assert(is_array($before));

    expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], temporaryBase: $base))
        ->toThrow(RuntimeException::class);

    $after = glob($base.'/boot-probe-*');
    expect($after)->toBe($before, '起動前の fail-closed が残骸を残している');

    // 境界判定そのものを pin する (`/repo` と `/repository` を取り違えない)。
    expect(BootProbeRunner::isInside('/repo', '/repo'))->toBeTrue()
        ->and(BootProbeRunner::isInside('/repo', '/repo/inner'))->toBeTrue()
        ->and(BootProbeRunner::isInside('/repo', '/repository'))->toBeFalse()
        ->and(BootProbeRunner::isInside('/repo/', '/repo/inner'))->toBeTrue();
});

test('S12: 管を早く閉じた子でも確実に落として回収する', function (): void {
    // 子は標準出力・標準エラーを閉じてから寝る。管の EOF だけを終了検知に使う実装は
    // ここで無限に待つ (= 制限時間が効いていない) ことになる。
    $result = BootProbeRunner::run(
        ['-r', 'fclose(STDOUT); fclose(STDERR); sleep(30);'],
        timeoutSeconds: 1,
    );

    expect($result->timedOut)->toBeTrue()
        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');

    if (! function_exists('posix_kill')) {
        return;
    }

    // 回収済みなら pid はもう存在しない (ps ではなく runner が握っていた pid を直接見る)。
    expect(posix_kill($result->pid, 0))->toBeFalse('子プロセスが残っている');
});

test('S13: 子の終了後も読み切り、その最終読み取りには上限がある', function (): void {
    // 子は孫へ標準出力・標準エラーを渡したまま先に終了する。2 方向を同時に測る:
    //  - 上限が無い実装は、孫が寝ている間ずっと戻れない (孫は回収しない = 保証しないことの 1 つ)
    //  - 最終読み取りが無い実装は、子の終了後に届いた印を取りこぼす
    $code = <<<'PHP'
        $child = proc_open(
            [PHP_BINARY, '-r', 'usleep(300000); fwrite(STDOUT, "DRAINED"); sleep(6);'],
            [1 => STDOUT, 2 => STDERR],
            $pipes,
        );
        exit(3);
        PHP;

    $result = BootProbeRunner::run(['-r', $code]);

    // toContain は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
    expect($result->stdout)->toContain('DRAINED');

    expect($result->exitCode)->toBe(3, '子の終了コードを取りこぼしている')
        ->and($result->timedOut)->toBeFalse()
        ->and($result->elapsedSeconds)->toBeLessThan(
            BootProbeRunner::FINAL_DRAIN_SECONDS + 2.5,
            '孫が管を持っている間ずっと待っている (最終読み取りの上限が効いていない)',
        );
});

test('S14: 終了要求を無視する子は強制終了で落とす (段階的強制終了)', function (): void {
    // S7 / S12 の子は SIGTERM で死ぬので、SIGKILL への昇格を消しても緑になってしまう。
    // ここは**終了要求を無視する子**を使い、猶予の後の強制終了まで到達させる。
    $result = BootProbeRunner::run(
        ['-r', 'pcntl_signal(SIGTERM, SIG_IGN); sleep(30);'],
        timeoutSeconds: 1,
    );

    expect($result->timedOut)->toBeTrue()
        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
        // 終了要求では死なないので、猶予ぶんは必ず経過している (= SIGKILL 経路を通った)。
        ->and($result->elapsedSeconds)->toBeGreaterThanOrEqual(1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS)
        ->and($result->elapsedSeconds)->toBeLessThan(
            1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS + BootProbeRunner::KILL_WAIT_SECONDS,
            '最終期限を超えるまで落とせていない',
        )
        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');

    if (! function_exists('posix_kill')) {
        return;
    }

    expect(posix_kill($result->pid, 0))->toBeFalse('強制終了しても子が残っている');
})->skip(
    // 子は親と同じ実行体なので、親に ext-pcntl が無ければ子にも無い。
    // **成功扱いにはしない** — 段階的強制終了を測れていないことをテスト結果に出す。
    fn (): bool => ! function_exists('pcntl_signal'),
    'ext-pcntl が無い環境では終了要求を無視する子を作れず、段階的強制終了を測れない',
);
