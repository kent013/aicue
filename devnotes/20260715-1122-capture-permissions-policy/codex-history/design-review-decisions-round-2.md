# 対応マトリクス: design-review Round 2

## [Warning] T2/T3: 未マッチ 404 (/app/nonexistent) では web group 自体が起動せず「route null → baseline」は成立しない
- 判断: 対応する（指摘を採用。設計を訂正）
- 根拠: SecurityHeaders は web group の append に登録された route middleware。どの route にもマッチしない
  リクエストでは web group pipeline が起動しないため SecurityHeaders は走らず、Permissions-Policy は
  一切付かない（baseline ですらない）。よって test 4a の baseline 期待は誤り。
  なお binding 失敗 404（matched route）は別挙動: `Illuminate\Routing\Pipeline::handleException` が
  例外を pipeline 内で 404 レスポンスへ render するため SecurityHeaders の post-$next が走り、
  `$request->route()` は capture.manuals.show のまま → capture 値（round 1 で整理した 4b は正しい）。
- 対応内容:
  - T3 から test 4a（未マッチ 404 → baseline）を削除。
  - 4b を test 4（binding 失敗 404 → capture 値）に一本化し、handleException の render 経路を明記。
  - baseline fallback の検証は matched な非 allowlist route（test 2 の `/`・test 3 の capture.manuals.index）に集約。
  - T2 設計判断の「未マッチ 404 → baseline (fail-secure)」記述を削除し、未マッチ 404 は SecurityHeaders
    非起動で緩和漏れなし、と正確化。

## [Warning] test 4a は Permissions-Policy 自体が付かず失敗する可能性が高い
- 判断: 対応する（上記と同一。4a 削除で解消）
- 根拠: 同上。

## Round 1 指摘（binding 404 整理・不正型混入テスト）
- 判断: Codex が解消確認済み。変更なし。
