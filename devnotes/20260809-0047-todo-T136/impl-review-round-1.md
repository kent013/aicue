**ファイルごとの判定**
`app/Actions/Fortify/CreateNewUser.php` [OK]  
設計どおり `LegalConsent::version()` 経由へ置換され、既存の `forceFill` / transaction 構造を変えていません。

`app/Actions/Inquiry/CreateInquiryAction.php` [OK]  
旧 `Assert::stringNotEmpty` の責務が `LegalConsent::version()` に移っただけで、保存前 fail-fast と通知宛先の fail-fast 順序は維持されています。

`app/Services/Auth/SocialAccountService.php` [OK]  
SSO 登録経路も正準形へ揃っています。`email_verified_at` 等の周辺挙動に変更はありません。

`app/Support/Legal/LegalConsent.php` [OK]  
設計どおり SSOT になっています。`config()->string()` + `Assert::stringNotEmpty()` により、非文字列 / null / 空文字を落とすため、旧 `CreateInquiryAction` より弱くなっていません。

`database/factories/InquiryFactory.php` [OK]  
literal `draft-1` を排除し、fixture も同じ解決点へ統一されています。config 依存化による副作用は、提示されたテスト結果と設計上の `.env.testing` / config 既定値から問題なしと判断します。

`tests/Architecture/LegalConsentVersionSingleSourceTest.php` [OK]  
4 語彙限定、G3 exact-fit、空振り green 対策、billing 非巻き込みの負の制御まで入っており、設計と一致しています。既知の限界を除く「静的に literal で版を取る道」は実用上かなり塞げています。

`tests/Unit/Support/Legal/LegalConsentTest.php` [OK]  
正常系、空文字、未設定の 3 点があり、fail-fast の中心挙動を直接固定できています。Pest の global namespace use 制約にも抵触していません。

**指摘一覧**
[Critical] なし

[Warning] なし

[Suggestion] なし

**全体判定**
実装は詳細設計と一致しています。DTO / JsonResource は HTTP 応答を持たないため該当なし、フロント差分もないため DESIGN.md / Atomic Design 観点も該当なしです。提示された `composer phpstan`、Pest、pnpm 系検証、mutation 実測も十分です。

APPROVED