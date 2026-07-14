# 対応マトリクス: impl-review Round 1

Codex 判定: **APPROVED**（Critical 無し）。以下は非ブロッキングの Warning/Suggestion。

## [Warning] AdminUsers.test.ts の nameColumn 取得が parentElement 依存で DOM 構造にやや脆い
- 判断: 見送る
- 根拠: 現状でも S1 不変条件（`min-w-0 sm:min-w-40` の床）は十分固定できているとレビュアー自身が明言。
  対象 DOM（`getByText(email).parentElement`）は本ページで安定しており、data-testid 新設は
  「今必要なものだけ作る」（思考原則 2）に照らし過剰。既存テストの既定パターンとも整合。
- 対応内容: 変更なし。

## [Suggestion] SettingsIndex.test.ts のテスト名に「error 存在時のみ aria-describedby を検証」の明記
- 判断: 見送る（既にコメントで担保済み）
- 根拠: 当該ケース内に「FormField は error があるときだけ aria-describedby を生成するため、
  透過を検証するには両フィールドにエラーを載せた状態で描画する」というコメントを既に付与済みで、
  将来の誤解（常時付与と誤認）は防げている。テスト名変更は表層的差分に留まる。
- 対応内容: 変更なし。

## [Suggestion] 834px/768px の viewport 視覚回帰を将来 E2E で追加
- 判断: 見送る（今回スコープ外・レビュアーも「必須ではない」と明記）
- 根拠: jsdom はレイアウト非計算のためクラス不変条件をプロキシとする方針は設計で合意済み。
  E2E 視覚回帰基盤の新設は本 Low 改善のスコープを超える。
- 対応内容: 変更なし。実挙動は設計のとおり手動 viewport 確認で補完する方針を維持。
