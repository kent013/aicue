<?php

declare(strict_types=1);

use App\Enums\Billing\TicketLedgerKind;

/*
 * Architecture invariant: **チケット台帳 (`ticket_ledger_entries`) を読む場所は deny-by-default の目録制**。
 *
 * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C2 (C2a)。
 *
 * ★なぜ要るか:
 *   保持期間 (7 年) の決着は**畳み込み** — 期限超過の個別取引行を消し、
 *   `(organization_id, source, expires_at)` ごとの**残高スナップショット 1 行**へ置き換える。
 *   帰結として、7 年より古い**個別取引の情報は復元できなくなる** (それが保持期間の意味である)。
 *   よって「台帳の個別行を読む場所」が宣言なしに増えると、ある日その画面 / 集計だけが
 *   静かに壊れる (行が消えているのに例外は起きない = 気付けない壊れ方)。
 *   目録は「増やすときに必ず読み方 (集計 / 個別行) を宣言させる」ための摩擦である。
 *
 * ★走査入口は 4 つ (詳細設計 C2a):
 *   1. モデル参照 (`TicketLedgerEntry` の識別子)
 *   2. table 名リテラル (`'ticket_ledger_entries'`)
 *   3. relation 名 (`ticketLedgerEntries`)
 *   4. 主要列名リテラル (`'delta'` / `'source'` / `'expires_at'`)
 *
 * ★この gate が保証するもの:
 *   - 検査 1: 走査で検出したファイルが目録と **exact-fit** (未登録 = fail / 幽霊登録 = fail)
 *   - 検査 2: 全 entry が読み方 (`aggregate` / `row_detail` / `other_table`) を宣言し、
 *     根拠が 30 文字以上
 *   - 検査 3: 空振り検知 (走査ファイル数 / 検出件数を**現在値ちょうど**で pin)
 *   - 検査 4: 自己参照コントロール (コメント・docblock 内の言及は 0 件 = 説明文で偽赤にならない)
 *   - 検査 5: 正のコントロール (4 入口それぞれが実際に点灯する = 検出器が死んでいない)
 *   - 検査 6: 負のコントロール (未登録ファイルを混ぜると検査 1 が点灯する)
 *   - 検査 7: **フロント側に台帳 kind の対応型が無い**ことを deny-by-default で固定する
 *     (持ち込むなら PHP enum ⇔ TS union の同期テストを同時に足させる)
 *   - 検査 8: 台帳 kind の全 case が表示ラベルを持ち、件数が現在値ちょうどで pin されている
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **目録が保証するのは「読んでいる場所を宣言なしに増やせない」ことだけ**である。
 *     動的 relation (`$org->{$name}`) / 変数 table 名 (`DB::table($t)`) /
 *     文字列を組み立てる raw SQL は**取りこぼす**。
 *     **最終保証は畳み込みの挙動テスト側** (tests/Feature/Billing/TicketLedgerCarryForwardTest.php) である
 *   - 宣言した読み方 (`aggregate` / `row_detail`) が**実際のコードと一致するか**は機械では見ない
 *     (人間の申告である)。gate が強制できるのは「宣言があること」まで
 *   - **列名リテラル (入口 4) の走査範囲は課金ディレクトリに限る**
 *     (`app/Models/Billing` / `app/Services/Billing` / `app/Console/Commands/Billing` /
 *      `app/Enums/Billing`)。`source` / `expires_at` は `ticket_reservations` /
 *      `ticket_checkout_sessions` / `api_keys` / `organization_invitations` 等が**同名の列**を
 *      持ち、app/ 全体を走査すると台帳と無関係な hit が大量に出て信号が死ぬ。
 *      **課金ディレクトリの外**で台帳の列名だけを使う経路には**沈黙する**
 *   - vendor / database/migrations / tests は母集団外 (migration は列定義そのものであり、
 *     tests は台帳を読むのが仕事である)
 */

/** 台帳モデルの短縮名 (識別子として現れる形)。 */
const TICKET_LEDGER_MODEL_IDENTIFIER = 'TicketLedgerEntry';

/** 台帳の table 名。 */
const TICKET_LEDGER_TABLE = 'ticket_ledger_entries';

