# 対応マトリクス: design-review Round 2

## [Warning] 施策1: 応答と Inertia 更新の順序逆転で stale alert が残る (race)
- 判断: 対応する (Codex 案を全面採用。設計がより単純かつ正しくなる)
- 根拠: 妥当かつ重要。解析要求中に SOP アップロードが完了して `hasDocument=true` になり、その後に
  先行 422 が遅延到達すると、(a) `classifyStartError` が現在値 `hasDocument=true` を読み "generic" と誤分類し、
  (b) false→true 遷移は消費済みで edge-triggered effect も発火しない → stale が残る。
- 対応内容:
  1. **分類を開始時スナップショットで固定**: `startAnalyze` 冒頭で `const hadDocumentAtStart = hasDocument;`
     を取り、`handleStartResponse(res, hadDocumentAtStart)` → `classifyStartError(status, body, hadDocumentAtStart)`
     に渡す。422 分岐は `!hadDocumentAtStart` で判定 (要求時に手順書が無かったか)。
  2. **effect を level-triggered 化**: `previousHasDocument` を廃止し、
     `if (hasDocument && isResolvedByDocumentUpload(startErrorKind)) { クリア }` に変更。
     `hasDocument===true && missing_document` は常に矛盾状態なので、両順序 (422→upload / upload→遅延422) を
     一様に破棄できる。遷移追跡が不要になり実装も単純化。

## [Warning] 施策2: 競合順序の回帰テストが必要
- 判断: 対応する
- 対応内容: deferred Promise で fetch を保留し、解析開始 → `hasDocument=true` へ rerender → その後 422 を
  resolve → alert が残らないことを固定するケース (ケース4) を追加。
