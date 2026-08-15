<?php

declare(strict_types=1);

use Tests\Support\GlobalUse\NonCompoundGlobalUseScanner;
use Tests\Support\GlobalUse\PhpLintOracle;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: **グローバル名前空間**にあるコードで非複合名の `use` を書かない。
 *
 * SoT = PHP の言語仕様であり、**真値は `php -l` の警告**である (家系の正典 t1)。
 *   Warning: The use statement with non-compound name 'X' has no effect
 * この警告が出る形は 3 種の取り込み (`use` / `use function` / `use const`) すべてで、
 * 先頭にバックスラッシュを付けた形でも同じである (実測)。
 * 逆に**別名が付いた形 (`use Foo as Bar;`) には警告が出ない** — 別名の付いた取り込みは
 * 実際に効くためで、これを違反として数えるのは偽陽性である。
 *
 * なぜ「出力が汚れるだけ」で済ませないか (実測):
 *   - この警告が set_error_handler に届くかは **環境依存** (opcache 状態 /
 *     ファイルの初回コンパイル時点)。同一 devcontainer で「届く」「届かない」両方を観測した
 *   - 届いた場合、Laravel の HandleExceptions::handleError は
 *     `error_reporting() & $level` (本アプリは -1) で **ErrorException を throw する**
 *   - migration は Migrator が実行時に require する = そこで throw されれば
 *     RefreshDatabase が死に **全テストが全滅する**
 * つまり「今日は raw output 汚染で済んでいるが、いつ全滅へ化けてもおかしくない非決定的な地雷」。
 *
 * 走査対象: git 追跡下の *.php (ただし *.blade.php を除く)。列挙は
 * `Tests\Support\TrackedPhpSourceFiles` に集約してある (同じ列挙を 2 本持たない。
 * 走査域の定義と限界は同クラスの docblock が正本)。
 * git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 * **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * **既知の限界**: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
 * commit / CI であり、そこでは必ず追跡下にあるため実効性は損なわれない。
 *
 * allowlist は設けない: 非複合 global use に正当な用途は存在しない (常に無効な import)。
 *
 * ★**検出力の裏取り**: 見本 12 本 (検出 7 / 無違反 5) を `php -l` の警告と
 *   名前・行番号まで照合する。見本は `.php.txt` で置く — `.php` にすると
 *   本 gate 自身と `StrictTypesDeclarationGateTest` /
 *   `ForbiddenStatementTokenInvariantTest` の母集団に入り、
 *   **わざと違反させた見本で本番の gate が赤くなる** (`php -l` は拡張子を見ない)。
 * ★**照合の空振りも検知する**: `php -l` の警告文が将来変わると真値が 0 件になり、
 *   照合が「両方 0 件で一致」して静かに無力化する。真値の総数の床を別の検査で固定する。
 */

/** 見本の置き場所 (走査器の自己検査の入力)。 */
const GLOBAL_USE_FIXTURE_DIR = __DIR__.'/fixtures/global-use';

/**
 * 見本の完全な一覧。差し替え・こっそり削除で検出力が落ちるのを止める。
 *
 * @var array<string, bool> 見本名 => 検出側か (true = 警告が出る形)
 */
const GLOBAL_USE_FIXTURES = [
    'detects-class' => true,
    'detects-function-const' => true,
    'detects-leading-backslash' => true,
    'detects-comma-list' => true,
    'detects-partial-alias' => true,
    'detects-bracketed-global' => true,
    'detects-bracketed-after-named' => true,
    'clean-compound' => false,
    'clean-aliased' => false,
    'clean-named-namespace' => false,
    'clean-bracketed-named' => false,
    'clean-trait-and-closure' => false,
];

/**
 * 見本 1 本につき `php -l` を **1 回だけ**実行した結果。
 *
 * ★各検査の中から `inspect()` を呼ぶ形にすると、「同じ 1 回の結果を共有する」という
 *   契約が書いてあるだけになり、同じ見本を何度も実行しやすくなる。ここで 1 度だけ回す。
 *
 * @var array<string, array{
 *     warnings: list<array{name: string, line: int}>,
 *     syntaxValid: bool,
 *     exitCode: int,
 *     stdout: string,
 *     stderr: string,
 * }>
 */
