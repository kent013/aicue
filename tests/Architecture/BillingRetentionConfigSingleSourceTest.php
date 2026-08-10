<?php

declare(strict_types=1);

use App\Support\Legal\BillingRetention;

/*
 * Architecture invariant: 課金取引記録の保持年数 (legal.billing_retention_years) の
 * **解決点は App\Support\Legal\BillingRetention の 1 箇所だけ**である。
 *
 * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C1 (C1a)
 * とオーナー決定 (課金取引記録の保持 = 7 年)。
 *
 * 背景: この数値は「環境ごとに変えてよい運用値」ではなく、**法務文書 (/privacy) が
 * 宣言する値そのもの**である。読む場所が分岐すると「規約が宣言した年数」と
 * 「実際に消える年数」が静かにズレる — 利用者から見て検証不能な形で規約違反が起きる。
 * よって (a) env を使わない (b) config を読むのは SSOT クラス 1 箇所だけ、を機械固定する。
 *
 * ★この gate が保証するもの:
 *   - 検査 1: `'legal.billing_retention_years'` を読むのは BillingRetention だけ (app/ 走査)
 *   - 検査 2: config/legal.php の値が **整数リテラル**である (env() 経由で環境依存にしない)
 *     かつ**オーナー決定の 7** である
 *   - 検査 3: 実行時の `BillingRetention::years()` が config リテラルと一致する
 *   - 検査 4: 空振り検知 (走査ファイル数 / token 数が 0 でない) と
 *     正の自己検証 (SSOT ファイルで検出器が実際に点灯する)
 *   - 検査 5: 負のコントロール (fixture ソースで点灯 / コメント中の表記は点灯しない)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **tests/ は走査しない**。保持年数の fail-fast (0 以下) を検証するテストは
 *     config を書き換える必要があり、そこを禁止すると検査そのものが書けなくなる
 *   - **規約文面 (privacy blade) との一致は見ない**。文面はまだ存在せず (PR-C3 の担当)、
 *     三者一致 (config / SSOT / 文面) の gate は PR-C3 で本 gate の上に積む
 *   - 動的キー組み立て (`config('legal.'.$key)`) には沈黙する (実測 0 件)
 *
 * 検出方式は LegalConsentVersionSingleSourceTest と同じ token 走査
 * (regex にすると本ファイルの説明コメント自身で偽赤になる)。DB 不使用。
 */

/** 設定キー: SSOT だけが読んでよい。 */
const BILLING_RETENTION_CONFIG_KEY = 'legal.billing_retention_years';

/** config/legal.php 内での素のキー名。 */
const BILLING_RETENTION_CONFIG_BARE_KEY = 'billing_retention_years';

/** 単一出典クラス (repo ルート相対)。 */
const BILLING_RETENTION_SOURCE_FILE = 'app/Support/Legal/BillingRetention.php';

/** オーナー決定の保持年数 (逸脱不可。変更は規約文面の変更と同義)。 */
const BILLING_RETENTION_OWNER_DECIDED_YEARS = 7;

/**
 * 1 ソースを走査して出現数を返す (純関数 = 負のコントロールから直接呼べる)。
 *
 * @return array{configKey: int, tokens: int}
 */
function billingRetentionScanSource(string $source): array
{
    $result = ['configKey' => 0, 'tokens' => 0];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }
        $result['tokens']++;
        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (trim($token[1], "'\"") === BILLING_RETENTION_CONFIG_KEY) {
            $result['configKey']++;
        }
    }

    return $result;
}

/**
 * repo ルート相対パス => 走査結果。
 *
 * @param  list<string>  $dirs
 * @return array<string, array{configKey: int, tokens: int}>
 */
function billingRetentionScanTree(array $dirs): array
{
    $root = base_path();
    $scanned = [];

    foreach ($dirs as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            $source = file_get_contents($absolute);
            if (! is_string($source)) {
                continue;
            }
            $scanned[substr($absolute, strlen($root) + 1)] = billingRetentionScanSource($source);
        }
    }

    ksort($scanned);

    return $scanned;
}

