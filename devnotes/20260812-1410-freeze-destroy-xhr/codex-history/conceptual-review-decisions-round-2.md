# 対応マトリクス: conceptual-review Round 2

判定 CHANGES_REQUESTED。Critical 0 / Warning 2。**両方対応**(反論なし)。

## [Warning] 契約 4 が仕様決定になっていない (現実装の追認は priority の偶然を契約化する)

- 判断: **対応する**
- 根拠: 妥当。「どちらであれ固定する」は仕様を決めていない。
- 対応内容: 「**順序の決定**」節を新設し、
  **凍結中の即時削除は recent-auth の有無にかかわらず 409** と決めた。理由は
  (a) 凍結状態を知るのは本人で `/settings` に既に表示しており**秘匿すべき相手がいない**、
  (b) **再認証させてから断るのは体験として悪い**、の 2 つ。
  「現在の group/route 順と一致する」ことは**根拠ではなく確認**として書き、
  実行順が変わっても 409 が正であることを契約テストで固定する、とした。

## [Warning] `deletion_requested` だけでは「どうやって通ったか」に到達できない

- 判断: **対応する**
- 根拠: 実査したところ `security_audit_events` は
  `user_id` / `event_type` / `metadata` / `ip_address` / `occurred_at` しか持たず、
  **route 名も HTTP メソッドも残っていない**。
- 対応内容: metadata に **`deletion_requested` / `route` / `method`** の 3 つを載せる形へ拡張した
  (いずれも PII ではない)。既存テーブルが何を持っているかも設計に明記した。
