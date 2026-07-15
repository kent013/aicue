# 対応マトリクス: design-review Round 1

## [Warning] T3 の 404 ケースの前提が実装実態とズレる（scopeBindings の binding 失敗 404 は routeIs=true）
- 判断: 対応する（指摘を採用。設計の fail-secure 記述を訂正）
- 根拠: scopeBindings のモデル解決失敗は route マッチ後段（SubstituteBindings）で起きるため
  `$request->route()` は capture.manuals.show のまま = routeIs() true。よって当該 404 には capture 値が付く。
  「存在しない manual id → baseline」という当初のテスト前提は誤り。真の fail-secure は
  どの route にもマッチしないパス（/app/nonexistent → route null → baseline）。
- 対応内容: T3 のテストを 4a（route 未解決パス /app/nonexistent → baseline = 真の fail-secure）と
  4b（binding 失敗 404 on capture.manuals.show → routeIs true → capture 値、実装仕様として固定）に分離。
  T2 設計判断に binding 失敗 404 の扱いを明示追記。

## [Warning] config()->array() の list<string> narrowing をテストで固める（不正型混入で安全側に倒れる）
- 判断: 対応する
- 根拠: array_filter(is_string(...)) の narrowing が実挙動として効くことをテストで固定すると堅い。
- 対応内容: T3 に test 6 を追加。`capture_permissions_policy_routes = ['capture.manuals.show', 123, null]` の下で
  show は capture 値・非 recorder は baseline（非文字列要素は無視される）を検証。

## [Suggestion] ポリシー文字列に camera/microphone directive を含むことを検証 / RFC 準拠形式を config コメントに
- 判断: 一部対応
- 根拠: test 1 が Permissions-Policy を完全一致で検証しており camera=(self)/microphone=(self) の存在は担保済み。
- 対応内容: 追加テストは設けず、config コメントに「camera/microphone を (self) に緩める」意図は既に明記済み
  （T1 のコメント）。RFC 準拠形式の明記は既存 permissions_policy コメントの流儀を踏襲。

## [Suggestion] capture.manuals.show 以外へ広げない理由を config コメントに1行
- 判断: 対応済み
- 根拠: T1 の capture_permissions_policy_routes コメントに「document 単位に効く / 他へ広げると XSS 時に権限面積増大 /
  将来追加はレビュー対象」を既に記載済み。
