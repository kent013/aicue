# 対応マトリクス: impl-review Round 1

Round 1 は **APPROVED** (Critical / Warning なし。Suggestion 2 件)。

## [Suggestion] `?session_id=...` + error flash の keep 経路もテストで固定する
- 判断: **対応する**
- 根拠: 妥当。`error` 抑止 + `keep(['error'])` は **着地 hop 共通の分岐**であり、
  portal 専用ではない。テストが `?portal=1` の 1 本しかないと、
  「error 抑止は portal 着地の話」という誤読を招き、将来 `session_id` 側に
  分岐を割られたときに検出できない。
- 対応内容: `BillingFeedbackTest` に
  「`?session_id` 着地でも error flash があれば feedback を出さず error を keep する」
  を追加 (303 + `assertSessionMissing(FLASH_KEY)` + `assertSessionHas('error')` +
  追従先 props で `flash.error` が届くところまで)。25 → 26 ケース。

## [Suggestion] PHP enum ⇔ TS union の同期テストがあるとさらに堅い
- 判断: **見送る**
- 根拠: 概念設計の「スコープ外」に
  「`BillingFeedbackKind` の PHP enum ⇔ TS union 同期テスト (`TsUnionValues`) の新設。
  値集合を変えないため今回の不変条件ではない」と明記済み。
  本 TODO は F-3-04 (one-shot 契約) の修正であり、値集合は 1 つも増減していない。
  ここで同期テスト基盤を持ち込むのは思考原則 2 (今必要なものだけ作る) に反する。
  なお PHP 側は `value-of<BillingFeedbackKind>` 化により **PHP 内の drift は解消済み**
  (手書き literal union を削除した)。TS 側同期は別 TODO として切り出すのが筋。
- 対応内容: 変更なし (理由を本ファイルに記録)。