/** 台帳への relation 名。 */
const TICKET_LEDGER_RELATION = 'ticketLedgerEntries';

/** 台帳の主要列名 (入口 4)。 */
const TICKET_LEDGER_COLUMNS = ['delta', 'source', 'expires_at'];

/**
 * 入口 4 (列名リテラル) の走査範囲。
 *
 * `source` / `expires_at` は他テーブルにも実在する一般名のため、app/ 全体では信号が死ぬ。
 * 課金ディレクトリに限ることで「台帳の近所で列名だけ使う新規経路」を捕まえる。
 */
const TICKET_LEDGER_COLUMN_SCAN_DIRS = [
    'Models/Billing',
    'Services/Billing',
    'Console/Commands/Billing',
    'Enums/Billing',
];

/**
 * 台帳を読む / 触る場所の目録 (app_path からの相対パス => [読み方, 根拠])。
 *
 * 読み方の語彙:
 * - `aggregate`   … 集計 (SUM / COUNT / MAX) でしか読まない。畳み込みに影響されない
 * - `row_detail`  … 個別取引行の属性に依存する。畳み込みで**情報が失われる**側
 * - `other_table` … 台帳ではない同名列を持つ別テーブルの経路 (入口 4 の巻き添え)
 *
 * @var array<string, array{string, string}>
 */
const TICKET_LEDGER_READER_INVENTORY = [
    'Models/Billing/TicketLedgerEntry.php' => [
        'row_detail',
        '台帳モデルそのもの。列定義と append-only guard (update/delete の例外化) を持つ',
    ],
    'Models/Organization.php' => [
        'aggregate',
        'relation 定義 (ticketLedgerEntries) のみ。行の中身は読まず件数・合算の入口を提供する',
    ],
    'Enums/Billing/BillingRetentionTarget.php' => [
        'aggregate',
        '保持期間の目録で台帳を target として宣言する。モデルクラスと起算列名の参照のみ',
    ],
    'Services/Billing/TicketLedgerService.php' => [
        'aggregate',
        '台帳の唯一の書き込み窓口。残高は source / expires_at 別の SUM で読み、個別取引行の識別子には依存しない',
    ],
    'Services/Billing/TicketLedgerCarryForwardService.php' => [
        'row_detail',
        '保持期間の畳み込み本体。期限超過の個別取引行を残高スナップショット 1 行へ置換する唯一の経路',
    ],
    'Services/Billing/Retention/TicketLedgerEntryPurger.php' => [
        'aggregate',
        '保持期間 purger の adapter。件数の集計と畳み込みサービスへの委譲だけを行う',
    ],
    'Models/Billing/TicketReservation.php' => [
        'other_table',
        'ticket_reservations の expires_at (予約 TTL) であり台帳の失効時刻ではない。入口 4 の巻き添え',
    ],
    'Models/Billing/TicketCheckoutSession.php' => [
        'other_table',
        'ticket_checkout_sessions の expires_at (Checkout Session の失効) であり台帳ではない',
    ],
    'Services/Billing/TicketCheckoutService.php' => [
        'other_table',
        'ticket_checkout_sessions の expires_at を扱う購入手続きの経路であり台帳は読まない',
    ],
];

/** 読み方の語彙 (exact-fit)。 */
const TICKET_LEDGER_READ_MODES = ['aggregate', 'row_detail', 'other_table'];

/** 走査ファイル数の下限 (degenerate PASS 防止)。 */
const TICKET_LEDGER_SCAN_FLOOR = 200;

/**
 * PHP ソースから台帳への参照入口を検出する。
 *
 * コメント / docblock は code token ではないので拾わない (説明文で偽赤にならない)。
 * 文字列リテラルは table 名・relation 名・列名の照合に要るので**値だけ**見る
 * (中身を PHP として解釈はしない)。
 *
 * @param  bool  $scanColumns  入口 4 (列名リテラル) を有効にするか
 * @return list<string> 検出した入口のラベル (重複排除済み)
 */
