<?php

declare(strict_types=1);

/*
 * Architecture invariant: 月 / 年 / 四半期の加減算に **暗黙 overflow メソッドを使わない**。
 *
 * SoT = Carbon 3 の既定挙動。`addMonth()` 等は「月末を超えた分だけ翌月へ溢れる」:
 *   2026-01-31 ->addMonth()           => 2026-03-03   (溢れる)
 *   2026-01-31 ->addMonthNoOverflow() => 2026-02-28
 *   2024-02-29 ->addYear()            => 2025-03-01
 *   2026-03-31 ->subMonth()           => 2026-03-03
 * 課金ドメイン (current_period_end / チケット有効期限 / signup grant マーカー) は月末起点の
 * 日付を構造的に扱いうるため、1 日ずれた期日は「請求周期が 1 日ずれる」形で顕在化し、
 * しかも **月末月にしか再現しない** = 発見が極めて遅れる。
 *
 * 契約: 既定は `*NoOverflow`。overflow が要件なら `*WithOverflow` を明示して意図を残す
 * (`->addMonthWithOverflow()`)。暗黙形 (`->addMonth()`) だけを fail させる。
 * AGENTS.md 実装規約と 1:1 で対応する。
 *
 * 検出方式: PhpToken::tokenize で `->` / `?->` の直後の T_STRING を見る。
 * コメント・文字列リテラル中の `->addMonth()` という **記述** を誤検出しないために
 * regex ではなく token 走査を使う (負のコントロールで固定)。
 *
 * 識別子比較は **小文字化して完全一致**:
 *   - PHP のメソッド解決は case-insensitive で、実測上 `->addmonth()` は受理され溢れる
 *     (`->AddMonth()` は Carbon が拒否)。case 一致で判定すると全小文字形がすり抜ける
 *   - 完全一致にすることで `addMonthNoOverflow` / `addMonthsWithOverflow` を
 *     前方一致で巻き込まない (小文字化しても deny 集合と一致しない = 安全側)
 *
 * 動的メソッド呼び出しの扱い:
 *   - `->{'addMonth'}()` (literal 文字列) は **静的に決定できるので検出する**
 *     (gate の literal 回避を塞ぐ。実測で現状 0 件なので追加コストなし)
 *   - `->{$method}()` / `->$prop()` (変数形) は静的に決定できないため **対象外**。
 *     これは本 gate の明示的な限界。全 dynamic dispatch を deny-by-default にする案は
 *     採らない — 走査対象に日付と無関係な dynamic dispatch が実在し
 *     (HTTP verb テスト `->{$method}($uri)`、factory state `->{$state}()` の 4 件)、
 *     Carbon gate がそれらを人質に取るのは責務外だから。
 *     「変数に 'addMonth' を入れて日付を進める」書き方は現実的な脅威ではない
 *     (実測 0 件、かつ通常のコードレビューで自明に不自然)
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 暗黙 overflow するメソッド名 (全小文字)。
 *
 * @var list<string>
 */
const CARBON_OVERFLOW_DENIED_METHODS = [
    'addmonth', 'addmonths', 'submonth', 'submonths',
    'addyear', 'addyears', 'subyear', 'subyears',
    'addquarter', 'addquarters', 'subquarter', 'subquarters',
];

// 動的メソッド名のうち **literal 文字列** で書かれたもの (`->{'addMonth'}()`) は
// 静的に決定できるため deny 対象に含める。変数による動的ディスパッチ (`->{$method}()`) は
// 静的に決定できず、本 gate の**明示的な限界**として扱う (理由は冒頭コメント参照)。
// 実装は carbonOverflowCollectFromSource() の `->{` 分岐が担う。

/**
 * 走査対象 (app/ + database/ + tests/) の PHP ファイル一覧。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function carbonOverflowScanTargets(): array
{
    $root = base_path();
    $files = [];
    foreach (['app', 'database', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
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
 * index 以降で最初の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function carbonOverflowNextSignificant(array $tokens, int $index): ?int
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
 * literal 文字列トークン (`'addMonth'` / `"addMonth"`) の中身を返す。literal でなければ null。
 */
