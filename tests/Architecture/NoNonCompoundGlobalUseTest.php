<?php

declare(strict_types=1);

use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: **namespace 宣言の無い PHP ファイル**の global スコープに
 * 非複合名の `use` を書かない。
 *
 * SoT = PHP の言語仕様。namespace 無しファイルでの非複合 use は
 *   Warning: The use statement with non-compound name 'X' has no effect
 * を出し、**import として何の効果も持たない** (参照は global にフォールバックする)。
 * `use function` / `use const` でも **まったく同じ warning** が出る (実測):
 *   use RuntimeException;   → Warning ...'RuntimeException'...
 *   use function strlen;    → Warning ...'strlen'...
 *   use const PHP_VERSION;  → Warning ...'PHP_VERSION'...
 *   use function Foo\bar;   → (複合名なので正常)
 *
 * なぜ「出力が汚れるだけ」で済ませないか (実測):
 *   - この warning が set_error_handler に届くかは **環境依存** (opcache 状態 /
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
 * git 不在は環境不備として silent skip せず fail させる
 * (tests/js/architecture/pages-path-case-invariant.test.ts と同じ作法)。
 *
 * allowlist は設けない: 非複合 global use に正当な用途は存在しない (常に無効な import)。
 */

/**
 * index 以降で最初の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function nonCompoundUseNextSignificant(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * 1 ファイル分の PHP ソースから global スコープの非複合 use を収集する (純関数)。
 *
 * 判定手順:
 *   1. T_NAMESPACE が出現するファイルは対象外 (PHP が warning を出さない = 実際に import される)
 *   2. brace depth を追跡し **depth 0 の T_USE** のみを見る (クラス本体の trait use を除外)
 *   3. `use` 直後の `function` / `const` 修飾は読み飛ばす (同じ warning が出るため対象)
 *   4. `(` が続くならクロージャの `use ($x)` なので対象外
 *   5. カンマ区切りの各要素について、`as` の前の import 名を **1 つの文字列に正規化**し、
 *      先頭の `\` を除いた残りに区切り `\` を含まなければ非複合 = 違反
 *
 * **名前の正規化が必須である理由 (実測)**: PHP は `use \RuntimeException;` のような
 * 先頭 `\` 付きの単一名も受理し、**まったく同じ warning を出す**:
 *   use \RuntimeException;    → Warning ...non-compound name 'RuntimeException'...
 *   use function \strlen;     → Warning ...non-compound name 'strlen'...
 *   use const \PHP_VERSION;   → Warning ...non-compound name 'PHP_VERSION'...
 * しかも tokenizer 上は **T_STRING ではなく T_NAME_FULLY_QUALIFIED** になる:
 *   `use \RuntimeException;`  → T_USE, T_NAME_FULLY_QUALIFIED('\RuntimeException'), ';'
 *   `use RuntimeException;`   → T_USE, T_STRING('RuntimeException'), ';'
 * よって「T_STRING かどうか」で判定すると **先頭 `\` 付きを丸ごと取りこぼす** (silent hole)。
 * token 種別ではなく **名前の中身 (セグメント数)** で判定する。
 *
 * @return array{violations: list<string>, scanned: bool}
 */
function nonCompoundUseCollectFromSource(string $source, string $relative): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    // 1. namespace 付きファイルは対象外
    foreach ($tokens as $token) {
        if ($token->is(T_NAMESPACE)) {
            return ['violations' => [], 'scanned' => false];
        }
    }

    $violations = [];
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token->text === '{') {
            $depth++;

            continue;
        }
        if ($token->text === '}') {
            $depth--;

            continue;
        }

        // 2. global スコープの use だけを見る
        if (! $token->is(T_USE) || $depth !== 0) {
            continue;
        }

        $cursor = nonCompoundUseNextSignificant($tokens, $i + 1);
        if ($cursor === null) {
            continue;
        }

        // 4. クロージャの `use ($x)` は import ではない
        if ($tokens[$cursor]->text === '(') {
            continue;
        }

        // 3. `use function` / `use const` の修飾を読み飛ばす (対象に含める)
        if ($tokens[$cursor]->is([T_FUNCTION, T_CONST])) {
            $next = nonCompoundUseNextSignificant($tokens, $cursor + 1);
            if ($next === null) {
                continue;
            }
            $cursor = $next;
        }

        // 5. カンマ区切りの各 import 要素を評価する。
        //    名前は「1 要素 = 1 文字列」に正規化してからセグメント数で判定する
        //    (T_STRING / T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED / T_NS_SEPARATOR 分割の
        //     いずれの tokenizer 表現でも同じ結論になる)。
        $name = '';
        $nameLine = 0;
        $collecting = true;

        /** 収集済みの名前を判定して violations へ積む。 */
        $flush = function () use (&$name, &$nameLine, &$violations, $relative): void {
            $normalized = ltrim($name, '\\');
            if ($normalized !== '' && ! str_contains($normalized, '\\')) {
                $violations[] = "{$relative}:{$nameLine} → use {$name};";
            }
            $name = '';
        };

        for ($j = $cursor; $j < $count; $j++) {
            $current = $tokens[$j];

            if ($current->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($current->text === ';') {
                $flush();
                break;
            }
            if ($current->text === ',') {
                $flush();
                $collecting = true;

                continue;
            }
            if ($current->is(T_AS)) {
                $flush();
                $collecting = false; // `as` 以降の別名は判定対象ではない

                continue;
            }
            // グループ use (`use A\B\{C, D};`) は prefix に必ず `\` を含むため非複合になりえない。
            if ($current->text === '{') {
                $name = '';
                break;
            }
            if (! $collecting) {
                continue;
            }
            if ($current->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                if ($name === '') {
                    $nameLine = $current->line;
                }
                $name .= $current->text;
            }
        }
    }

    return ['violations' => $violations, 'scanned' => true];
}

