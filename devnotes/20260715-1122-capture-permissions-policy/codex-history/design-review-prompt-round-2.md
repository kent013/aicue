## Round 2: Round 1 指摘への対応

T1・T2 は APPROVE いただきました。T3 の 2 Warning に対応しました。

### [Warning] T3 の 404 前提が実装実態とズレる（binding 失敗 404 は routeIs=true）→ 対応
ご指摘のとおり、scopeBindings のモデル解決失敗は route マッチ後段（SubstituteBindings）で起きるため
`$request->route()` は capture.manuals.show のまま残り routeIs() は true です。当初の「存在しない manual id → baseline」
前提は誤りでした。T3 の 404 テストを 2 系統に分離しました:

- **4a. route 未解決パス**（`/app/nonexistent`。どの capture route にもマッチしない 404）
  → `$request->route()` null → routeIs false → **baseline**（真の fail-secure）
- **4b. binding 失敗 404**（`capture.manuals.show` の存在しない manual id → scopeBindings 404）
  → route マッチ済みで routeIs true → **capture 値**（実装仕様として固定。404 error document は recorder では
     ないが同一 route 名・同一オリジン self のみで攻撃面は recorder ページと同等、cross-origin は開かない）

T2 設計判断にも binding 失敗 404 の扱いを明示追記しました。

### [Warning] config()->array() の list<string> narrowing をテストで固める → 対応
T3 に追加:
- `config()->set('security.capture_permissions_policy_routes', ['capture.manuals.show', 123, null])` の下で
  `capture.manuals.show` は capture 値、非 recorder（`capture.manuals.index`）は baseline。
  `array_values(array_filter(config()->array(...), is_string(...)))` が非文字列要素を落とすことを固定。

### [Suggestion] directive 検証 / config コメント → 一部対応
- test 1 が Permissions-Policy を**完全一致**で検証しており camera=(self)/microphone=(self) の存在は担保済みのため
  追加テストは設けません。
- config コメントには「camera/microphone を (self) に緩める理由」「document 単位に効くため
  capture.manuals.show 以外へ広げない（XSS 時の権限面積増大）／将来追加はレビュー対象」を既に明記しています。

### 修正後の T3 テスト一覧
1. capture.manuals.show 応答の Permissions-Policy 完全一致 = capture 値
2. 非 capture（/）は baseline（既存 L16-19 = 非退行）
3. capture.manuals.index は baseline（least-privilege）
4a. /app/nonexistent（route 未解決）は baseline（真の fail-secure）
4b. capture.manuals.show の binding 失敗 404 は capture 値（実装仕様）
5. capture 用 config 空文字（opt-out）で非送出
6. allowlist に非文字列混入時も文字列のみ採用（show=capture 値 / index=baseline）
7. 既存 SecurityHeadersTest 非退行

以上で T3 の Warning は解消したと考えます。各施策および全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
