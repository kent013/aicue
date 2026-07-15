# 対応マトリクス: conceptual-review Round 2

## [Warning] /app/* 未解決ルート (404) の fail-secure がテスト観点から漏れている
- 判断: 対応する
- 根拠: AGENTS.md 禁止事項 #1。fail-secure 契約はテストで固定すべき。
- 対応内容: テスト観点に「/app 配下の未解決 404 は厳格値を維持 (fail-secure)」を追加 (観点4)。

## [Warning] 許可対象を capture.* 全体でなく撮影 document route (capture.manuals.show) に限定せよ (least-privilege)
- 判断: 対応する (Codex の指摘を採用)
- 根拠: Permissions-Policy は document 単位に効くため、recorder を描画しない他の capture HTML document
  (capture.manuals.index) に camera=(self) を付けると、そこで XSS が成立した場合に camera/microphone を
  要求できてしまう。recorder を描画するのは grep 確認の結果 pages/Capture/Show.svelte
  (= capture.manuals.show) の 1 ルートのみ。よって撮影 document route に限定する方が最小権限で、
  かつ resolver 内の routeIs 引数を絞るだけなので複雑さは増えない (専用 middleware は不要)。
- 対応内容: 緩和対象を config 駆動 allowlist `security.capture_permissions_policy_routes = ['capture.manuals.show']`
  とし、`$request->routeIs(...allowlist)` で判定。将来撮影画面が増えたら allowlist へ明示追加。
  概念設計の改善アイデア・実装方針・テスト観点を更新。

## [Suggestion] 使命・実現可能性・型安全性
- 判断: 対応不要 (Codex が成立を確認)
