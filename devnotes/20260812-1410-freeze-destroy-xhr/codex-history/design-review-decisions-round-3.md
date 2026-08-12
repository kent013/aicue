# 対応マトリクス: design-review Round 3

判定 REQUEST_CHANGES。Critical 1 / Warning 1。**両方対応**(反論なし。いずれも文書の不整合)。

## [Critical] 呼び出し元対応表が新しい DTO 契約と矛盾 (`new` 直呼び)

- 判断: **対応する**
- 根拠: constructor を private にして named constructor を用意した以上、表の
  `new AccountDeletionAuditContext(...)` は**実装不能**である。書き換え漏れだった。
- 対応内容: `AccountDeletionAuditContext::http($request->route()?->getName(), $request->method())` に修正。

## [Warning] 契約数が誤っている (8 件ではなく 9 件)

- 判断: **対応する**
- 対応内容: 施策一覧を「契約 **9 件** (1〜6 + 7a + 7b + 8)」に修正した。
