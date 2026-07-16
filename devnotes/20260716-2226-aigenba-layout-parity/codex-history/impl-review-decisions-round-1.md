# 対応マトリクス: impl-review Round 1（REQUEST_CHANGES）

## [Critical] page-shell-structure のコメント除去が文字列内 // を壊し得る
- 判断: 対応する
- 対応: `//` 行コメント除去を **行頭コメント限定** (`/^\s*\/\/[^\n]*$/gm`) に変更。文字列内 (URL "https://" 等)
  や行内の `//` を壊さない。arch テスト再実行 green 維持。

## [Critical] Projects/Show カテゴリ導線 URL が route helper 直書き (耐変更性)
- 判断: 反論(現行標準に一致・変更しない)
- 根拠(確認済): AI-CUE は **Ziggy 未導入**(grep 0 件)で、FE の URL は文字列パス直書きが既存標準
  (例: Projects/Index の `href={\`/projects/${project.id}\`}`、OrganizationSwitcher の
  `router.post(\`/organizations/${id}/switch\`)` 等。コメントにも「Ziggy 未導入のため文字列パス直書きが既存標準」)。
  `route()` は BE 専用。よって categories 導線の文字列 href は AI-CUE の確立標準に一致し、独自逸脱ではない。

## [Suggestion] PageContainer コメントの名称ドリフト (page-content-usage)
- 判断: 対応する
- 対応: PageContainer の docblock を `page-content-usage` → `page-shell-structure` に修正。

## [Warning] PageHeaderSection の const $derived / [Suggestion] Breadcrumb key / Admin テスト
- 判断: 変更不要(現行整合)
- 根拠: `const x = $derived(...)` は AI-CUE 既存流儀(AppLayout 等でも使用)。Breadcrumb key の衝突は実害小。
  Admin/Users の二次メニュー不在テスト(admin-nav-users/categories null)は既に追加済み。

## APPROVE 済み (AppLayout/PageContent/PageHeader/BE/Feature 等)
- 変更なし。
