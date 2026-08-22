<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 逸脱の登録簿と指紋台帳の固定値 (不変の scalar 定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・git 実行を一切持たない**。値の置き場所を 1 か所にするための型である。
 *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
 *   2 つの gate (形式検査と突合) が同じ値を読むにはクラス定数である必要がある。
 * ★**これは免除の一覧ではない**。個別のパスや D 番号を名指しして規則を免除する仕組みは
 *   本機構のどこにも無い。
 */
final class LedgerPins
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
    public const int DIVERGENCE_ENTRY_COUNT = 41;

    /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
    public const int FINGERPRINT_POPULATION_COUNT = 281;

    /**
     * 採用時債務の件数。
     *
     * ★機械が保証するのは**無断の増減の検出**までである (一覧と本定数を同じ変更で
     *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
     *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
     */
    public const int ADOPTION_DEBT_COUNT = 156;

    /**
     * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
     *
     * ★掃除の判定は**登録の存在**で行う (対象パスだけを見ると、一覧ファイルを消して
     *   対象パス欄から一覧パスだけを削り登録を残す、という中途半端な掃除が緑になる)。
     *   同定に使うので番号を pin する。
     *   ★**引退時に外すのは対象パスの 1 行だけで、登録そのものは残る** —
     *   一覧が 0 件になっても判定機構 (`AdoptionDebtInventory`) は残り続けるので、
     *   本アプリ固有の追加としての説明は要る (詳しくは同クラスの docblock)。
     */
    public const int ADOPTION_DEBT_DIVERGENCE_ID = 34;

    /** 取り込んだ正典台帳の generated_at_commit (指紋台帳の出自 pin)。 */
    public const string TEMPLATE_LEDGER_SOURCE_COMMIT = 'a078806b0574518ddc64966f60f7d536b1338b2f';

    /**
     * 取り込んだ正典台帳ファイル自身の sha256 (生成器の入力ガード)。
     *
     * 取得元は laravel-claude-template の `docs/template-fingerprints.json`
     * (読み取りコミット `0597a0c24d7fa7a054e3337704ccc97e4409b866` / 947 キー / 128420 バイト)。
     * 別の台帳を食わせるには生成器へ `--adopt-new-template-ledger` を明示する。
     */
    public const string TEMPLATE_LEDGER_SOURCE_SHA256 = '0c9add21dc79429f6d80e38cfeb95736af750bd760ee9584d2e2b8a1285c0c90';

    /** アプリ側の指紋台帳の置き場 (リポジトリ相対)。 */
    public const string FINGERPRINT_LEDGER_PATH = 'docs/template-fingerprints.json';
}