/**
 * git 追跡下全体の収集結果。
 *
 * @return array{violations: list<string>, namespacelessFiles: int, totalFiles: int}
 */
function nonCompoundUseCollectAll(): array
{
    $violations = [];
    $namespaceless = 0;
    $total = 0;

    foreach (TrackedPhpSourceFiles::all(base_path()) as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $total++;
        $collected = nonCompoundUseCollectFromSource($source, $target['relative']);
        if ($collected['scanned']) {
            $namespaceless++;
        }
        $violations = array_merge($violations, $collected['violations']);
    }

    return [
        'violations' => $violations,
        'namespacelessFiles' => $namespaceless,
        'totalFiles' => $total,
    ];
}

test('namespace 無しファイルに非複合 global use が存在しない', function (): void {
    $result = nonCompoundUseCollectAll();

    expect($result['violations'])->toBe([],
        '非複合 global use を検出しました。PHP は「has no effect」warning を出し import は無効です。'
        .'use 文を削除して参照側を \\FQCN (例: \\RuntimeException) にしてください。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

test('走査が空振りしていない (git 追跡 PHP > 0 かつ namespace 無しファイル > 0)', function (): void {
    $result = nonCompoundUseCollectAll();

    expect($result['totalFiles'])->toBeGreaterThan(0);
    // database/migrations (60 本) や tests/Architecture など namespace 無しファイルは
    // 構造的に必ず存在する。0 なら namespace 判定が壊れている。
    expect($result['namespacelessFiles'])->toBeGreaterThan(0);
});

/*
 * 負のコントロール: 3 形態すべて (class / function / const) が実際に同じ warning を出すため、
 * 3 形態すべてを検出できることを fixture で固定する。
 */
test('負のコントロール: class / function / const の非複合 use を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    declare(strict_types=1);
    use RuntimeException;
    use function strlen;
    use const PHP_VERSION;
    return new class {};
    PHP;

    $result = nonCompoundUseCollectFromSource($fixture, 'fixture.php');
    expect($result['scanned'])->toBeTrue();
    expect($result['violations'])->toHaveCount(3);
});

test('負のコントロール: カンマ区切り / as 別名の非複合 use も検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use RuntimeException, LogicException;
    use InvalidArgumentException as Bad;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toHaveCount(3);
});

/*
 * 負のコントロール: **先頭 `\` 付きの単一名**も PHP は同じ warning を出す (実測)。
 * tokenizer 上は T_STRING ではなく T_NAME_FULLY_QUALIFIED になるため、
 * token 種別で判定していると丸ごと取りこぼす (silent hole)。
 */
test('負のコントロール: 先頭バックスラッシュ付きの非複合 use も検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use \RuntimeException;
    use function \strlen;
    use const \PHP_VERSION;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toHaveCount(3);
});

test('正のコントロール: 複合名 / グループ use / 先頭 \\ 付き複合名は検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\{DB, Schema};
    use function Illuminate\Support\enum_value;
    use const Illuminate\Foundation\SOME_CONST;
    use App\Models\User as Account;
    use \Illuminate\Support\Str;
    use Illuminate\Support\Arr, Illuminate\Support\Collection;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toBe([]);
});

/*
 * 正のコントロール: namespace 付きファイルの非複合 use は PHP が warning を出さない
 * (実際に import として機能する) ため対象外。scanned=false で走査自体をスキップする。
 */
test('正のコントロール: namespace 付きファイルは対象外', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Services;
    use RuntimeException;
    class Foo {}
    PHP;

    $result = nonCompoundUseCollectFromSource($fixture, 'fixture.php');
    expect($result['scanned'])->toBeFalse();
    expect($result['violations'])->toBe([]);
});

/*
 * 正のコントロール: クラス本体の trait use と、クロージャの use ($x) を誤検知しない。
 * brace depth 追跡が効いていることの証明。
 */
test('正のコントロール: trait use / クロージャ use を誤検知しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    return new class extends Migration {
        use SomeTrait;
        public function up(): void {
            $x = 1;
            $fn = function () use ($x) { return $x; };
            $arrow = fn () => $x;
        }
    };
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toBe([]);
});