function carbonOverflowLiteralValue(string $raw): ?string
{
    if (preg_match('/\A[bB]?([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\z/', $raw, $m) !== 1) {
        return null;
    }

    return $m[2];
}

/**
 * 1 ファイル分の PHP ソースから月/年/四半期系のメソッド呼び出しを収集する (純関数)。
 *
 * `methodCalls` は「`->name(` 形のメソッド呼び出しを何件見たか」。
 * 空振り検知に使う (実コードの Carbon 使用有無に依存しない指標)。
 *
 * @return array{violations: list<string>, methodCalls: int}
 */
function carbonOverflowCollectFromSource(string $source, string $relative): array
{
    $violations = [];
    $methodCalls = 0;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            continue;
        }
        $nameIndex = carbonOverflowNextSignificant($tokens, $i + 1);
        if ($nameIndex === null) {
            continue;
        }

        $name = null;

        if ($tokens[$nameIndex]->is(T_STRING)) {
            // 通常形 `->addMonth(`。メソッド呼び出し (プロパティアクセスではない) に限る
            $parenIndex = carbonOverflowNextSignificant($tokens, $nameIndex + 1);
            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
                continue;
            }
            $methodCalls++;
            $name = $tokens[$nameIndex]->text;
        } elseif ($tokens[$nameIndex]->text === '{') {
            // 動的形 `->{...}(`。**literal 文字列**なら静的に決定できるので対象に含める
            // (`->{'addMonth'}()` による gate 回避を塞ぐ)。変数形は決定できないので対象外。
            $inner = carbonOverflowNextSignificant($tokens, $nameIndex + 1);
            if ($inner === null || ! $tokens[$inner]->is(T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }
            $close = carbonOverflowNextSignificant($tokens, $inner + 1);
            if ($close === null || $tokens[$close]->text !== '}') {
                continue;
            }
            $parenIndex = carbonOverflowNextSignificant($tokens, $close + 1);
            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
                continue;
            }
            $methodCalls++;
            $name = carbonOverflowLiteralValue($tokens[$inner]->text);
        }

        if ($name === null) {
            continue;
        }

        if (in_array(strtolower($name), CARBON_OVERFLOW_DENIED_METHODS, true)) {
            $violations[] = "{$relative}:{$tokens[$nameIndex]->line} → ->{$name}()";
        }
    }

    return ['violations' => $violations, 'methodCalls' => $methodCalls];
}

/**
 * app/ + database/ + tests/ 全体の収集結果。
 *
 * @return array{violations: list<string>, methodCalls: int, files: int}
 */
function carbonOverflowCollectAll(): array
{
    $violations = [];
    $methodCalls = 0;
    $files = 0;

    foreach (carbonOverflowScanTargets() as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $files++;
        $collected = carbonOverflowCollectFromSource($source, $target['relative']);
        $violations = array_merge($violations, $collected['violations']);
        $methodCalls += $collected['methodCalls'];
    }

    return ['violations' => $violations, 'methodCalls' => $methodCalls, 'files' => $files];
}

