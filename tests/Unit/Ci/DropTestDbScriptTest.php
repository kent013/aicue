<?php

declare(strict_types=1);

// drop-test-db.php は「直接実行されたときだけ main を走らせる」ので、require しても
// DB へは接続しない (関数定義だけが読み込まれる)。
require_once __DIR__.'/../../../scripts/ci/drop-test-db.php';

/*
 * `scripts/ci/drop-test-db.php` の guard ループと引数解析の Unit テスト。
 *
 * **なぜ実走ではなく単体テストなのか**: `--apply` は LLM / エージェントが
 * 実行してはならない契約なので、実 DROP を伴う経路を実走で検証できない。
 * そこで DDL 実行境界 (`$exec`) を注入し、
 *   1. dev DB (`app` / `bug_hunt*`) と allowlist 外の名前が **executor に一切到達しない**
 *   2. 失敗を握りつぶさず failed として数える (呼び出し側が exit code を分けられる)
 *   3. 引数解析が fail-closed (未知の引数 / 不正 hash / --confirm 無しの --apply を拒否)
 * を実 DB 無しで固定する。
 *
 * 本テストは DB を触らない。
 */

// ── guard ループ: 危険な名前は executor に到達しない ──

it('never passes the dev database to the SQL executor', function (): void {
    $seen = [];
    $outcome = dropTestDbDropAll(function (string $sql) use (&$seen): int {
        $seen[] = $sql;

        return 1;
    }, ['app', 'app_test_8af22c44']);

    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toBe('DROP DATABASE IF EXISTS "app_test_8af22c44" WITH (FORCE)')
        ->and($outcome)->toBe(['dropped' => 1, 'failed' => 0, 'skipped' => 1]);
});

it('never passes bug-hunt databases to the SQL executor', function (string $name): void {
    $seen = [];
    $outcome = dropTestDbDropAll(function (string $sql) use (&$seen): int {
        $seen[] = $sql;

        return 1;
    }, [$name]);

    expect($seen)->toBe([])
        ->and($outcome['skipped'])->toBe(1)
        ->and($outcome['dropped'])->toBe(0);
})->with(['bug_hunt', 'bug_hunt_1', 'bug_hunt_8']);

it('never passes non-allowlisted names to the SQL executor', function (string $name): void {
    $seen = [];
    dropTestDbDropAll(function (string $sql) use (&$seen): int {
        $seen[] = $sql;

        return 1;
    }, [$name]);

    expect($seen)->toBe([]);
})->with(['postgres', 'app_test_XYZ', 'app_test_8af22c44_backup', 'app_test_8AF22C44', '']);

it('drops every allowlisted database exactly once', function (): void {
    $seen = [];
    $outcome = dropTestDbDropAll(function (string $sql) use (&$seen): int {
        $seen[] = $sql;

        return 1;
    }, ['app_test_3a7d6b4e', 'app_test_3a7d6b4e_test_1', 'app_test_3a7d6b4e_test_2']);

    expect($seen)->toHaveCount(3)
        ->and($outcome)->toBe(['dropped' => 3, 'failed' => 0, 'skipped' => 0]);
});

// ── 失敗を握りつぶさない (呼び出し側が exit code を分けられる) ──

it('counts a thrown executor error as a failure without aborting the loop', function (): void {
    $outcome = dropTestDbDropAll(static function (string $sql): int {
        if (str_contains($sql, '_test_1')) {
            throw new RuntimeException('database is being accessed by other users');
        }

        return 1;
    }, ['app_test_3a7d6b4e', 'app_test_3a7d6b4e_test_1', 'app_test_3a7d6b4e_test_2']);

    expect($outcome)->toBe(['dropped' => 2, 'failed' => 1, 'skipped' => 0]);
});

it('counts a false return value as a failure (PDO::exec can return false instead of throwing)', function (): void {
    $outcome = dropTestDbDropAll(static fn (string $sql): bool => false, ['app_test_3a7d6b4e']);

    expect($outcome)->toBe(['dropped' => 0, 'failed' => 1, 'skipped' => 0]);
});

// ── --apply の終了コード判定 (元の指摘「失敗しても exit 0」の直接の回帰) ──

it('exits zero from --apply only when every approved target was dropped', function (): void {
    expect(dropTestDbApplyExitCode(['dropped' => 3, 'failed' => 0, 'skipped' => 0], 3))->toBe(0)
        ->and(dropTestDbApplyExitCode(['dropped' => 0, 'failed' => 0, 'skipped' => 0], 0))->toBe(0);
});

it('exits non-zero from --apply when any target failed or was skipped', function (array $outcome, int $targets): void {
    expect(dropTestDbApplyExitCode($outcome, $targets))->toBe(1);
})->with([
    'DROP が例外で失敗した' => [['dropped' => 2, 'failed' => 1, 'skipped' => 0], 3],
    'guard で skip された' => [['dropped' => 2, 'failed' => 0, 'skipped' => 1], 3],
    '全件失敗した' => [['dropped' => 0, 'failed' => 3, 'skipped' => 0], 3],
    '対象が減っていた' => [['dropped' => 2, 'failed' => 0, 'skipped' => 0], 3],
]);

