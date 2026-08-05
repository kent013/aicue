<?php

declare(strict_types=1);

/*
 * Architecture invariant: PCRE パターンリテラルが `\R` を含むなら `u` 修飾子が必須。
 *
 * PCRE の `\R` は **8bit 非 UTF-8 モードでバイト 0x85 (NEL) にもマッチする**。
 * UTF-8 の日本語には 0x85 を含む文字が多数あり (「全」E5 85 A8 /「先」E5 85 88 /
 * 「共」E5 85 B1 /「内」E5 86 85 /「入」E5 85 A5 /「公」E5 85 AC など)、
 * `/u` の無い `preg_split('/\R/')` は **文字の途中で行を分断する**。
 *
 * 実害 (監査サイクル 2 で実測): GlobalTestLockInventoryTest の解析入力で
 * scripts/global-test-lock.sh が 380 行 → 454 行に偽分割され、4.8 KB のコメント文字列が
 * 「コード」として検査対象に漏出していた。漏出テキストに検査語が 1 つ現れた時点で
 * ゲートが偽赤になる。本リポジトリは **コメントを日本語で書く規約** (AGENTS.md §実装規約)
 * なので、踏むのは時間の問題である。
 *
 * 本ゲートは deny-by-default で固定する。免除リストは持たない
 * (このリポジトリに `\R` を非 UTF-8 モードで使う正当な用途が 1 つも無いため)。
 *
 * **共通ヘルパ化ではなくゲートを選んだ理由**: 呼び出し箇所は 3 つしかなく、共通の
 * 行分割ヘルパを作ると新しい共有クラスが 1 本増える (AGENTS.md 思考原則 2)。
 * ゲートがあれば `/u` 忘れは書いた瞬間に検出できるので、ヘルパは不要。
 *
 * 解析は PhpToken (コメントは別トークンなので拾わない)。文字列 grep にすると
 * 「本ゲートの説明コメント」自身で偽赤になる。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * PCRE パターンリテラルとして認識するデリミタ (このリポジトリで実際に使われているもの)。
 *
 * @var list<string>
 */
const PCRE_DELIMITERS = ['/', '#', '~', '%', '!', '@'];

/**
 * 走査対象ディレクトリ (リポジトリルートからの相対)。
 *
 * @var list<string>
 */
const PCRE_SCAN_DIRS = ['app', 'tests', 'config', 'database', 'routes', 'scripts'];

/**
 * 走査対象の PHP ファイル一覧。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function pcreScanTargets(): array
{
    $root = base_path();
    $files = [];
    foreach (PCRE_SCAN_DIRS as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            $files[] = [
                'absolute' => $absolute,
                'relative' => ltrim(str_replace($root, '', $absolute), '/'),
            ];
        }
    }

    return $files;
}

/**
 * PHP の文字列リテラルトークンを **評価後の値** へ復元する (純関数)。
 *
 * **復元規則**: 引用符を剥がしたあと `\\` → `\` の 1 パスだけを畳む
 * (加えて `\'` / `\"` のような「引用符自身のエスケープ」も畳む)。
 * `\R` は PHP のエスケープ列ではないため single / double のどちらでもそのまま残る
 * = この 1 パスで必要十分。
 *
 * **意図的な射程外**: double-quoted の `"\x5cR"` / `"\u{5c}R"` は PHP 評価後に `\R` に
 * なるが、本関数は復元しない (16 進 / Unicode エスケープまで復元すると PHP の
 * 文字列評価器を再実装することになり、費用対効果が合わない)。
 * このリポジトリに該当記述は 1 件も無い。将来必要になったら射程を広げる。
 *
 * @return string|null literal でなければ null
 */
function pcreUnquoteLiteral(string $raw): ?string
{
    // b'...' / B"..." のようなバイナリ接頭辞を落とす。
    $raw = preg_replace('/\A[bB]/', '', $raw) ?? $raw;
    if (strlen($raw) < 2) {
        return null;
    }
    $quote = $raw[0];
    if (($quote !== "'" && $quote !== '"') || $raw[strlen($raw) - 1] !== $quote) {
        return null;
    }

    $inner = substr($raw, 1, -1);
    $out = '';
    $len = strlen($inner);
    for ($i = 0; $i < $len; $i++) {
        if ($inner[$i] === '\\' && $i + 1 < $len) {
            $next = $inner[$i + 1];
            if ($next === '\\' || $next === $quote) {
                $out .= $next;
                $i++;

                continue;
            }
            // PHP のエスケープ列でないものは `\` ごとそのまま残す (`\R` はここを通る)。
            $out .= '\\'.$next;
            $i++;

            continue;
        }
        $out .= $inner[$i];
    }

    return $out;
}

