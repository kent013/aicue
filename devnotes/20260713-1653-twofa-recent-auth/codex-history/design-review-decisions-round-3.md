# 対応マトリクス: design-review Round 3

Round 3 判定: CHANGES_REQUESTED（S4 に Warning 1、S1/S2/S3/S5 APPROVE）。

## [Warning] S4: destructive closure 破棄の自動テストが未計画
- 判断: 対応する
- 根拠: 指摘は正当。新しいセキュリティ挙動（キャンセル時の pending 破棄）は AGENTS.md 禁止事項①「テストなしの実装完了」に該当しないよう自動テストが必須。既存 `tests/js/pages/SettingsSecurity.test.ts`（vitest + testing-library、`router` mock 済み、recent-auth stale/fresh の fetch mock helper `stubFetchRoutes` あり）が同種フローを既にカバーしており、disable フローを同ファイルに追加できる。
- 対応内容: S4 のテスト計画を「任意」から「必須（component テスト）」へ格上げ。`router.delete` を `routerDeleteMock` へ hoist し、以下 4 テストを追加:
  1. fresh → `routerDeleteMock` が exactly once。
  2. stale → recent-auth モーダル表示 + `routerDeleteMock` 未発火 + 確認ダイアログ close（二重モーダル回避）。
  3. stale → キャンセル → 別操作で recent-auth 成功しても `routerDeleteMock` 未発火（pending 破棄）。
  4. stale → password 確認成功 → resume で `routerDeleteMock` exactly once。
  施策一覧・変更ファイルに `tests/js/pages/SettingsSecurity.test.ts` を追加。