test('月/年/四半期の加減算に暗黙 overflow メソッドを使っていない', function (): void {
    $result = carbonOverflowCollectAll();

    expect($result['violations'])->toBe([],
        '暗黙 overflow するメソッドを検出しました。既定は *NoOverflow を使い、'
        .'overflow が要件なら *WithOverflow を明示してください (AGENTS.md 実装規約)。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

test('走査が空振りしていない (対象ファイル > 0 かつメソッド呼び出しを実際に見ている)', function (): void {
    $result = carbonOverflowCollectAll();

    // ディレクトリ構成変更や走査基盤の破損で「0 件検査して green」になる退行を落とす。
    expect($result['files'])->toBeGreaterThan(0);
    // `->name(` 形のメソッド呼び出し件数。実コードの Carbon 使用有無に依存しない指標なので、
    // 将来 Carbon 呼び出しが 0 になっても誤落ちしない。0 なら token 走査が死んでいる。
    expect($result['methodCalls'])->toBeGreaterThan(0);
});

/*
 * 負のコントロール: 実ファイルを書き換えず fixture ソースに対して gate が点灯することを確認する。
 * app/ 本体の違反は 0 件 (= 予防 gate) のため、ここが空振りでないことの唯一の担保になる。
 */
test('負のコントロール: 暗黙 overflow メソッドを検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Carbon\CarbonImmutable;
    class Fixture {
        public function run(CarbonImmutable $at): void {
            $a = $at->addMonth();
            $b = $at->subMonths(2);
            $c = $at->addYear();
            $d = $at->subYears(1);
            $e = $at->addQuarter();
            $f = $at->subQuarters(3);
            $g = $at?->addMonths(1);
        }
    }
    PHP;

    $result = carbonOverflowCollectFromSource($fixture, 'fixture.php');
    expect($result['violations'])->toHaveCount(7);
    expect($result['methodCalls'])->toBe(7);
});

/*
 * 負のコントロール: literal 文字列の動的呼び出し `->{'addMonth'}()` による gate 回避を塞ぐ。
 * (変数形 `->{$method}()` は静的に決定できないため本 gate の明示的な限界。冒頭コメント参照)
 */
test('負のコントロール: literal 文字列の動的メソッド呼び出しも検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function run($at): void {
            $a = $at->{'addMonth'}();
            $b = $at->{"subYears"}(2);
            $c = $at->{'addMonthNoOverflow'}();
        }
    }
    PHP;

    $result = carbonOverflowCollectFromSource($fixture, 'fixture.php');
    expect($result['violations'])->toHaveCount(2); // NoOverflow は許可
    expect($result['methodCalls'])->toBe(3);
});

/*
 * 負のコントロール: PHP のメソッド解決は case-insensitive で、実測上 `->addmonth()` は
 * Carbon に受理され実際に溢れる。case 一致で判定していると全小文字形がすり抜ける。
 */
test('負のコントロール: 全小文字の呼び出しも検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function run($at): void {
            $a = $at->addmonth();
            $b = $at->submonths(2);
            $c = $at->addyear();
        }
    }
    PHP;

    expect(carbonOverflowCollectFromSource($fixture, 'fixture.php')['violations'])->toHaveCount(3);
});

test('正のコントロール: NoOverflow / WithOverflow 形は検出せず、許可形として数える', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function run($at): void {
            $a = $at->addMonthNoOverflow();
            $b = $at->subMonthsNoOverflow(2);
            $c = $at->addYearNoOverflow();
            $d = $at->addQuartersNoOverflow(1);
            $e = $at->addMonthWithOverflow();
            $f = $at->addMonthsWithOverflow(2);
        }
    }
    PHP;

    $result = carbonOverflowCollectFromSource($fixture, 'fixture.php');
    expect($result['violations'])->toBe([]);
    expect($result['methodCalls'])->toBe(6);
});

/*
 * 正のコントロール: コメント / 文字列リテラル中の記述は「コード」ではないので検出しない。
 * これが regex ではなく PhpToken を使う理由そのもの。
 */
test('正のコントロール: コメント・文字列中の記述を誤検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        /** 使用禁止: $at->addMonth() は溢れるので addMonthNoOverflow を使うこと */
        public function run($at): void {
            // $at->addYear() と書くと 2/29 起点で 3/1 になる
            $doc = '呼び出し例: $at->subQuarter()';
            $heredoc = <<<'INNER'
            $at->addMonths(3)
            INNER;
            $ok = $at->addMonthNoOverflow();
        }
    }
    PHP;

    $result = carbonOverflowCollectFromSource($fixture, 'fixture.php');
    expect($result['violations'])->toBe([]);
    expect($result['methodCalls'])->toBe(1);
});

/*
 * 正のコントロール: 同名でも「メソッド呼び出しでない」ものは対象外。
 * プロパティアクセス・静的呼び出し・関数定義を巻き込まないことを固定する。
 */
test('正のコントロール: プロパティアクセスや別文脈の同名識別子は検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public $addMonth = 1;
        public function addMonth(): void {}
        public function run($at): void {
            $x = $this->addMonth;          // プロパティ (括弧なし)
            $y = ['addMonth' => 1];        // 配列キー
            $z = Helper::addMonth($at);    // static (:: は対象外)
        }
    }
    PHP;

    expect(carbonOverflowCollectFromSource($fixture, 'fixture.php')['violations'])->toBe([]);
});
