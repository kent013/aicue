<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

/**
 * 追記専用チケット台帳の**変更サイトの目録** (deny-by-default + 件数完全一致)。
 *
 * ★**グローバル定数・グローバル関数を 1 つも宣言しない**。既存の
 *   `tests/Architecture/TicketLedgerReaderInventoryTest.php` が
 *   グローバル定数 `TICKET_LEDGER_TABLE` 等とグローバル関数 `ticketLedgerScanFiles()` を
 *   宣言しており、Pest は同一プロセスでテストファイルを読み込むため、同名を宣言すると
 *   `Cannot redeclare` で Architecture レーン全体が落ちる。目録と走査器は
 *   クラス定数 / static メソッドに置く (`DirectFetchInventory` / `LedgerPins` と同じ作法)。
 *
 * ★**件数は「実測 → 申告」の順で確定させる**。gate を赤で走らせて実測を読み、
 *   その値を申告する。合わないときは理由を読んでコード側が正しいのか申告が正しいのかを
 *   判断する (緩めない)。
 */
final class TicketLedgerMutationInventory
{
    /** 畳み込みサービス (台帳の行を物理削除し残高スナップショットへ置換する唯一の経路)。 */
    public const string CARRY_FORWARD_FILE = 'app/Services/Billing/Retention/TicketLedgerCarryForwardService.php';

    /** 台帳の書き込み窓口。 */
    public const string LEDGER_SERVICE_FILE = 'app/Services/Billing/TicketLedgerService.php';

    /** 変更語彙。 @var list<string> */
    public const array MUTATION_VERBS = [
        'save', 'delete', 'truncate', 'insert', 'insertOrIgnore', 'update', 'upsert', 'forceDelete',
    ];

    /** 削除語彙。 @var list<string> */
    public const array DELETE_VERBS = ['delete', 'truncate', 'forceDelete'];

    /** 母集団の下限 (走査根取り違えの補助検出。現在 933 ファイル)。 */
    public const int SCAN_FLOOR = 500;

    /** 畳み込みのロック順序を見るメソッド名。 */
    public const string LOCK_ORDER_METHOD = 'carryForwardOrganization';

    /** 繰越行の追記の呼び出し (TLM-5 の 5 条が `DB::transaction(` の引数範囲の内側にあることを要求する)。 */
    public const string APPEND_CALL = 'appendCarryForward';

    /** インスタンス化しない (目録の置き場)。 */
    private function __construct() {}

    /**
     * 表名リテラルを持ってよいファイル => {count, reason} (全数申告 + 件数完全一致)。
     *
     * @return array<string, array{count: int, reason: string}>
     */
    public static function tableLiteralSites(): array
    {
        return [
            self::CARRY_FORWARD_FILE => [
                'count' => 1,
                'reason' => '畳み込みの集計 (cast を通さないクエリビルダ) の対象表。集計を 1 文で取るため表名を直に書く',
            ],
            self::LEDGER_SERVICE_FILE => [
                'count' => 2,
                'reason' => '冪等 insert (insertOrIgnore) と payment_intent_id の backfill UPDATE。どちらも caster を通さない',
            ],
        ];
    }

    /**
     * モデル参照 + 変更語彙を同居させてよいファイル => {count, reason}
     * (`count` は**変更語彙の出現数**)。
     *
     * @return array<string, array{count: int, reason: string}>
     */
    public static function mutationSites(): array
    {
        return [
            self::CARRY_FORWARD_FILE => [
                'count' => 3,
                'reason' => '行の物理削除と残高スナップショットへの置換を行う唯一の経路 (範囲削除 2 + 繰越行の save 1)',
            ],
            self::LEDGER_SERVICE_FILE => [
                'count' => 7,
                'reason' => '台帳の追記 (appendEntry の save + 冪等 insert) と予約行の状態遷移 (save 4) と backfill の update 1。削除語彙は持たない',
            ],
        ];
    }

    /**
     * 削除語彙を持ってよいファイル (畳み込み 1 ファイルだけ)。
     *
     * @return array<string, array{count: int, reason: string}>
     */
    public static function deleteSites(): array
    {
        return [
            self::CARRY_FORWARD_FILE => [
                'count' => 2,
                'reason' => '失効済みの行の範囲削除と、集約キーごとの行の範囲削除。行の物理削除は append-only の唯一の例外である',
            ],
        ];
    }

    /**
     * 論理削除の scope を使ってよいファイル => {count, reason}。
     *
     * @return array<string, array{count: int, reason: string}>
     */
    public static function trashedScopeSites(): array
    {
        return [
            self::CARRY_FORWARD_FILE => [
                'count' => 2,
                'reason' => '退会 (論理削除) 済み組織の台帳も保持期限の対象である。組織の列挙と組織行ロックの 2 箇所だけ',
            ],
        ];
    }
}
