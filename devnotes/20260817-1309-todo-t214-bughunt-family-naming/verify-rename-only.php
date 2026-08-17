<?php

declare(strict_types=1);

/*
 * T214 の受け入れ条件 A-6 (意図しない実行コード差分が無いこと) を再実行できる形にする一時スクリプト。
 *
 * 恒久化しない (1 回きりの改名の検証であり scripts/README.md の台帳に載せる性質ではない)。
 *
 * 使い方 (リポジトリルートで実行):
 *   out="$(mktemp)"
 *   php devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php > "$out"
 *   cp "$out" devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md
 *   rm -f "$out"
 *
 * ★出力は決定的である (時刻・実行環境・コミットハッシュを含めない)。
 * ★結果ファイルを自分では書かない (直接リダイレクトすると段 0 の clean 検査が自分の副作用で落ちる)。
 *
 * ★保証範囲を誇張しない: 示せるのは「意図しない実行コード差分が無い」ことまでである。
 *   振る舞いの同値性そのものは証明しない (autoload・キャッシュ・リポジトリ外の実行手順・
 *   動的に組み立てるクラス名は対象外)。振る舞い側は既存テストと composer test 全体が受け持つ。
 */

const RENAMES = [
    'BughuntStripeSyncSeeder' => 'BughuntBillingSeeder',
    'BughuntFakesServiceProvider' => 'FakeExternalsServiceProvider',
];

/**
 * A-6b: 名前の置換に加えて**意味も更新した**ファイルと、許可する追加。
 *
 * 逆置換した新内容のトークン列が、旧内容のトークン列へ下記の挿入を施したものと
 * **完全一致**することを要求する (それ以外のコード差分が 1 トークンでもあれば不合格)。
 * 削除側は挿入だけを許す構成上ゼロである。
 *
 * トークン列はコメント・docblock・空白を落として 1 個の空白で連結したものである
 * (= コメントの書き換えは自由、実行トークンは固定)。
 *
 * @var array<string, list<array{after: string, insert: string}>>
 */
const TOKEN_LEVEL_FILES = [
    // docblock (用途 2 の明記) だけを更新した = 実行トークンの差分はゼロ
    'tests/Support/ExternalFakes/FakeClassCatalog.php' => [],

    // 3-10 の候補集合へ配置例外のキーを足す
    'tests/Architecture/ExternalFakeWiringInvariantTest.php' => [
        [
            'after' => 'FakeClassCatalog :: namedClasses ( ) ,',
            'insert' => 'array_keys ( FakeClassCatalog :: placementExceptions ( ) ) ,',
        ],
    ],

    // 4-3 へ候補集合の明示 assertion を足す
    'tests/Architecture/FakeClassReferenceInvariantTest.php' => [
        [
            'after' => 'expect ( $candidates ) -> not -> toBeEmpty ( ) -> and ( $files ) -> not -> toBeEmpty ( ) ;',
            'insert' => 'expect ( $candidates ) -> toContain ( FakeExternalsServiceProvider :: class ) '
                .'-> and ( $candidates ) -> toContain ( FakeStorageGate :: class ) ;',
        ],
    ],
];

/**
 * A-6a のうち **import の並べ替えを伴う**ファイル。
 *
 * `vendor/bin/pint` の `ordered_imports` が強制するため、名前を変えると `use` 行の順序が動く。
 * 逆置換した新内容と旧内容の**`use` 行を同じ規則で並べ替えたうえで**バイト比較する
 * (= 並べ替え以外の差分は 1 バイトも許さない)。並べ替えが実際に起きていない
 * (= 素のバイト比較で一致する) ファイルをここに置くと不合格にする (分類の誤用を通さない)。
 *
 * @var list<string>
 */
const IMPORT_REORDERED_FILES = [
    'bootstrap/providers.php',
    'tests/Support/Bughunt/BughuntSeedWiringInventory.php',
];

/**
 * A-6c: 新規の恒久資産 (旧内容が無いので逆置換の比較対象にしない)。
 *
 * @var list<string>
 */
const NEW_PERMANENT_FILES = [
    'tests/Architecture/BughuntNamingResidualTest.php',
];

/**
 * A-6e: 設計・レビュー記録・検証の道具 (**パスの明示一覧**)。
 *
 * ここに載せてよいのは本 devnotes ディレクトリ配下と TODO 台帳だけである
 * (`app/` `tests/` `database/` `scripts/` `config/` `bootstrap/` `docs/` の他ファイルを
 * 逃がすことは禁止する)。
 *
 * @var list<string>
 */