/**
 * PCRE パターン body が **改行クラス `\R`** を含むかを判定する (純関数)。
 *
 * 単純な `str_contains($body, '\R')` は誤判定する: `\\R` (エスケープされた
 * バックスラッシュ + 文字 `R`) にも部分文字列として `\R` が現れるため。
 * 先頭からエスケープを畳みながら走査して「奇数個目の `\` の直後の `R`」だけを拾う。
 */
function pcreBodyHasNewlineClass(string $body): bool
{
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        if ($body[$i] !== '\\') {
            continue;
        }
        if ($i + 1 >= $len) {
            return false;
        }
        if ($body[$i + 1] === 'R') {
            return true;
        }
        $i++; // エスケープされた 1 文字を読み飛ばす (`\\` → 次の文字は素の文字)
    }

    return false;
}

/**
 * PHP ソースから **PCRE パターンリテラル** を抽出する (純関数)。
 *
 * **射程の明示**: 本抽出器は完全な PCRE parser ではない。escaped delimiter
 * (`'/a\/b/'`) や文字クラス内の delimiter (`'/[/]/'`) を厳密に扱わない。
 * 射程は「`\R` を含むパターンリテラルの `u` 修飾子欠落を検出すること」に限定する。
 * 動的生成 (`sprintf('/%s/', $x)`) と補間文字列 (`"/$x/"`) は
 * `T_CONSTANT_ENCAPSED_STRING` にならないため対象外 (意図的な射程外)。
 *
 * @return list<array{literal: string, body: string, modifiers: string}>
 */
function pcrePatternLiterals(string $source): array
{
    $patterns = [];

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    foreach ($tokens as $token) {
        if (! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            continue;
        }
        $value = pcreUnquoteLiteral($token->text);
        if ($value === null || $value === '') {
            continue;
        }

        $delimiter = $value[0];
        if (! in_array($delimiter, PCRE_DELIMITERS, true)) {
            // デリミタ始まりでない = 通常の文字列 (「`\R` は改行クラス」等の説明文)。
            continue;
        }

        $end = strrpos($value, $delimiter);
        if ($end === false || $end === 0) {
            continue;
        }

        $modifiers = substr($value, $end + 1);
        if (preg_match('/\A[a-zA-Z]*\z/', $modifiers) !== 1) {
            continue;
        }

        $patterns[] = [
            'literal' => $token->text,
            'body' => substr($value, 1, $end - 1),
            'modifiers' => $modifiers,
        ];
    }

    return $patterns;
}

/**
 * `\R` を含むのに `u` 修飾子が無いパターンリテラルを返す (純関数)。
 *
 * @return list<string> 違反リテラル (原文のまま)
 */
function pcreLiteralsMissingUnicodeModifier(string $source): array
{
    $violations = [];
    foreach (pcrePatternLiterals($source) as $pattern) {
        if (! pcreBodyHasNewlineClass($pattern['body'])) {
            continue;
        }
        if (str_contains($pattern['modifiers'], 'u')) {
            continue;
        }
        $violations[] = $pattern['literal'];
    }

    return $violations;
}

/**
 * 走査対象全体の収集結果。
 *
 * @return array{violations: list<string>, patterns: int, files: int, architecturePatterns: int, relatives: list<string>}
 */
function pcreCollectAll(): array
{
    $violations = [];
    $patterns = 0;
    $architecturePatterns = 0;
    $files = 0;
    $relatives = [];

    foreach (pcreScanTargets() as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $files++;
        $relatives[] = $target['relative'];

        $found = pcrePatternLiterals($source);
        $patterns += count($found);
        if (str_starts_with($target['relative'], 'tests/Architecture/')) {
            $architecturePatterns += count($found);
        }

        foreach (pcreLiteralsMissingUnicodeModifier($source) as $literal) {
            $violations[] = "{$target['relative']} → {$literal}";
        }
    }

    return [
        'violations' => $violations,
        'patterns' => $patterns,
        'files' => $files,
        'architecturePatterns' => $architecturePatterns,
        'relatives' => $relatives,
    ];
}