$globalUseOracle = [];
foreach (array_keys(GLOBAL_USE_FIXTURES) as $globalUseFixtureName) {
    $globalUseOracle[$globalUseFixtureName] = PhpLintOracle::inspect(
        GLOBAL_USE_FIXTURE_DIR.'/'.$globalUseFixtureName.'.php.txt'
    );
}

/**
 * 名前と行の一覧を、両側で同じ規則に整列する。
 *
 * ★**集合にしない**。同じ名前・同じ行の警告が 2 回出る場合に、集合化すると
 *   走査器側の重複や欠落を隠してしまう。重複を保ったまま整列して比べる。
 *
 * @param  list<array{name: string, line: int}>  $entries
 * @return list<string>
 */
function globalUseSorted(array $entries): array
{
    $formatted = array_map(
        static fn (array $entry): string => sprintf('%d:%s', $entry['line'], $entry['name']),
        $entries,
    );
    sort($formatted);

    return $formatted;
}

/**
 * 見本を走査器に掛ける。
 *
 * @return array{
 *     violations: list<array{name: string, line: int}>,
 *     hasGlobalRegion: bool,
 *     unresolved: list<string>,
 * }
 */
function globalUseScanFixture(string $name): array
{
    $path = GLOBAL_USE_FIXTURE_DIR.'/'.$name.'.php.txt';
    $source = file_get_contents($path);

    if ($source === false) {
        throw new RuntimeException('見本を読めませんでした: '.$path);
    }

    return NonCompoundGlobalUseScanner::scan($source, $name.'.php.txt');
}

/**
 * git 追跡下全体の走査結果。
 *
 * @return array{
 *     violations: list<string>,
 *     globalRegionFiles: list<string>,
 *     unresolved: list<string>,
 *     totalFiles: int,
 * }
 */
function globalUseScanTrackedTree(): array
{
    $violations = [];
    $globalRegionFiles = [];
    $unresolved = [];
    $total = 0;

    foreach (TrackedPhpSourceFiles::all(base_path()) as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $total++;

        $scanned = NonCompoundGlobalUseScanner::scan($source, $target['relative']);

        if ($scanned['hasGlobalRegion']) {
            $globalRegionFiles[] = $target['relative'];
        }
        foreach ($scanned['violations'] as $violation) {
            $violations[] = sprintf('%s:%d → use %s;', $target['relative'], $violation['line'], $violation['name']);
        }
        $unresolved = array_merge($unresolved, $scanned['unresolved']);
    }

    return [
        'violations' => $violations,
        'globalRegionFiles' => $globalRegionFiles,
        'unresolved' => $unresolved,
        'totalFiles' => $total,
    ];
}

