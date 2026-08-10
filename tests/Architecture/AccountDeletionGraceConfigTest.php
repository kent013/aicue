<?php

declare(strict_types=1);

use App\Support\Account\AccountDeletionGrace;
use Carbon\CarbonImmutable;

/*
 * Architecture invariant: 退会 (アカウント削除) の猶予日数 (account.deletion_grace_days) の
 * **解決点は App\Support\Account\AccountDeletionGrace の 1 箇所だけ**である。
 *
 * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-B (B0)
 * とオーナー決定 (猶予期間 = 30 日)。
 *
 * 背景: 猶予日数は「環境ごとに変えてよい運用値」ではなく、利用者に対して
 * 「いつまで取り消せるか」を約束する値である。読む場所が分岐すると
 * 「画面が案内した期日」と「バッチが実際に消す期日」が静かにズレる。
 * よって (a) env を使わない (b) config を読むのは SSOT クラス 1 箇所だけ、を機械固定する。
 * (config/legal.php の billing_retention_years / config/idempotency.php の
 * retention_hours と同じ理由・同じ形。)
 *
 * ★この gate が保証するもの:
 *   - 検査 1: `'account.deletion_grace_days'` を読むのは AccountDeletionGrace だけ
 *     (app/ config/ database/ routes/ を走査)
 *   - 検査 2: config/account.php の値が **整数リテラル** (env() を挟まない) かつ
 *     オーナー決定の **30** である
 *   - 検査 3: 実行時の `AccountDeletionGrace::days()` が config リテラルと一致する
 *   - 検査 4: 0 以下なら **fail-fast** する (fail-open にしない)
 *   - 検査 5: 空振り検知 (走査ファイル数 / token 数が 0 でない) と
 *     正の自己検証 (SSOT ファイルで検出器が実際に点灯する)
 *   - 検査 6: 負のコントロール (fixture ソースで点灯 / コメント中の表記は点灯しない)
 *   - 検査 7-9 (**behavioral**): `purgeAfter()` が**暦日 30 日**であること
 *     (月末丸め無し / うるう年跨ぎ / アプリ TZ 下のローカル時刻)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **tests/ は走査しない**。fail-fast (0 以下) の検証は config を書き換える必要があり、
 *     そこを禁止すると検査そのものが書けなくなる
 *   - 動的キー組み立て (`config('account.'.$key)`) には沈黙する (実測 0 件)
 *   - 既に予約済みのユーザーへの遡及有無は本 gate の担当ではない
 *     (users.deletion_purge_after が**絶対時刻**であることが構造的な答えで、
 *      その挙動は tests/Feature/Auth/AccountDeletionGraceTest が固定する)
 *
 * 検出方式は BillingRetentionConfigSingleSourceTest / LegalConsentVersionSingleSourceTest と
 * 同じ token 走査 (regex にすると本ファイルの説明コメント自身で偽赤になる)。DB 不使用。
 */

/** 設定キー: SSOT だけが読んでよい。 */
const ACCOUNT_GRACE_CONFIG_KEY = 'account.deletion_grace_days';

/** config/account.php 内での素のキー名。 */
const ACCOUNT_GRACE_CONFIG_BARE_KEY = 'deletion_grace_days';

/** 単一出典クラス (repo ルート相対)。 */
const ACCOUNT_GRACE_SOURCE_FILE = 'app/Support/Account/AccountDeletionGrace.php';

/** オーナー決定の猶予日数 (逸脱不可)。 */
const ACCOUNT_GRACE_OWNER_DECIDED_DAYS = 30;

/**
 * 1 ソースを走査して出現数を返す (純関数 = 負のコントロールから直接呼べる)。
 *
 * @return array{configKey: int, tokens: int}
 */
function accountGraceScanSource(string $source): array
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
        if (trim($token[1], "'\"") === ACCOUNT_GRACE_CONFIG_KEY) {
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
function accountGraceScanTree(array $dirs): array
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
            $scanned[substr($absolute, strlen($root) + 1)] = accountGraceScanSource($source);
        }
    }

    ksort($scanned);

    return $scanned;
}

/**
 * config/account.php の `deletion_grace_days => <値>` の値トークンを返す。
 *
 * 値が単一の整数リテラルでなければ null (= env() やクラス定数を挟んだ形は不合格)。
 */
