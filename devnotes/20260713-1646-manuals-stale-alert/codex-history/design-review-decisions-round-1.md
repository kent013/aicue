# 対応マトリクス: design-review Round 1

## [Critical] 施策1: `hasDocumentValidationError` が弱く、将来の document 由来 422 (形式/容量) を誤破棄
- 判断: 対応する
- 根拠: 妥当。analyze endpoint の現状 422 は missing-doc のみだが、robust にすべき。
  backend に機械可読 code を足す案 (A) は Laravel ValidationException を独自ハンドリングする侵襲があり過剰。
  Codex 併記の案 (B)「`status===422 && hasDocument===false` を併用」を採用。
  missing-doc 422 は「手順書が存在しない」ときにのみ発生し、これは `hasDocument: false` と一致する
  (両者とも `sourceDocuments()->exists()` 由来)。分類時に現在の `hasDocument === false` を要求すれば、
  document を伴う将来の 422 (形式/容量: そもそも upload endpoint 側で発生) を missing_document に誤分類しない。
- 対応内容: `classifyStartError` の 422 分岐を
  `status === 422 && !hasDocument && hasDocumentValidationError(body)` に強化。backend 変更なし。

## [Warning] 施策1: plain `hadDocument` 変数の遷移検出が見落とされやすい
- 判断: 対応する (可読性向上 + テスト固定)
- 根拠: previous-value 追跡は $derived で表現できない (stateless) ため plain var 自体は正しいが、命名と
  テストで意図を固定する。
- 対応内容: 変数名を `previousHasDocument` に。遷移ケース (初回 true / false→true / true→true) を
  施策2 のテストで固定 (初回 true は「そもそもエラーが出ない」ため破棄対象なし、を別途明記)。

## [Warning] 施策1: 「missing_document だけ自動破棄、他は保持」の仕様がコードから読み取りづらい
- 判断: 対応する
- 対応内容: 述語関数 `isResolvedByDocumentUpload(kind)` を導入し effect で使用 (自己記述化)。

## [Suggestion] 施策1: 201 成功分岐で `startErrorKind = null` を明示
- 判断: 対応する
- 対応内容: `handleStartResponse` の 201 分岐に `startErrorKind = null` を追加。

## [Critical] 施策2 ケース2: 402 を hasDocument:false で作るのはドメイン乖離
- 判断: 対応する
- 根拠: 402 (残高不足) は hasDocument=true 文脈で発生する。テスト前提を実運用に合わせる。
- 対応内容: ケース2 は初期 props を `hasDocument: true` で開始し、`rerender` は `manualStatus` 等のみ変更して
  「missing_document 以外は消えない」を検証。強化後の分類 (`!hasDocument` 要求) とも整合する。

## [Warning] 施策2 ケース1: showPurchaseLink 等への非干渉が未固定
- 判断: 対応する
- 対応内容: ケース1 に「購入リンク非表示」assertion を追加。

## [Warning] 施策2: 非退行テストの意図 (start-error のみ破棄・failedJob は server-truth 維持) を名前/コメントに明記
- 判断: 対応する
- 対応内容: テスト名を「start-error のみ破棄、failedJob (server-truth) は維持」に。
