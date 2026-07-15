## Round 4: Round 3 指摘への対応（実測で確定）

ご指摘のとおりでした。middleware 順序を推測で断定するのを避け、一時 probe テストで現行挙動を**実測**しました
（観測後にテストファイルは削除。コミットしません）:

| ケース | status | Permissions-Policy |
|--------|--------|--------------------|
| 撮影 show（200 成功） | 200 | baseline 値（現行。改修後は capture 値） |
| 撮影 show の binding 失敗 404（存在しない manual id） | 404 | **null（ヘッダなし）** |
| `/app/nonexistent`（未マッチ 404） | 404 | **null（ヘッダなし）** |

結論: `SecurityHeaders` は web group で `SubstituteBindings` より内側 (append) にあり、binding 失敗時は
`SubstituteBindings` が `$next()` 呼び出し前に `ModelNotFoundException` を投げるため `SecurityHeaders` に
到達しません。よって **404 には Permissions-Policy が一切付かない**（capture 緩和が error 応答に漏れない
= fail-safe）。前ラウンドの「binding 失敗 404 → capture 値」「未マッチ 404 → baseline」は誤りでした。

### 訂正内容
- **T3 test 4** を `assertNotFound()` + `assertHeaderMissing('Permissions-Policy')` に変更
  （binding 失敗 404 で capture 緩和が漏れないことを固定）。ご提案どおりです。
- **T2 設計判断**の 404 節を実測結果へ全面訂正: SecurityHeaders は正常にレスポンスが返る経路でのみ適用され、
  その場合 allowlist 外 matched route は baseline（test 2・3）、allowlist 一致は capture 値（test 1）。
  404 はヘッダなし。
- 実測ログを detailed-design.md に「検証ログ」として記録。

### 修正後の T3 テスト一覧
1. capture.manuals.show（200）応答の Permissions-Policy 完全一致 = capture 値
2. `/`（非 capture, 200）は baseline（既存 = 非退行）
3. capture.manuals.index（200）は baseline（least-privilege / allowlist 外 matched route）
4. capture.manuals.show の binding 失敗 404 は `assertHeaderMissing('Permissions-Policy')`（緩和漏れなし）
5. capture 用 config 空文字（opt-out）で非送出（capture.manuals.show で assertHeaderMissing）
6. allowlist に非文字列混入（['capture.manuals.show', 123, null]）でも文字列のみ採用（show=capture / index=baseline）
7. 既存 SecurityHeadersTest 非退行

以上で残 Warning は解消したと考えます。各施策および全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