function ticketLedgerReferenceEntries(string $source, bool $scanColumns): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);

    $found = [];
    foreach ($tokens as $token) {
        if ($token->is([T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML])) {
            continue;
        }

        if ($token->is(T_STRING)) {
            if ($token->text === TICKET_LEDGER_MODEL_IDENTIFIER) {
                $found['model'] = 'model';
            }
            if ($token->text === TICKET_LEDGER_RELATION) {
                $found['relation'] = 'relation';
            }

            continue;
        }

        if (! $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
            continue;
        }

        $value = trim($token->text, "'\"");
        if ($value === TICKET_LEDGER_TABLE) {
            $found['table'] = 'table';
        }
        if ($value === TICKET_LEDGER_RELATION) {
            $found['relation'] = 'relation';
        }
        if ($scanColumns && in_array($value, TICKET_LEDGER_COLUMNS, true)) {
            $found['column'] = 'column';
        }
    }

    return array_values($found);
}

/**
 * app/ 配下の PHP ファイル (app_path からの相対パス)。
 *
 * @return list<string>
 */
function ticketLedgerScanFiles(): array
{
    $base = app_path();
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $files[] = str_replace($base.'/', '', $file->getPathname());
    }

    sort($files);

    return $files;
}

/**
 * 走査結果 (相対パス => 検出した入口ラベル)。
 *
 * @return array<string, list<string>>
 */
function ticketLedgerDetected(): array
{
    $detected = [];
    foreach (ticketLedgerScanFiles() as $relative) {
        $source = file_get_contents(app_path($relative));
        if ($source === false) {
            continue;
        }

        $scanColumns = false;
        foreach (TICKET_LEDGER_COLUMN_SCAN_DIRS as $dir) {
            if (str_starts_with($relative, $dir.'/')) {
                $scanColumns = true;

                break;
            }
        }

        $entries = ticketLedgerReferenceEntries($source, $scanColumns);
        if ($entries !== []) {
            $detected[$relative] = $entries;
        }
    }

    ksort($detected);

    return $detected;
}

test('検査 1: 台帳を読む場所が目録と exact-fit である', function (): void {
    $detected = array_keys(ticketLedgerDetected());
    $declared = array_keys(TICKET_LEDGER_READER_INVENTORY);
    sort($declared);

    $missing = array_values(array_diff($detected, $declared));
    $phantom = array_values(array_diff($declared, $detected));

    expect($missing)->toBe([],
        '台帳を読む場所が目録に登録されていません。読み方 (aggregate / row_detail / other_table) と '
        .'30 文字以上の根拠を TICKET_LEDGER_READER_INVENTORY へ登録してください。'
        .PHP_EOL.implode(PHP_EOL, $missing));

    expect($phantom)->toBe([],
        '目録にあるが実在しない / 台帳を参照しなくなったファイルです (残置を消してください): '
        .implode(', ', $phantom));
});

