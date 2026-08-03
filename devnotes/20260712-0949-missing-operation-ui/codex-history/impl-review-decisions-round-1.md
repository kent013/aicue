# impl-review round 1 — 対応マトリクス

Codex 指摘 (impl-review-round-1.md) への対応判断。

## Critical

なし。

## Warning

| # | 指摘 | 判断 | 根拠 |
|---|------|------|------|
| W1 | `FortifyServiceProvider` の booted callback が複数回評価される実行コンテキストで `recent-auth` が重複付与され得る | **対応する** | `in_array('recent-auth', $route->middleware(), true)` の未付与ガードを追加し idempotent 化。安価で堅牢性が上がる |

## Suggestion

| # | 指摘 | 判断 | 根拠 |
|---|------|------|------|
| S1 | `withRecentAuth` 失敗時に UI へ明示エラーが出ない | **見送る** | `fetchRecentAuthStatus` は内部で例外を握って null を返し、`withRecentAuth` は delegated 分岐で「再認証が必要な場合は確認ページへ移動します」の info トーストを既に出す (lib/recent-auth.ts)。Promise が reject する現実的経路がなく、追加の共通トーストは既存 5 画面へ波及する変更のため本タスク (レビュー指摘修正) のスコープ外 |
| S2 | 再生成 stale ケースにも「GET /user/two-factor-recovery-codes 不発火」断言を追加 | **対応する** | 1 assertion 追加のみで回帰検出力が上がる (SettingsSecurity.test.ts の stale テストに追加) |
| S3 | `NO_TRANSFER_CANDIDATES` のエラー文もう一段の定数化 | **見送る** | 現状でも案内文とエラー文は単一定数を共有しテストも同文言を検証済み。連結部分の定数化は文言変更時に vitest が即検出するため実益が薄く、前回コミットの実装 (レビュー通過済み S3) を不必要に触らない |