it('wires the drop outcome into the --apply exit code end to end', function (): void {
    // 実 DROP を伴わずに「guard ループの結果 → 終了コード」の結合を固定する。
    $targets = ['app_test_3a7d6b4e', 'app_test_3a7d6b4e_test_1'];

    $allOk = dropTestDbDropAll(static fn (string $sql): int => 1, $targets);
    $partial = dropTestDbDropAll(static function (string $sql): int {
        if (str_contains($sql, '_test_1')) {
            throw new RuntimeException('database is being accessed by other users');
        }

        return 1;
    }, $targets);

    expect(dropTestDbApplyExitCode($allOk, count($targets)))->toBe(0)
        ->and(dropTestDbApplyExitCode($partial, count($targets)))->toBe(1);
});

it('exits non-zero from --apply if a dev database somehow reached the approved target list', function (): void {
    // 分類側が壊れても、末端 guard が skip し、apply は成功を名乗らない (二重防御)。
    $seen = [];
    $outcome = dropTestDbDropAll(function (string $sql) use (&$seen): int {
        $seen[] = $sql;

        return 1;
    }, ['app', 'app_test_3a7d6b4e']);

    expect($seen)->toHaveCount(1)
        ->and(dropTestDbApplyExitCode($outcome, 2))->toBe(1);
});

// ── 引数解析 (fail-closed) ──

it('defaults to the legacy mode with no arguments', function (): void {
    expect(dropTestDbParseArgs([]))->toBe([
        'orphans' => false, 'apply' => false, 'confirm' => null, 'protect' => [], 'include' => [],
    ]);
});

it('defaults --orphans to dry-run (apply stays false)', function (): void {
    $parsed = dropTestDbParseArgs(['--orphans']);

    expect($parsed['orphans'])->toBeTrue()
        ->and($parsed['apply'])->toBeFalse();
});

it('collects repeatable hash options', function (): void {
    $parsed = dropTestDbParseArgs([
        '--orphans',
        '--include-hash=3a7d6b4e',
        '--include-hash=823cbbd2',
        '--protect-hash=91c7197b',
    ]);

    expect($parsed['include'])->toBe(['3a7d6b4e', '823cbbd2'])
        ->and($parsed['protect'])->toBe(['91c7197b']);
});

it('rejects unknown arguments instead of silently ignoring them', function (): void {
    // `--include-hasch=...` のような typo が「対象 0 件」として黙って通ると危険。
    dropTestDbParseArgs(['--orphans', '--include-hasch=3a7d6b4e']);
})->throws(InvalidArgumentException::class);

it('rejects a bulk flag that was intentionally never implemented', function (): void {
    dropTestDbParseArgs(['--orphans', '--include-unlabeled']);
})->throws(InvalidArgumentException::class);

it('rejects malformed hash options', function (string $arg): void {
    dropTestDbParseArgs(['--orphans', $arg]);
})->with([
    '--include-hash=ZZZZZZZZ',
    '--include-hash=3a7d6b4',
    '--include-hash=3A7D6B4E',
    '--protect-hash=',
    '--protect-hash=not-a-hash',
])->throws(InvalidArgumentException::class);

it('requires --confirm for --apply', function (): void {
    dropTestDbParseArgs(['--orphans', '--apply']);
})->throws(InvalidArgumentException::class);

it('rejects an empty --confirm for --apply', function (): void {
    dropTestDbParseArgs(['--orphans', '--apply', '--confirm=']);
})->throws(InvalidArgumentException::class);

it('rejects --apply without --orphans', function (): void {
    dropTestDbParseArgs(['--apply', '--confirm=deadbeef']);
})->throws(InvalidArgumentException::class);

it('accepts --apply with --orphans and a confirm token', function (): void {
    $parsed = dropTestDbParseArgs(['--orphans', '--apply', '--confirm=abc123']);

    expect($parsed['apply'])->toBeTrue()
        ->and($parsed['confirm'])->toBe('abc123');
});

// ── usage に運用契約が書かれていること (3 箇所のうちの 1 つ) ──

it('states the LLM-must-not-apply contract in the usage text', function (): void {
    expect(dropTestDbUsage())
        ->toContain('--apply')
        ->toContain('LLM')
        ->toContain('ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ')
        ->toContain('--include-hash');
});

// ── 表示ヘルパ ──

it('pads to display width so multibyte columns stay aligned', function (): void {
    expect(mb_strwidth(dropTestDbPad('(ラベルなし)', 20), 'UTF-8'))->toBe(20)
        ->and(mb_strwidth(dropTestDbPad('/workspace', 20), 'UTF-8'))->toBe(20)
        // 幅を超える入力でも最低 1 スペースは入れて列が潰れないようにする
        ->and(dropTestDbPad('0123456789', 5))->toBe('0123456789 ');
});

it('formats byte counts for humans', function (): void {
    expect(dropTestDbHumanBytes(0))->toBe('0.0 kB')
        ->and(dropTestDbHumanBytes(512 * 1024))->toBe('512.0 kB')
        ->and(dropTestDbHumanBytes(14 * 1024 * 1024))->toBe('14.0 MB')
        // 1 MiB がちょうど境界 (kB 側に落ちない)
        ->and(dropTestDbHumanBytes(1024 * 1024))->toBe('1.0 MB');
});