const META_FILES = [
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-decisions-round-1.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-decisions-round-2.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-prompt-round-1.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-prompt-round-2.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-prompt-round-3.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/impl-review-round-1.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/impl-review-round-2.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/impl-review-round-3.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md',
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php',
];

/** A-6e に載せてよいパスの接頭辞 (これ以外が META_FILES にあれば不合格) */
const META_ALLOWED_PREFIXES = [
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/',
];

/**
 * A-6e に載せてよい TODO 台帳 (**完全一致**で扱う)。
 *
 * 接頭辞判定にすると `docs/TODO.md.backup` のような別ファイルまで通ってしまうため、
 * 2 冊だけを名指しする。
 *
 * @var list<string>
 */
const META_ALLOWED_EXACT = [
    'docs/TODO.md',
    'docs/TODO-closed.md',
];

/**
 * コマンドを実行して標準出力を返す (失敗したら例外)。
 *
 * @param  list<string>  $command
 */
function run(array $command): string
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('コマンドを起動できない: '.implode(' ', $command));
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0) {
        throw new RuntimeException(
            'コマンドが失敗した ('.implode(' ', $command)."): {$stderr}"
        );
    }

    return $stdout;
}

/** 新内容へ逆置換 (家系名 → 旧名) を掛ける */
function reverseSubstitute(string $content): string
{
    return str_replace(array_keys(RENAMES), array_values(RENAMES), $content);
}

/** PHP ソースのトークン列 (コメント・docblock・空白を落として 1 個の空白で連結) */
function normalizedTokens(string $source): string
{
    $parts = [];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            $parts[] = $token;

            continue;
        }

        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            continue;
        }

        $parts[] = $token[1];
    }

    return implode(' ', $parts);
}

/** `use ...;` 行を同じ規則で並べ替える (順序以外の差分だけを残す) */
function sortUseLines(string $content): string
{
    $lines = explode("\n", $content);
    $indices = [];
    $useLines = [];

    foreach ($lines as $index => $line) {
        if (preg_match('/^use\s[^;]*;$/', $line) === 1) {
            $indices[] = $index;
            $useLines[] = $line;
        }
    }

    sort($useLines, SORT_STRING);

    foreach ($indices as $position => $index) {
        $lines[$index] = $useLines[$position];
    }

    return implode("\n", $lines);
}

/** main 上の内容を取る (存在しなければ例外) */
function contentOnMain(string $path): string
{
    return run(['git', 'show', 'main:'.$path]);
}

$failures = [];
$rows = [];

// 段 0: 作業ツリーが clean であること (母集団は main...HEAD から取るので未コミットは見えない)
$status = run(['git', 'status', '--porcelain']);
if (trim($status) !== '') {
    $failures[] = '段 0: 作業ツリーが clean ではない (未コミットの変更は母集団に現れないため検証にならない)';
}

// A-6e の置き場所の制約 (メタ成果物という名目で本体の差分を逃がせないようにする)
foreach (META_FILES as $path) {
    $allowed = in_array($path, META_ALLOWED_EXACT, true);
    foreach (META_ALLOWED_PREFIXES as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $allowed = true;
        }
    }
    if (! $allowed) {
        $failures[] = "A-6e: 許可されていない置き場所が明示一覧に載っている: {$path}";
    }
}

// 明示一覧どうしの重複
$explicit = [
    'A-6b' => array_keys(TOKEN_LEVEL_FILES),
    'A-6a-imports' => IMPORT_REORDERED_FILES,
    'A-6c' => NEW_PERMANENT_FILES,
    'A-6e' => META_FILES,
];
$seen = [];
foreach ($explicit as $category => $paths) {
    foreach ($paths as $path) {
        if (isset($seen[$path])) {
            $failures[] = "分類の重複: {$path} ({$seen[$path]} と {$category})";
        }
        $seen[$path] = $category;
    }
}

// 段 1: 母集団
$diff = run(['git', 'diff', '--name-status', '-M', 'main...HEAD']);
$entries = [];
foreach (explode("\n", trim($diff)) as $line) {
    if ($line === '') {
        continue;
    }

    $fields = explode("\t", $line);
    $symbol = substr($fields[0], 0, 1);

    if ($symbol === 'R') {
        $entries[$fields[2]] = ['symbol' => 'R', 'old' => $fields[1]];

        continue;
    }

    $entries[$fields[1]] = ['symbol' => $symbol, 'old' => $fields[1]];
}
ksort($entries);

