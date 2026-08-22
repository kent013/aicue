Round 4 の境界越えは閉じています。§2 の位置を決める前に文書全体へ HTML 拒否を適用したため、HTML block 内の偽の章・アンカー・表は通りません。

### `tests/Architecture/IntegrationGuideGateTableSyncTest.php`

判定: 適合。

- fenced code、字下げ、HTMLコメント、HTML blockを構造行から分離しています。
- 閉じない構文や限定文法外の形は例外になり、未解決値を戻り値へ混ぜていません。
- 表の件数・実在・表間一意性をすべて判定に使用しています。
- Round 4 の境界越えを直接再現する負例が追加されています。
- offset検査、`preg_match()` の厳密比較、捕獲群の型絞りもPHPStan level 10に適合しています。

### `docs/app-integration-guide.md`

判定: 適合。設計された8件／13件の索引、項目名による§7参照、保証範囲の記述に問題ありません。

### `docs/template-divergence.md`

判定: 適合。D40が述べる実在・件数・一意性の保証と、修正後の同期検査が一致しています。

### `LedgerPins.php` / `adoption-debt.tsv`

判定: 適合。登録37件、採用時債務170件、D40への付け替えが整合しています。

全必須検証コマンドも本ラウンドの状態でgreenになっており、DTO／HTTP境界、UI、Atomic Designへの波及もありません。残るブロッキングな懸念はありません。

APPROVED