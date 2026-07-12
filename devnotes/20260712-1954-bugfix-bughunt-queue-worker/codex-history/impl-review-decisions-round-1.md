# 対応マトリクス: impl-review Round 1

全体判定: **APPROVED**（全ファイル APPROVE、Critical / Warning なし。Suggestion 1 件）

## [Suggestion] 実機 (real Postgres + BUGHUNT_ORCHESTRATOR) の運用確認手順を1回分明記
- 判断: 対応する（安価かつ運用事故低減に有効）
- 対応内容: `impl-notes.md` に live provisioning → queue 投入 → teardown --drop-db の
  最小手順と障害時の確認点 (worker-*.log / pidfile 残留 / pgid 残留) を明記。
  ※ 実機確認自体は bughunt インフラ一式を要するため本セッションでは未実施。self-test の
  構造・機能検査 ([y1]-[y6d]) で停止系の重要分岐を機能担保している。
