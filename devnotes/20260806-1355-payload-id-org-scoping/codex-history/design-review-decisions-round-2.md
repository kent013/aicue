# 対応マトリクス: design-review Round 2

全体判定 **APPROVED** (施策 A〜F すべて APPROVE)。

## [Suggestion] 実装前の fail 内容の記述が不正確 (ケース 1 は status ではなく文言の分岐)
- 判断: **対応する**
- 根拠: 指摘のとおり。transfer-ownership は現行でも両ケースとも validation failure に落ち、
  違うのは**文言だけ** (exists rule の既定文言 vs 「移譲先は組織のメンバーである必要があります。」)。
  projects.members.store は 403 と validation failure で status ごと分岐する。
- 対応内容: 検証コマンド表の期待結果を
  「ケース 1 は field error 文言の分岐、ケース 2 は 403 と validation failure の分岐」に訂正。

## 残課題
なし (Critical / Warning はすべて Round 1 で解消済み)。