// 一覧にあるのに差分へ現れないパス (設計と実装のずれ)
foreach ($explicit as $category => $paths) {
    foreach ($paths as $path) {
        if (! isset($entries[$path])) {
            $failures[] = "{$category} の一覧にあるのに差分へ現れない: {$path}";
        }
    }
}

// 段 2〜4: 分類と判定
foreach ($entries as $path => $entry) {
    $category = $seen[$path] ?? null;

    if ($entry['symbol'] === 'D') {
        $failures[] = "削除は本施策に無い: {$path}";
        $rows[] = [$path, 'D', '不合格 (削除)'];

        continue;
    }

    if ($entry['symbol'] === 'A' && $category === null) {
        $failures[] = "新規なのに A-6c / A-6e への明示登録が無い: {$path}";
        $rows[] = [$path, 'A', '不合格 (未分類)'];

        continue;
    }

    if ($category === 'A-6c' || $category === 'A-6e') {
        $rows[] = [$path, $entry['symbol'], "{$category} (比較対象外)"];

        continue;
    }

    // ここから先は旧内容との比較が要る (R / M)
    if ($entry['symbol'] === 'A') {
        $failures[] = "新規を比較対象の分類へ入れている: {$path}";
        $rows[] = [$path, 'A', '不合格 (分類の誤り)'];

        continue;
    }

    $old = contentOnMain($entry['old']);
    $new = (string) file_get_contents($path);
    $reversed = reverseSubstitute($new);

    if ($category === 'A-6b') {
        $expected = normalizedTokens($old);

        foreach (TOKEN_LEVEL_FILES[$path] as $insertion) {
            $occurrences = substr_count($expected, $insertion['after']);
            if ($occurrences !== 1) {
                $failures[] = "A-6b: 挿入位置の目印が {$occurrences} 箇所 (1 箇所であること): {$path}";

                continue 2;
            }
            $expected = str_replace(
                $insertion['after'],
                $insertion['after'].' '.$insertion['insert'],
                $expected
            );
        }

        if (normalizedTokens($reversed) !== $expected) {
            $failures[] = "A-6b: 許可した追加以外の実行トークン差分がある: {$path}";
            $rows[] = [$path, $entry['symbol'], '不合格 (A-6b)'];

            continue;
        }

        $rows[] = [$path, $entry['symbol'], 'A-6b 合格 (許可した追加のみ)'];

        continue;
    }

    if ($category === 'A-6a-imports') {
        if ($reversed === $old) {
            $failures[] = "A-6a-imports: 並べ替えが起きていない (A-6a へ移すこと): {$path}";
            $rows[] = [$path, $entry['symbol'], '不合格 (分類の誤用)'];

            continue;
        }

        if (sortUseLines($reversed) !== sortUseLines($old)) {
            $failures[] = "A-6a-imports: import の並べ替え以外の差分がある: {$path}";
            $rows[] = [$path, $entry['symbol'], '不合格 (A-6a-imports)'];

            continue;
        }

        $rows[] = [$path, $entry['symbol'], 'A-6a-imports 合格 (import 順のみ)'];

        continue;
    }

    // 分類なし = A-6a (名前の置換だけ)
    if ($reversed !== $old) {
        $failures[] = "A-6a: 逆置換しても旧内容とバイト一致しない: {$path}";
        $rows[] = [$path, $entry['symbol'], '不合格 (A-6a)'];

        continue;
    }

    $rows[] = [$path, $entry['symbol'], 'A-6a 合格 (名前の置換のみ)'];
}

usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
sort($failures, SORT_STRING);

/** 標準出力へ書く (AGENTS.md が `echo` を禁じているため `fwrite` を使う) */
function out(string $text): void
{
    fwrite(STDOUT, $text);
}

out("# T214 改名の差分検証 (A-6)\n\n");
out('`php devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php`'
    ." の出力そのままである。\n\n");
out("| ファイル | 状態 | 判定 |\n|---|---|---|\n");
foreach ($rows as [$path, $symbol, $verdict]) {
    out("| `{$path}` | {$symbol} | {$verdict} |\n");
}

out("\n");
out('- 対象ファイル数: '.count($rows)."\n");
out('- 不合格: '.count($failures)."\n");

if ($failures !== []) {
    out("\n## 不合格の内訳\n\n");
    foreach ($failures as $failure) {
        out("- {$failure}\n");
    }

    exit(1);
}

out("\n判定: 合格 (意図しない実行コード差分は無い)。\n");
out("\n");
out("> 保証範囲: 示せるのはここまでである。振る舞いの同値性そのものは証明しない\n");
out("> (autoload・キャッシュ・リポジトリ外の実行手順・動的に組み立てるクラス名は対象外)。\n");

exit(0);