test('検査 2: 全 entry が読み方を宣言し根拠が 30 文字以上である', function (): void {
    $violations = [];
    foreach (TICKET_LEDGER_READER_INVENTORY as $path => [$mode, $rationale]) {
        if (! in_array($mode, TICKET_LEDGER_READ_MODES, true)) {
            $violations[] = $path.': 未知の読み方 '.$mode;
        }
        if (mb_strlen($rationale) < 30) {
            $violations[] = $path.': 根拠が 30 文字未満';
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('検査 3: 空振り検知 (走査ファイル数と検出件数を pin する)', function (): void {
    expect(count(ticketLedgerScanFiles()))->toBeGreaterThan(TICKET_LEDGER_SCAN_FLOOR);
    expect(ticketLedgerDetected())->toHaveCount(count(TICKET_LEDGER_READER_INVENTORY));
    expect(TICKET_LEDGER_READER_INVENTORY)->not->toBeEmpty();

    // 正の自己検証: 実ファイルで検出器が実際に点灯する (検出器が死んでいない)
    $service = file_get_contents(app_path('Services/Billing/TicketLedgerService.php'));
    expect($service)->toBeString();
    expect(ticketLedgerReferenceEntries((string) $service, true))->toContain('model');
});

test('検査 4: 自己参照コントロール (コメント・docblock 内の言及は検出しない)', function (): void {
    $fixture = <<<'PHP'
        <?php
        /**
         * 残高の真実源は ledger (TicketLedgerEntry) である。
         * table 名は ticket_ledger_entries、relation は ticketLedgerEntries。
         */
        final class Documented
        {
            // delta / source / expires_at の意味はここに書く
            public function noop(): void {}
        }
        PHP;

    expect(ticketLedgerReferenceEntries($fixture, true))->toBe([]);

    // 実在の証拠: コメントでしか台帳に触れないファイルは目録に載らない
    expect(TICKET_LEDGER_READER_INVENTORY)
        ->not->toHaveKey('Services/Billing/AutoRechargeService.php');
    expect(TICKET_LEDGER_READER_INVENTORY)
        ->not->toHaveKey('Models/Billing/TicketAutoRecharge.php');
});

test('検査 5: 正のコントロール (4 入口それぞれが点灯する)', function (): void {
    $model = <<<'PHP'
        <?php
        use App\Models\Billing\TicketLedgerEntry;
        final class R { public function f(): void { TicketLedgerEntry::query()->get(); } }
        PHP;
    expect(ticketLedgerReferenceEntries($model, false))->toBe(['model']);

    $table = <<<'PHP'
        <?php
        final class R { public function f(): void { DB::table('ticket_ledger_entries')->get(); } }
        PHP;
    expect(ticketLedgerReferenceEntries($table, false))->toBe(['table']);

    $relation = <<<'PHP'
        <?php
        final class R { public function f($org): void { $org->ticketLedgerEntries()->count(); } }
        PHP;
    expect(ticketLedgerReferenceEntries($relation, false))->toBe(['relation']);

    $column = <<<'PHP'
        <?php
        final class R { public function f($q): void { $q->sum('delta'); } }
        PHP;
    expect(ticketLedgerReferenceEntries($column, true))->toBe(['column']);

    // 入口 4 は走査範囲を絞っている (無効時は点灯しない)
    expect(ticketLedgerReferenceEntries($column, false))->toBe([]);
});

test('検査 7: フロント側に台帳 kind の対応型が無い (増やすなら TS 同期テストが要る)', function (): void {
    // C2b で `TicketLedgerKind` に `carry_forward` を足した。**現時点で `resources/js` 側に
    // 台帳 kind の対応型も表示分岐も存在しない**ため TS 同期テストは不要である
    // (ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest のような
    //  literal union が 1 つも無い)。この「不在」を deny-by-default で固定する —
    // フロントに台帳 kind を持ち込むなら、同時に enum ⇔ TS union の同期テストを足させる。
    $hits = [];
    $base = resource_path('js');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'svelte'], true)) {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            continue;
        }
        foreach (['TicketLedgerKind', 'reserve_commit', 'carry_forward'] as $needle) {
            if (str_contains($source, $needle)) {
                $hits[] = str_replace($base.'/', '', $file->getPathname()).' => '.$needle;
            }
        }
    }

    expect($hits)->toBe([],
        'フロントに台帳 kind の対応型 / 表示分岐が現れました。PHP enum ⇔ TS union の '
        .'同期テスト (Tests\Support\TsUnionValues) を同時に追加してください。'
        .PHP_EOL.implode(PHP_EOL, $hits));

    // 空振り検知: 走査が実際にファイルへ届いている
    expect(is_dir($base))->toBeTrue();
    expect(file_get_contents(resource_path('js/types/manual.ts')))->toContain('export type');
});

test('検査 8: 台帳 kind は全 case が表示ラベルを持つ (表示分岐の網羅)', function (): void {
    foreach (TicketLedgerKind::cases() as $case) {
        expect($case->label())->not->toBe('');
    }

    // 現在値ちょうどで pin (case を足したら必ずこの数字を書き換える = 表示分岐を見直す契機)
    expect(TicketLedgerKind::cases())->toHaveCount(6);
    expect(TicketLedgerKind::CarryForward->value)->toBe('carry_forward');
});

test('検査 6: 負のコントロール (未登録ファイルを混ぜると検査 1 が点灯する)', function (): void {
    $detected = array_keys(ticketLedgerDetected());
    $detected[] = 'Services/Billing/UndeclaredLedgerReader.php';
    $declared = array_keys(TICKET_LEDGER_READER_INVENTORY);

    expect(array_values(array_diff($detected, $declared)))
        ->toBe(['Services/Billing/UndeclaredLedgerReader.php']);
});
