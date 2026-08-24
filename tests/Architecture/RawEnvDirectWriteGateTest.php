<?php

declare(strict_types=1);

use Tests\Support\RawEnv\RawEnvDirectWriteAllowance;
use Tests\Support\RawEnv\RawEnvDirectWriteScanner;
use Tests\Support\RawEnv\RawEnvWriteKind;
use Tests\Support\RawEnv\RawEnvWriteSite;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: 生の環境変数 3 面 (`$_SERVER` / `$_ENV` / `putenv`) への
 * **直接の書き込み**は `Tests\Support\RawEnv\RawEnvSnapshot` へ集約されており、
 * 部品の外には現れない (家系の正典 raw-env-snapshot-restore v1 の i12)。
 *
 * なぜ要るか: PHP では 1 つの環境変数がプロセスの中で 3 面に現れ、Laravel の `env()` は
 * `$_SERVER` → `$_ENV` → `putenv` の順に **live で**読む。テストが 1 面だけ戻すと、
 * 残った面の古い値が先に読まれ、あとから走る別のテストの入力が静かに変わる。
 * 撮影 PWA の秘匿と本番構成の起動時 fail-fast を守る検査
 * (`ProductionEnvGuardTest` / `ConfigHardeningTest` / `PasskeyOriginDeclarationTest`) は
 * すべて 3 面を差し替えて動くので、その土台が揺れると**守りの主張そのものが信用できなくなる**。
 *
 * ★**この gate の主張は「3 面への直接の書き込みが 1 件も無い」ではない**。
 *   「`RawEnvDirectWriteScanner` が列挙した**字句の書き込み形**が、許可した 3 か所以外に
 *   1 件も無い」である。検出しない構文 (可変関数呼び出し / `call_user_func` /
 *   値渡しで受けた先の書き換え / ライブラリ経由 / ヒアドキュメント本文) の一覧は
 *   **走査器の docblock が正本**であり、ここには写さない (2 か所に書くと必ず食い違う)。
 * ★母集団は `Tests\Support\TrackedPhpSourceFiles` (git 追跡下の `*.php` から blade を除く) から
 *   **`devnotes/` 配下だけを除いたもの**である。除外が 1 つだけであることと、
 *   除外が形骸化していないことは G3〜G5 が機械で見る。
 *   追跡 PHP の**総数そのものは pin しない** — 無関係な PHP を 1 本足すだけで赤くなり、
 *   守りたい性質 (黙って走査から落ちない) は恒等式のほうが強く固定できるため。
 * ★`unresolved` は**目録へ登録できない** (G7)。未解決を免除で黙らせる経路を作らない。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/** 許可の根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const RAW_ENV_DIRECT_WRITE_REASON_MIN_LENGTH = 30;

/** 走査対象ファイル数の床値 (走査が空振り 0 件でも「違反 0 件」で緑になるのを止める)。 */
const RAW_ENV_DIRECT_WRITE_SCANNED_FILE_FLOOR = 1900;

/** 母集団から外す唯一の置き場 (一時スクリプトの置き場であり実行経路にも CI にも載らない)。 */
const RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX = 'devnotes/';

/**
 * 3 面へ直接書いてよい置き場の目録 (型付き + 具体的根拠必須 + 件数の完全一致)。
 *
 * ★**免除ではなく「1 件の事実」の登録**である。件数は完全一致で、増えても減っても赤になる。
 * ★`unresolved` は登録できない (G7 が別途赤にする)。
 *
 * @return array<string, array{
 *     allowance: RawEnvDirectWriteAllowance,
 *     counts: array<string, int>,
 *     reason: non-empty-string,
 * }>
 */
function rawEnvDirectWriteAllowances(): array
{
    return [
        'tests/Support/RawEnv/RawEnvSnapshot.php' => [
            'allowance' => RawEnvDirectWriteAllowance::ComponentItself,
            'counts' => ['element_assign' => 4, 'element_unset' => 4, 'putenv' => 4],
            'reason' => '3 面の退避・注入・復元を担う部品そのもの。ここへ集約するために'
                .'他のすべての置き場から直接の書き込みを取り上げている (正典 v1 の i1)。',
        ],
        'tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php' => [
            'allowance' => RawEnvDirectWriteAllowance::ComponentContractTest,
            'counts' => ['element_assign' => 7, 'element_unset' => 4, 'putenv' => 6],
            'reason' => '部品の契約テスト。検査対象である部品を使わずに 3 面の状態を作らないと'
                .'往復そのものを検査できない (部品で作った状態を部品で確かめると同語反復になる)。',
        ],
        'tests/bootstrap.php' => [
            'allowance' => RawEnvDirectWriteAllowance::PreFrameworkBootstrap,
            'counts' => ['element_assign' => 2, 'putenv' => 1],
            'reason' => '枠組みが立ち上がる前の足場。テスト DB 名を 3 面へ注入してから'
                .'単一点ガードを走らせる位置であり、autoload された部品を呼べる段階より前に動く。',
        ],
    ];
}

