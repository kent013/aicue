**レビュー結果（T024）**

以下、提示差分のみを対象にレビューしました。結論は **APPROVED** です。

**`bootstrap/app.php`**
- **判定**: OK（設計一致）
- **Critical**
  - なし
- **Warning**
  - なし
- **Suggestion**
  - `authenticateSessions()` を framework 標準 API で有効化しており、設計の層1と一致。`append` 直書きでなく将来互換性の高い実装で妥当。

**`app/Actions/Fortify/UpdateUserPassword.php`**
- **判定**: OK（設計一致・正確）
- **Critical**
  - なし
- **Warning**
  - なし
- **Suggestion**
  - 実装順序 `save → logoutOtherDevices → best-effort削除` は正しい。`logoutOtherDevices` の事前条件（新ハッシュ保存済み）を満たしている。
  - `session.driver !== database` 早期return、`session()->isStarted()` ガード、`try/catch + report()` は設計どおりで、console/queue/非DBドライバ文脈にも安全側。
  - `Assert::nullOrString` / `Assert::string` で `config()` の mixed を narrowing しており、PHPStan L10 方針に適合（widen/ignore なし）。
  - `response()->json()` 直書きなし（voidアクションとして妥当）。

**`tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php`**
- **判定**: OK（網羅性・非トートロジー）
- **Critical**
  - なし
- **Warning**
  - なし
- **Suggestion**
  - (a) 他ユーザー行保持＋対象ユーザー他行削除を分離検証しており、削除条件の誤爆検知ができる。
  - (b) 再`actingAs`せず保護ルート到達を確認しており、「現在デバイス維持」を実際に検証できている。
  - (c) `withSession(password_hash_web=oldHash)` は層1の中核（ハッシュ不一致失効）を決定的に証明できる良い設計。
  - (d) 実recaller経路（`viaRemember`）を cookie で検証しており、(c)と別分岐の不変条件を固定できている。
  - (e) 非databaseドライバでのスキップ動作を担保しており、best-effort方針と整合。

**総評**
- 3層設計（層1 `AuthenticateSession` / 層2 `logoutOtherDevices` / 層3 best-effort削除）に忠実。
- セキュリティ観点で重大な穴（現デバイス誤失効、他デバイス失効漏れ、情報漏洩誘発）は見当たりません。
- テストは実装の結果を直接検証しており、自己充足的なトートロジーにはなっていません。

**全体判定: APPROVED**