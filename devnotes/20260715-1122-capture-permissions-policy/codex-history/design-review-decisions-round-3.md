# 対応マトリクス: design-review Round 3

## [Warning] T2/T3: binding 失敗時に SecurityHeaders の post-$next は走らない（SubstituteBindings が $next 前に例外）
- 判断: 対応する（Codex の指摘が正しい。実測で確定）
- 根拠: 一時 probe テスト (tests/Feature/Security/TempPermissionsProbeTest.php、観測後削除) で現行挙動を実測:
  - 撮影 show 200 → Permissions-Policy = baseline 値（SecurityHeaders は 200 で適用）
  - 撮影 show binding 失敗 404 → Permissions-Policy = **null（ヘッダなし）**
  - /app/nonexistent 未マッチ 404 → Permissions-Policy = **null（ヘッダなし）**
  SecurityHeaders は web group で SubstituteBindings より内側 (append) にあり、binding 失敗時は
  SubstituteBindings が $next 呼び出し前に ModelNotFoundException を投げるため SecurityHeaders に到達しない。
  round 1/2 の「binding 失敗 404 → capture 値」「未マッチ 404 → baseline」はいずれも誤りだった。
- 対応内容:
  - T3 test 4 を `assertNotFound()` + `assertHeaderMissing('Permissions-Policy')` に変更
    （404 には capture 緩和が漏れないことを固定）。
  - T2 設計判断の 404 節を実測結果で全面訂正（SecurityHeaders は正常応答経路でのみ適用、404 はヘッダなし）。
  - 実測ログを detailed-design.md に「検証ログ」として記録。

## [Suggestion] その他のテスト計画・allowlist narrowing
- 判断: 対応不要（Codex が妥当と確認）