test('グローバル名前空間に非複合 use が存在しない', function (): void {
    $result = globalUseScanTrackedTree();

    expect($result['violations'])->toBe([],
        '非複合 global use を検出しました。PHP は「has no effect」warning を出し import は無効です。'
        .'use 文を削除して参照側を \\FQCN (例: \\RuntimeException) にしてください。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

test('走査が空振りしていない (母集団と走査域が縮退していない)', function (): void {
    $result = globalUseScanTrackedTree();

    expect($result['totalFiles'])->toBeGreaterThan(0);

    // 件数の床は置かない (整理で自然に減ることは正常であり、本質でない赤を生む)。
    // 目的に直結するのは「グローバル領域を持つファイルが 1 本も無くなっていないこと」と
    // 「構造的に名前空間を持たない置き場がどちらも生きていること」である。
    expect($result['globalRegionFiles'])->not->toBeEmpty();

    $hasMigration = array_filter(
        $result['globalRegionFiles'],
        static fn (string $relative): bool => str_starts_with($relative, 'database/migrations/'),
    );
    $hasArchitectureTest = array_filter(
        $result['globalRegionFiles'],
        static fn (string $relative): bool => str_starts_with($relative, 'tests/Architecture/'),
    );

    expect($hasMigration)->not->toBeEmpty('database/migrations/ が走査域から落ちています');
    expect($hasArchitectureTest)->not->toBeEmpty('tests/Architecture/ が走査域から落ちています');

    // 読めなかった namespace 宣言は黙って対象外にしない (fail-closed)。
    expect($result['unresolved'])->toBe([], implode(PHP_EOL, $result['unresolved']));
});

test('見本の一覧が完全である (差し替え・削除で検出力が落ちない)', function (): void {
    $onDisk = glob(GLOBAL_USE_FIXTURE_DIR.'/*.php.txt');
    expect($onDisk)->toBeArray();

    $actual = array_map(
        static fn (string $path): string => basename($path, '.php.txt'),
        is_array($onDisk) ? $onDisk : [],
    );
    sort($actual);

    $expected = array_keys(GLOBAL_USE_FIXTURES);
    sort($expected);

    expect($actual)->toBe($expected);
    expect(count(array_filter(GLOBAL_USE_FIXTURES)))->toBe(7);
    expect(count(array_filter(GLOBAL_USE_FIXTURES, static fn (bool $d): bool => ! $d)))->toBe(5);
});

test('見本が構文として正しい (判定は終了コード)', function () use ($globalUseOracle): void {
    foreach ($globalUseOracle as $name => $inspection) {
        expect($inspection['syntaxValid'])->toBeTrue(sprintf(
            "見本 %s が構文として正しくありません。見本が parse error になると警告が 1 件も出ず、\n"
            ."検出力が落ちたのか見本が壊れたのかを切り分けられなくなります。\n"
            ."PHP_VERSION=%s PHP_BINARY=%s exitCode=%d\n--- stdout ---\n%s\n--- stderr ---\n%s",
            $name,
            PHP_VERSION,
            PHP_BINARY,
            $inspection['exitCode'],
            $inspection['stdout'],
            $inspection['stderr'],
        ));
    }
});

test('真値が空振りしていない (php -l の警告文の変化を検知する)', function () use ($globalUseOracle): void {
    $total = 0;
    $diagnostics = [];

    foreach (GLOBAL_USE_FIXTURES as $name => $detects) {
        if (! $detects) {
            continue;
        }
        $total += count($globalUseOracle[$name]['warnings']);
        $diagnostics[] = sprintf(
            "--- %s (exitCode=%d)\n--- stdout ---\n%s\n--- stderr ---\n%s",
            $name,
            $globalUseOracle[$name]['exitCode'],
            $globalUseOracle[$name]['stdout'],
            $globalUseOracle[$name]['stderr'],
        );
    }

    expect($total)->toBeGreaterThan(0, sprintf(
        "検出側の見本から真値が 1 件も取れませんでした。php -l の警告文が変わると、\n"
        ."照合が「両方 0 件で一致」して静かに無力化します。\n"
        ."PHP_VERSION=%s PHP_BINARY=%s\n%s",
        PHP_VERSION,
        PHP_BINARY,
        implode(PHP_EOL, $diagnostics),
    ));
});

test('検出側の見本で、走査器の判定が php -l の真値と名前・行まで一致する', function () use ($globalUseOracle): void {
    foreach (GLOBAL_USE_FIXTURES as $name => $detects) {
        if (! $detects) {
            continue;
        }

        $scanned = globalUseScanFixture($name);

        expect($scanned['unresolved'])->toBe([], implode(PHP_EOL, $scanned['unresolved']));
        expect(globalUseSorted($scanned['violations']))
            ->toBe(globalUseSorted($globalUseOracle[$name]['warnings']), '見本 '.$name.' の判定が真値と一致しません');
    }
});

test('無違反の見本で、真値も走査器も 0 件である', function () use ($globalUseOracle): void {
    foreach (GLOBAL_USE_FIXTURES as $name => $detects) {
        if ($detects) {
            continue;
        }

        $scanned = globalUseScanFixture($name);

        expect($globalUseOracle[$name]['warnings'])->toBe([], '見本 '.$name.' に php -l が警告を出しました');
        expect($scanned['unresolved'])->toBe([], implode(PHP_EOL, $scanned['unresolved']));
        expect(globalUseSorted($scanned['violations']))->toBe([], '見本 '.$name.' を誤検出しました');
    }
});
