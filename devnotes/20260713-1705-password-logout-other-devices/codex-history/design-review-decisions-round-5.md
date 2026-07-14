# 対応マトリクス: design-review Round 5

## [Critical] Cookie 処理の組み合わせが逆（施策3 (d)）
- 判断: **対応（framework source で確定）**
- 根拠: `MakesHttpRequests::prepareCookiesForRequest` を確認。`withCookie`（defaultCookies）は
  テスト側で `encrypt(CookieValuePrefix::create($key, key).$value)` として**再暗号化**して送り、アプリの
  `EncryptCookies` が復号する。一方 `withUnencryptedCookie`（unencryptedCookies）は as-is 送信で、
  平文を送るとアプリが復号に失敗する。Round 4 の「復号済み平文 + withUnencryptedCookie」は不整合だった。
- 対応内容: Codex 提示の **option A**（`getCookie($recallerName)` の復号済み平文 + `withCookie`）に統一。
  テスト側再暗号化 → アプリ復号 で recaller が正しく guard に渡る。ソース根拠をコメントに明記。

## Round 6（確認）: 施策3 (d) APPROVE / 全体 APPROVED
- Codex が option A の正しさと guard/session 分離を追認し、全体判定 APPROVED。
