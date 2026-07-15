## Round 3: Round 2 指摘への対応

両 Warning とも対応しました。

### [Warning] /app/* 未解決 404 の fail-secure テスト → 対応
テスト観点に「/app 配下の未解決 404 は route 未解決のため厳格値 (camera=(), microphone=()) を維持 (fail-secure)」を追加しました。

### [Warning] 許可対象を撮影 document route (capture.manuals.show) に限定 → 採用
- recorder (CameraRecorder.svelte) を描画するのは grep 確認の結果 `pages/Capture/Show.svelte`
  (= route `capture.manuals.show`) の 1 ルートのみです。
- 緩和対象を config 駆動 allowlist `security.capture_permissions_policy_routes = ['capture.manuals.show']`
  とし、middleware は `$request->routeIs(...config()->array('security.capture_permissions_policy_routes'))`
  で判定します。将来撮影画面が増えたら allowlist へ明示追加します (least-privilege を運用で維持)。
- resolver 内の routeIs 引数を絞るだけで専用 middleware は増やしません。

### 修正後のテスト観点
1. 撮影 document ルート (capture.manuals.show) 応答に camera=(self), microphone=(self) が含まれる
2. 非 capture ルート (/) は camera=(), microphone=() を維持
3. capture 内の非 recorder ルート (capture.manuals.index) も厳格値を維持 (least-privilege)
4. /app 配下の未解決 404 は厳格値を維持 (fail-secure)
5. 撮影 document でも geolocation=() / payment=(self stripe) が不変 (他 directive 回帰)
6. capture 用 config が空文字 (opt-out) のとき非送出
7. 既存 SecurityHeadersTest の非退行

以上で残 Warning は解消したと考えます。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