/**
 * config/legal.php の `billing_retention_years => <値>` の値トークンを返す。
 *
 * 値が単一の整数リテラルでなければ null (= env() やクラス定数を挟んだ形は不合格)。
 */
function billingRetentionConfigLiteral(): ?int
{
    $source = file_get_contents(base_path('config/legal.php'));
    if (! is_string($source)) {
        return null;
    }

    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $count = count($tokens);
    for ($i = 0; $i < $count - 3; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (trim($token[1], "'\"") !== BILLING_RETENTION_CONFIG_BARE_KEY) {
            continue;
        }
        $arrow = $tokens[$i + 1];
        $value = $tokens[$i + 2];
        $terminator = $tokens[$i + 3];
        if (! is_array($arrow) || $arrow[0] !== T_DOUBLE_ARROW) {
            return null;
        }
        if (! is_array($value) || $value[0] !== T_LNUMBER) {
            return null; // env(...) / 定数 / 式は不合格
        }
        if ($terminator !== ',' && $terminator !== ')' && $terminator !== ']') {
            return null;
        }

        return (int) $value[1];
    }

    return null;
}

test('検査 1: 保持年数の config キーを読むのは BillingRetention だけである', function (): void {
    $violations = [];
    foreach (billingRetentionScanTree(['app', 'config', 'database', 'routes']) as $relative => $scan) {
        if ($scan['configKey'] > 0 && $relative !== BILLING_RETENTION_SOURCE_FILE) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([],
        'config キー legal.billing_retention_years の直読を検出しました。保持年数は '
        .'App\Support\Legal\BillingRetention::years() 経由で取得してください '
        .'(規約が宣言する年数と実処理を 1 箇所で対応づけるため)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査 2: config/legal.php の保持年数が env を挟まない整数リテラル 7 である', function (): void {
    $literal = billingRetentionConfigLiteral();

    expect($literal)->not->toBeNull(
        'config/legal.php の billing_retention_years が整数リテラルではありません。'
        .'env() を挟むと環境ごとに保持年数が変わり、規約の宣言が環境依存の嘘になります。');
    expect($literal)->toBe(BILLING_RETENTION_OWNER_DECIDED_YEARS,
        '保持年数はオーナー決定 (7 年) です。変更は規約文面の変更と同義であり、'
        .'このテストと /privacy の文面を同じ PR で更新すること。');
});

test('検査 3: 実行時の BillingRetention::years() が config リテラルと一致する', function (): void {
    expect(BillingRetention::years())->toBe(billingRetentionConfigLiteral());
});

test('検査 4: 空振り検知と正の自己検証', function (): void {
    $scanned = billingRetentionScanTree(['app', 'config', 'database', 'routes']);

    expect(count($scanned))->toBeGreaterThan(0);
    expect(array_sum(array_column($scanned, 'tokens')))->toBeGreaterThan(0);

    // 検出器が死んでいたら検査 1 は vacuous green になる。SSOT では必ず 1 件点灯する。
    expect($scanned[BILLING_RETENTION_SOURCE_FILE]['configKey'])->toBe(1);
});

test('検査 5: 負のコントロール (リテラルは検出し、コメント中の表記は検出しない)', function (): void {
    $code = <<<'PHP'
    <?php
    class Fixture {
        public function run(): void {
            $a = config('legal.billing_retention_years');
            $b = config("legal.billing_retention_years");
        }
    }
    PHP;

    $comment = <<<'PHP'
    <?php
    /**
     * config('legal.billing_retention_years') を直読してはならない。
     */
    class Fixture {
        // config('legal.billing_retention_years')
        public function run(): void {}
    }
    PHP;

    expect(billingRetentionScanSource($code)['configKey'])->toBe(2);
    expect(billingRetentionScanSource($comment)['configKey'])->toBe(0);
    expect(billingRetentionScanSource($comment)['tokens'])->toBeGreaterThan(0);
});