// P1
test('`\R` を含む PCRE パターンリテラルに `u` 修飾子が付いている', function (): void {
    $result = pcreCollectAll();

    expect($result['violations'])->toBe([],
        '`/u` の無い `\R` を検出しました。非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも '
        .'マッチし、UTF-8 の日本語を文字途中で分断します。`u` 修飾子を付けてください。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

// P2 / P3: 空振り防止 (drift ガード)。走査基盤が壊れて「0 件検査して green」になる退行を落とす。
// 下限を「`\R` を含むリテラルが N 件以上」にしないのは、将来 3 箇所すべてがリファクタで
// 消えたときに **正しい状態が偽赤になる** ため。下限は「抽出器が動いていること」に掛ける。
// 閾値は現在値 (PCRE リテラル多数 / 対象ファイル 300 超) から大きく下げた固定値にし、
// 「代表ファイルが走査対象に含まれる」検査を併用する。規模に連動する高い閾値は
// リポジトリの縮小・分割で偽赤になり、本ゲートが減らそうとしているものと同種の罠になる。
test('走査が空振りしていない (PCRE リテラル抽出とファイル走査が実際に動いている)', function (): void {
    $result = pcreCollectAll();

    // P2: 抽出器が実際にパターンを拾えている
    expect($result['patterns'])->toBeGreaterThanOrEqual(20);
    expect($result['architecturePatterns'])->toBeGreaterThanOrEqual(1);

    // P3: ファイル走査が実際に効いている + 代表ファイルが対象に入っている
    expect($result['files'])->toBeGreaterThanOrEqual(50);
    expect($result['relatives'])->toContain('tests/Architecture/GlobalTestLockInventoryTest.php');
});

/*
 * 正のコントロール (P4/P5/P6/P12/P14): 実ファイルを書き換えず fixture ソースに対して
 * ゲートが点灯することを確認する。本体の違反は 0 件 (= 予防ゲート) のため、
 * ここが空振りでないことの唯一の担保になる。
 *
 * fixture は nowdoc に置く: nowdoc 本体は T_ENCAPSED_AND_WHITESPACE であって
 * T_CONSTANT_ENCAPSED_STRING ではないため、**本ファイル自身が P1 で違反にならない**。
 */
test('正のコントロール: `u` 修飾子の無い `\R` を検出する', function (): void {
    // P4: single-quoted `/\R/`
    $p4 = <<<'PHP'
    <?php
    $lines = preg_split('/\R/', $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p4))->toHaveCount(1);

    // P5: `u` 以外の修飾子だけがある
    $p5 = <<<'PHP'
    <?php
    preg_match("/\R/m", $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p5))->toHaveCount(1);

    // P6: 別デリミタ
    $p6 = <<<'PHP'
    <?php
    preg_match('#\R#', $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p6))->toHaveCount(1);

    // P12: double-quoted (評価後 `/\R/`)
    $p12 = <<<'PHP'
    <?php
    preg_split("/\R/", $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p12))->toHaveCount(1);

    // P14: PHP ソース上の `'/\\R/'` は評価後 `/\R/` = 改行クラス
    $p14 = <<<'PHP'
    <?php
    preg_split('/\\R/', $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p14))->toHaveCount(1);
});

test('負のコントロール: `u` 付き / `\R` 不使用 / コメント / 通常文字列を誤検出しない', function (): void {
    // P7: `u` 付き
    $p7 = <<<'PHP'
    <?php
    preg_split('/\R/u', $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p7))->toBe([]);
    expect(pcrePatternLiterals($p7))->toHaveCount(1);

    // P8: 別デリミタ + `u`
    $p8 = <<<'PHP'
    <?php
    preg_match('#\R#u', $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p8))->toBe([]);

    // P9: `\R` 不使用 (明示列挙は非 UTF-8 でも安全)
    $p9 = <<<'PHP'
    <?php
    preg_split('/\r\n|\r|\n/', $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p9))->toBe([]);

    // P10: コメント内の記述 (これが文字列 grep ではなく PhpToken を使う理由そのもの)
    $p10 = <<<'PHP'
    <?php
    // preg_split('/\R/') は NEL にも当たるので使わない
    /** `/\R/` の説明 */
    $x = 1;
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p10))->toBe([]);
    expect(pcrePatternLiterals($p10))->toBe([]);

    // P11: デリミタ始まりでない通常文字列
    $p11 = <<<'PHP'
    <?php
    $msg = '\R は改行クラスです';
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p11))->toBe([]);
    expect(pcrePatternLiterals($p11))->toBe([]);

    // P13: PHP ソース上の `'/\\\\R/'` は評価後 `/\\R/` = リテラルの `\` + `R` (改行クラスではない)
    $p13 = <<<'PHP'
    <?php
    preg_split('/\\\\R/', $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p13))->toBe([]);
    expect(pcrePatternLiterals($p13))->toHaveCount(1);
});

/*
 * P15: **意図的な射程外** の固定。
 * `"/\x5cR/"` は PHP 評価後 `/\R/` になるが、本抽出器は 16 進エスケープを復元しない
 * (PHP の文字列評価器の再実装は費用対効果が合わない)。このリポジトリに該当記述は
 * 1 件も無く、将来必要になったら射程を広げる。ここが 0 件であることを固定しておくと、
 * 射程を広げたときにこのテストが落ちて「射程が変わった」と気づける。
 */
test('射程外の固定: 16 進エスケープで書かれた `\R` は (意図的に) 検出しない', function (): void {
    $p15 = <<<'PHP'
    <?php
    preg_split("/\x5cR/", $x);
    PHP;
    expect(pcreLiteralsMissingUnicodeModifier($p15))->toBe([]);
});
