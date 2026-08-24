# 対応マトリクス: impl-review Round 4

> [Critical] は 0 件。残った文言整合 2 点をどちらも対応した。

## [Warning] 「削除と集約の間に commit した行は今回の 2 枝のどちらにも入らない」が広すぎる

- 判断: **対応する (指摘が正しい)**
- 根拠: 無期限 / 未来失効の行はその後の `contributingGroups()` に入るので持ち越されない。
  持ち越されるのは**削除完了後に追加された失効済みの行** (`expires_at <= now`) だけである。
- 対応内容: サービス docblock を
  「その間に commit した**失効済みの行** (`expires_at <= now`) は、今回の削除を通過済みで
  寄与側にも入らないため次回へ持ち越される (無期限 / 未来失効の行はその後の集約に入るので
  持ち越されない)」へ狭めた。N1c の実際の観測と一致する。

## [Warning] gate 冒頭の helper 件数が 4 のまま (実際は 5)

- 判断: **対応する**
- 対応内容: `ticketLedgerMutationIsAmbiguous` を列挙に追加し、件数を **5** へ直した
  (`ticketLedgerMutationScan` / `ticketLedgerMutationExpected` /
  `ticketLedgerMutationIsAmbiguous` / `ticketLedgerCarryForwardSource` /
  `ticketLedgerLockOrderViolations`)。

## 合議の打ち切りについて

`app-implement` スキルの規定は**最大 3 ラウンド**である。本件は Round 3 で [Critical] が 0 件になり、
Round 4 は文言整合の確認のみだった。Round 4 の指摘 2 件も**その場で全件対応済み**で、
未対応の指摘は 1 件も無い。以降のラウンドは回さず、この対応をもって実装完了とする
(スキル上限を超えて回し続けない。残る差は文言の精度であり、機能・不変条件には影響しない)。