/**
 * 母集団 (走査対象) と除外集合を分けて返す。
 *
 * @return array{scanned: list<array{absolute: string, relative: string}>, excluded: list<string>}
 */
function rawEnvDirectWritePopulation(): array
{
    $scanned = [];
    $excluded = [];

    foreach (TrackedPhpSourceFiles::all(base_path()) as $target) {
        if (str_starts_with($target['relative'], RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX)) {
            $excluded[] = $target['relative'];

            continue;
        }

        $scanned[] = $target;
    }

    return ['scanned' => $scanned, 'excluded' => $excluded];
}

/**
 * 走査対象を全数走査し、ファイルごとの検出結果を返す (読めないファイルは fail-closed)。
 *
 * @param  list<array{absolute: string, relative: string}>  $targets
 * @return array<string, list<RawEnvWriteSite>>
 */
function rawEnvDirectWriteScanAll(array $targets): array
{
    $found = [];

    foreach ($targets as $target) {
        $source = file_get_contents($target['absolute']);

        if ($source === false) {
            // 無音 skip すると書き込みを見逃す (fail-open) ため、読めないファイルは落とす。
            throw new RuntimeException("読み取れないファイルがあります: {$target['relative']}");
        }

        $sites = RawEnvDirectWriteScanner::scan($source);

        if ($sites !== []) {
            $found[$target['relative']] = $sites;
        }
    }

    return $found;
}

/**
 * 検出結果を種別ごとの件数へ畳む。
 *
 * @param  list<RawEnvWriteSite>  $sites
 * @return array<string, int>
 */
function rawEnvDirectWriteCounts(array $sites): array
{
    $counts = [];

    foreach ($sites as $site) {
        $counts[$site->kind->value] = ($counts[$site->kind->value] ?? 0) + 1;
    }

    ksort($counts);

    return $counts;
}

/**
 * 違反の報告文 (直し方まで書く)。
 *
 * @param  array<string, list<RawEnvWriteSite>>  $violations
 */
function rawEnvDirectWriteFailureMessage(array $violations): string
{
    $lines = [];

    foreach ($violations as $relative => $sites) {
        $detail = implode(', ', array_map(
            static fn (RawEnvWriteSite $site): string => "{$site->kind->value}@L{$site->line}({$site->subject})",
            $sites,
        ));
        $lines[] = "  - {$relative}: {$detail}";
    }

    return '生の環境変数 3 面への直接の書き込みが部品の外にあります ('.count($violations).' ファイル):'
        .PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL
        .'Tests\Support\RawEnv\RawEnvSnapshot の with() / captureAndClear() + restore() へ寄せてください。'
        .PHP_EOL.'(許可 3 か所を増やす選択肢は取らない。設計フローを通してから機構を変えること。)';
}

test('G1: 列挙した字句の書き込み形が許可 3 か所以外に 1 件も無い', function (): void {
    $population = rawEnvDirectWritePopulation();
    $found = rawEnvDirectWriteScanAll($population['scanned']);
    $allowed = rawEnvDirectWriteAllowances();

    $violations = array_diff_key($found, $allowed);

    expect($violations)->toBe([], rawEnvDirectWriteFailureMessage($violations));
});

test('G2: 走査対象ファイル数が床値以上である (空振りの検出)', function (): void {
    $population = rawEnvDirectWritePopulation();

    expect($population['scanned'])->not->toBeEmpty()
        ->and(count($population['scanned']))->toBeGreaterThanOrEqual(RAW_ENV_DIRECT_WRITE_SCANNED_FILE_FLOOR);
});

test('G3: 走査対象数 + 除外数 = 追跡 PHP 総数 (黙って落ちるファイルが無い)', function (): void {
    $population = rawEnvDirectWritePopulation();
    $tracked = TrackedPhpSourceFiles::all(base_path());

    expect(count($population['scanned']) + count($population['excluded']))->toBe(count($tracked));
});

