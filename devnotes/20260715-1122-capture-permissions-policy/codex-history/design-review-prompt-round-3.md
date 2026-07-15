## Round 3: Round 2 指摘への対応

T2/T3 の Warning（未マッチ 404 では web group 自体が起動せず「route null → baseline」は成立しない）を採用し訂正しました。

### 訂正内容
- **未マッチ 404**（どの route にもマッチしないパス）: SecurityHeaders は web group append の route middleware の
  ため、route 未マッチ時は web group pipeline が起動せず SecurityHeaders は走らない → Permissions-Policy は
  一切付かない（baseline ですらない）。緩和の漏れは起きない。よって **test 4a（未マッチ 404 → baseline）を削除**しました。
- **binding 失敗 404**（matched `capture.manuals.show` で存在しない manual id）: `SubstituteBindings` の
  `ModelNotFoundException` は `Illuminate\Routing\Pipeline::handleException` が pipeline 内で 404 レスポンスへ
  render するため、SecurityHeaders の `$next()` は例外を投げずに 404 レスポンスを返し post-`$next` が走る。
  `$request->route()` は capture.manuals.show のまま → **capture 値**。これを **test 4** として残します。
- **baseline fallback の検証**は matched な非 allowlist route に集約:
  - test 2: `/`（非 capture）→ baseline（既存 L16-19 = 非退行）
  - test 3: `capture.manuals.index`（capture 内の非 recorder matched route）→ baseline
- T2 設計判断からも「未マッチ 404 → baseline (fail-secure)」記述を削除し、未マッチ 404 は SecurityHeaders
  非起動で緩和漏れなし、と正確化しました。

### 修正後の T3 テスト一覧
1. capture.manuals.show（200）応答の Permissions-Policy 完全一致 = capture 値
2. `/`（非 capture, 200）は baseline（既存 = 非退行）
3. capture.manuals.index（200）は baseline（least-privilege / allowlist 外 matched route）
4. capture.manuals.show の binding 失敗 404 は capture 値（handleException の pipeline 内 render 経由）
5. capture 用 config 空文字（opt-out）で非送出（capture.manuals.show で assertHeaderMissing）
6. allowlist に非文字列混入（['capture.manuals.show', 123, null]）でも文字列のみ採用（show=capture / index=baseline）
7. 既存 SecurityHeadersTest 非退行

以上で T2/T3 の Warning は解消したと考えます。各施策および全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
