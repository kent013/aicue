# 対応マトリクス: impl-review Round 1

## [Critical] 全体 — 修正後コードの検証が未完了
- 判断: 対応する
- 根拠: 指摘の通り、プロンプト作成時点では `SourceDocumentUploadTest.php` の修正
  (png→gif fixture 置換) 後の `composer test` フルスイート結果と `pnpm test` の結果を
  提示できていなかった。実際には両方とも green まで確認済みだったが、それを
  Codex に伝えていなかった。
- 対応内容: Round 2 のプロンプトで以下を明記する。
  - `composer test`: 6422 tests / 6420 passed / 0 failed / 2 skipped / 5 risky
  - `pnpm test`: 173 files / 2366 tests passed
  - `pnpm test:packages`: 10 files / 106 tests passed
  - `composer phpstan`: level 10, No errors
  - `vendor/bin/pint --test`: passed
  - `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` /
    `pnpm build:packages`: すべて clean
  - Suggestion 2 件対応後の再実行結果も併せて報告する。

## [Suggestion] `SourceDocumentUploadOcrTest.php` — `imageSourceDocumentsEnabled` の `missing()` 検査
- 判断: 対応する
- 根拠: 完全撤去の回帰検出として妥当な指摘。現状のテストは新しい固定値を確認するだけで、
  旧 prop が復活しても検出できない。
- 対応内容: 「公開面の一貫性」テストの show/create 両方の `assertInertia` チェーンへ
  `->missing('imageSourceDocumentsEnabled')` を追加した。

## [Suggestion] `SourceDocumentUploadOcrTest.php` — 「jpg/png アップロードが成功する」のテスト名と実体の不一致
- 判断: 対応する
- 根拠: 指摘の通り、テスト名は jpg/png 両方の成功を主張しているが、実体は jpg のみを
  HTTP 層で検証していた (png は Unit テスト側の許可集合検査でのみ間接的にカバー)。
  名前が主張する範囲と検査範囲を一致させる。
- 対応内容: テストを 1 手順書 1 枚制約を避けるため 2 つの `VideoManual` に分け、
  jpg・png それぞれの HTTP 経由アップロード成功と `mime` を明示的に検証するよう拡張した。