test('G4: 除外集合が devnotes/ 配下と完全一致する', function (): void {
    // ★定数値そのものを独立した期待値と突き合わせる。走査側も検査側も同じ定数を使うので、
    //   定数を書き換えると (床値を満たす限り) G3〜G5 が緑のまま除外が広がりうる。
    expect(RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX)->toBe('devnotes/');

    $population = rawEnvDirectWritePopulation();

    foreach ($population['excluded'] as $relative) {
        expect(str_starts_with($relative, RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX))->toBeTrue(
            "除外集合に devnotes/ 以外が入っています: {$relative}"
        );
    }

    foreach ($population['scanned'] as $target) {
        expect(str_starts_with($target['relative'], RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX))->toBeFalse(
            "走査対象に devnotes/ が残っています: {$target['relative']}"
        );
    }
});

test('G5: devnotes/ に追跡 PHP が実在する (除外の形骸化の検出)', function (): void {
    $population = rawEnvDirectWritePopulation();

    expect($population['excluded'])->not->toBeEmpty();
});

test('G6: 目録の登録先ファイルが実在する', function (): void {
    foreach (array_keys(rawEnvDirectWriteAllowances()) as $relative) {
        expect(is_file(base_path($relative)))->toBeTrue("目録の登録先が実在しません: {$relative}");
    }
});

test('G7: 目録に unresolved を登録していない', function (): void {
    foreach (rawEnvDirectWriteAllowances() as $relative => $entry) {
        expect(array_key_exists(RawEnvWriteKind::Unresolved->value, $entry['counts']))->toBeFalse(
            "unresolved は免除で黙らせられません: {$relative}"
        );
    }
});

test('G8: 目録の実測件数が登録件数と完全一致する', function (): void {
    $population = rawEnvDirectWritePopulation();
    $found = rawEnvDirectWriteScanAll($population['scanned']);

    foreach (rawEnvDirectWriteAllowances() as $relative => $entry) {
        $actual = rawEnvDirectWriteCounts($found[$relative] ?? []);
        $expected = $entry['counts'];
        ksort($expected);

        expect($actual)->toBe($expected, "目録の件数が実測と食い違っています: {$relative}");
    }
});

test('G9: 目録の根拠が具体的である', function (): void {
    foreach (rawEnvDirectWriteAllowances() as $relative => $entry) {
        expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(
            RAW_ENV_DIRECT_WRITE_REASON_MIN_LENGTH,
            "根拠が短すぎます: {$relative}"
        );
    }
});

test('G10: 許可パス集合が期待する 3 パスと完全一致する', function (): void {
    expect(array_keys(rawEnvDirectWriteAllowances()))->toBe([
        'tests/Support/RawEnv/RawEnvSnapshot.php',
        'tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php',
        'tests/bootstrap.php',
    ]);
});

test('G11: 各パスと許可の分類の対応が完全一致する', function (): void {
    $actual = array_map(
        static fn (array $entry): string => $entry['allowance']->value,
        rawEnvDirectWriteAllowances(),
    );

    expect($actual)->toBe([
        'tests/Support/RawEnv/RawEnvSnapshot.php' => RawEnvDirectWriteAllowance::ComponentItself->value,
        'tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php' => RawEnvDirectWriteAllowance::ComponentContractTest->value,
        'tests/bootstrap.php' => RawEnvDirectWriteAllowance::PreFrameworkBootstrap->value,
    ]);
});

test('G12: 目録の counts のキーが既知の種別で、件数が正の整数である', function (): void {
    $known = array_map(static fn (RawEnvWriteKind $kind): string => $kind->value, RawEnvWriteKind::cases());

    foreach (rawEnvDirectWriteAllowances() as $relative => $entry) {
        foreach ($entry['counts'] as $kind => $count) {
            expect(in_array($kind, $known, true))->toBeTrue("未知の種別が登録されています: {$relative} / {$kind}");
            expect($count)->toBeGreaterThan(0, "件数は正の整数である必要があります: {$relative} / {$kind}");
        }
    }
});

test('G13: 代表パスが母集団に実在する (走査根が生きていること)', function (): void {
    $population = rawEnvDirectWritePopulation();
    $relatives = array_map(
        static fn (array $target): string => $target['relative'],
        $population['scanned'],
    );

    foreach (array_keys(rawEnvDirectWriteAllowances()) as $relative) {
        expect(in_array($relative, $relatives, true))->toBeTrue("母集団から代表パスが消えています: {$relative}");
    }
});