function accountGraceConfigLiteral(): ?int
{
    $source = file_get_contents(base_path('config/account.php'));
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
        if (trim($token[1], "'\"") !== ACCOUNT_GRACE_CONFIG_BARE_KEY) {
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

test('検査 1: 猶予日数の config キーを読むのは AccountDeletionGrace だけである', function (): void {
    $violations = [];
    foreach (accountGraceScanTree(['app', 'config', 'database', 'routes']) as $relative => $scan) {
        if ($scan['configKey'] > 0 && $relative !== ACCOUNT_GRACE_SOURCE_FILE) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([],
        'config キー account.deletion_grace_days の直読を検出しました。猶予日数は '
        .'App\Support\Account\AccountDeletionGrace::days() 経由で取得してください '
        .'(画面が案内する期日とバッチが消す期日を 1 箇所で対応づけるため)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査 2: config/account.php の猶予日数が env を挟まない整数リテラル 30 である', function (): void {
    $literal = accountGraceConfigLiteral();

    expect($literal)->not->toBeNull(
        'config/account.php の deletion_grace_days が整数リテラルではありません。'
        .'env() を挟むと環境ごとに猶予が変わり、利用者への約束が環境依存になります。');
    expect($literal)->toBe(ACCOUNT_GRACE_OWNER_DECIDED_DAYS,
        '猶予日数はオーナー決定 (30 日) です。変更は利用者への約束の変更と同義であり、'
        .'UI 文言・runbook と同じ PR で更新すること。');
});

test('検査 3: 実行時の AccountDeletionGrace::days() が config リテラルと一致する', function (): void {
    expect(AccountDeletionGrace::days())->toBe(accountGraceConfigLiteral());
});

test('検査 4: 猶予日数が 0 以下なら fail-fast する (fail-open にしない)', function (): void {
    // 0 以下をそのまま通すと purgeAfter が予約時刻以前になり、**予約した瞬間に期限到来**
    // = 猶予ゼロで物理削除される。設定漏れは静かに通してはならない。
    config()->set('account.deletion_grace_days', 0);
    expect(fn (): int => AccountDeletionGrace::days())->toThrow(InvalidArgumentException::class);

    config()->set('account.deletion_grace_days', -1);
    expect(fn (): int => AccountDeletionGrace::days())->toThrow(InvalidArgumentException::class);

    config()->set('account.deletion_grace_days', '30');
    expect(fn (): int => AccountDeletionGrace::days())->toThrow(InvalidArgumentException::class);
});

test('検査 5: 空振り検知と正の自己検証', function (): void {
    $scanned = accountGraceScanTree(['app', 'config', 'database', 'routes']);

    expect(count($scanned))->toBeGreaterThan(0);
    expect(array_sum(array_column($scanned, 'tokens')))->toBeGreaterThan(0);

    // 検出器が死んでいたら検査 1 は vacuous green になる。SSOT では必ず 1 件点灯する。
    expect($scanned[ACCOUNT_GRACE_SOURCE_FILE]['configKey'])->toBe(1);
});

test('検査 6: 負のコントロール (リテラルは検出し、コメント中の表記は検出しない)', function (): void {
    $code = <<<'PHP'
    <?php
    class Fixture {
        public function run(): void {
            $a = config('account.deletion_grace_days');
            $b = config("account.deletion_grace_days");
        }
    }
    PHP;

    $comment = <<<'PHP'
    <?php
    /**
     * config('account.deletion_grace_days') を直読してはならない。
     */
    class Fixture {
        // config('account.deletion_grace_days')
        public function run(): void {}
    }
    PHP;

    expect(accountGraceScanSource($code)['configKey'])->toBe(2);
    expect(accountGraceScanSource($comment)['configKey'])->toBe(0);
    expect(accountGraceScanSource($comment)['tokens'])->toBeGreaterThan(0);
});

test('検査 7: purgeAfter は暦日 30 日で月末に丸められない', function (): void {
    // ★`addDaysNoOverflow` にすると 2026-01-31 + 30 日が **2026-02-28** に丸められ、
    //   猶予が 28 日になる (「30 日は取り消せます」という案内が嘘になる)。
    $requestedAt = CarbonImmutable::parse('2026-01-31 12:00:00');

    $purgeAfter = AccountDeletionGrace::purgeAfter($requestedAt);

    expect($purgeAfter->toDateTimeString())->toBe('2026-03-02 12:00:00');
    expect($requestedAt->diffInDays($purgeAfter))->toBe(30.0);
});

test('検査 8: うるう年の 2 月をまたいでも暦日 30 日である', function (): void {
    // 2028 はうるう年 (2/29 が存在する)。2028-02-10 + 30 日 = 2028-03-11。
    $requestedAt = CarbonImmutable::parse('2028-02-10 00:00:00');

    expect(AccountDeletionGrace::purgeAfter($requestedAt)->toDateTimeString())
        ->toBe('2028-03-11 00:00:00');

    // 非うるう年の同月日は 1 日ずれる (暦日加算であることの対照)
    expect(AccountDeletionGrace::purgeAfter(CarbonImmutable::parse('2027-02-10 00:00:00'))->toDateTimeString())
        ->toBe('2027-03-12 00:00:00');
});

test('検査 9: アプリのタイムゾーン設定下で期待するローカル時刻になる', function (): void {
    // 要件は「暦日 30 日」であり、時刻部分は動かさない (時差計算で日付が前後しない)。
    $requestedAt = CarbonImmutable::parse('2026-06-01 23:30:00', config('app.timezone'));

    $purgeAfter = AccountDeletionGrace::purgeAfter($requestedAt);

    expect($purgeAfter->getTimezone()->getName())->toBe($requestedAt->getTimezone()->getName());
    expect($purgeAfter->format('Y-m-d H:i:s'))->toBe('2026-07-01 23:30:00');
});
