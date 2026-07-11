# 対応マトリクス: conceptual-review Round 4（APPROVED）

## [Suggestion] terminal-ack（attempts 上限到達）は「決済済み・未付与」を残し得るため運用アラート対象にせよ
- 判断: 対応する
- 対応内容: 詳細設計の webhook 施策に「terminal-ack 到達時は `Log::warning` の構造化ログ（既存 claim() の terminal ログ）に加え、ticket_purchase 系 event では `report()` で例外レポートに載せる（= 運用アラート経路）」を明記する。

全体判定 APPROVED（Round 4）。概念設計フェーズ完了。
