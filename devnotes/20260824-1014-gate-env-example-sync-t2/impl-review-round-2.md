提示差分による静的再レビューです。テストは再実行していません。

### `tests/Architecture/EnvExampleInvariantTest.php`

判定: 要修正

Round 1の検出力に関する指摘は解消されています。

- V22により同一種別内の重複を検証できています。
- V23/V24により、両方の申告mapで余分・不足の両方向が揃っています。
- 識別子の順序も意図した不変条件として明文化されています。

Warning — `$withEntry`の型契約はまだ完全には健全ではありません。

`$entry`は固定shapeになりましたが、`$index`が任意の`int`です。例えば`$index = 5`なら、listへキー5を代入して非連続配列にできる一方、戻り値は`list<...>`と宣言されています。Round 1の「将来PHPStanへ編入しても耐える型注記」は部分的な解消に留まります。

固定位置専用のヘルパーにするか、返却時に`array_values($entries)`でlistへ正規化するなど、宣言上も必ずlistになる必要があります。

Warning — V22〜V24追加後のdocblockに古い記述が残っています。

`envExampleLedgerViolations()`の冒頭が、依然として次の記述です。

> 各規則の判定分岐を負のコントロール V1〜V21 が対応表で押さえる

実際には、規則1と規則4の検出力を成立させるためV22〜V24が必要です。保証機構の正本となるdocblockなので、`V1〜V24`へ更新してください。

また、反証データセット側の「各負例は健全な素材の複製に1か所だけ手を入れる」という説明は、V22ではentry追加に加えて種別件数と分類件数も調整しているため、字義どおりではありません。「欠陥は1種類だけ導入し、申告件数は実件数へ合わせる」など、実態に合う表現が適切です。

### `docs/template-divergence.md`

判定: 問題なし

Critical / Warning / Suggestion: なし。

「識別子集合」から「識別子の並び」への修正は実装の`toBe()`と一致しています。D51と件数48の整合も維持されています。

### `tests/Support/TemplateDivergence/LedgerPins.php`

判定: 問題なし

Critical / Warning / Suggestion: なし。提示された状態では逸脱48件、債務147件で整合しています。

### `tests/Support/TemplateDivergence/adoption-debt.tsv`

判定: 問題なし

Critical / Warning / Suggestion: なし。D51への移送と債務行の削除は整合しています。

## 全体判定

**CHANGES_REQUESTED**

検出力不足と識別子順序の指摘は解消されています。一方、型安全化したヘルパーの戻り値契約と、V22〜V24追加後のdocblockに小さいながら明確な不整合が残っています。