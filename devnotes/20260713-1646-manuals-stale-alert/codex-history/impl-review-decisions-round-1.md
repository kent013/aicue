# 対応マトリクス: impl-review Round 1

Codex (gpt-5.3-codex, reasoning=high) 全体判定: **APPROVED**（Critical/Warning ともになし）。

## [Suggestion] `hasDocumentValidationError()` を `errors.document` が string 単体で返る将来 API 変更にも耐えるガードにする
- 判断: 見送る
- 根拠: 現行 backend (`AnalysisJobService`) は Laravel の `ValidationException::withMessages(['document' => [...]])` で常に配列を返す。現仕様前提では不要であり、Codex 自身も「現仕様前提なら不要」と付記。オーバーエンジニアリング禁止（AGENTS.md 思考原則2）に該当するため追加しない。

## [Suggestion] `422 + hadDocumentAtStart=true + errors.document あり` が generic 扱いで自動破棄されないことを明示するテストを追加
- 判断: 対応する
- 根拠: 分類仕様（`missing_document` は要求時に手順書なし限定）の意図を回帰テストで固定でき、低コストで価値が高い。`!hadDocumentAtStart` ガードの意図をドキュメント化できる。
- 対応内容: `AnalysisPanel.test.ts` に「422 でも解析要求時に手順書があれば (hadDocumentAtStart=true) generic 扱いで破棄されない」ケースを追加。hasDocument:true 開始で 422 を返し、別 props 更新後も alert が残ることを検証。vitest 481 passed / lint / typecheck green を確認。
