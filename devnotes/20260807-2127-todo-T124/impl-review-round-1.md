**指摘**

`resources/js/pages/Settings/Security.svelte`

[Warning] `retryEnrollmentAssets()` が `enrollmentAssetsFailed` をリセットしていません。  
一度 500 などで `enrollmentAssetsFailed = true` になった後、再試行で QR/secret が 409 を返すと、`loadEnrollmentAssets()` の 409 分岐は `enrollmentAssetsFailed` を消さないため、「再認証が必要」と「設定情報を取得できませんでした」の状態が混ざります。これは設計の「409 を取得失敗に畳まない」に反する経路です。

修正は `loadEnrollmentAssets()` 開始時、または 409 分岐前に `enrollmentAssetsFailed = false` を明示するのがよいです。併せて `500 -> 再試行 -> 409` で `enrollment-assets-error` が出ないテストを足すと固定できます。

`tests/js/pages/SettingsSecurity.test.ts`

[Warning] 上記の遷移テストがありません。現在の 409 系テストは初回状態からの 409 なので、既存の取得失敗状態を引きずるケースを検出できません。

**ファイル別判定**

`AGENTS.md`  
[OK] 設計の保証範囲を誇張しない記述、non-exemptible 6 本、satisfier 到達性が記録されています。

`app/Enums/Security/TwoFactorStepUpExemption.php`  
[OK] enum は理由分類として妥当です。route 名の写しにしていない点も設計通りです。

`app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php`  
[OK] `passkey.confirm*` だけを allowlist に追加しており、管理系 passkey route を広げていません。

`app/Providers/FortifyServiceProvider.php`  
[OK] `two-factor.qr-code` / `two-factor.secret-key` / `two-factor.enable` の追加は設計意図と一致します。`enable` を差し替え経路として扱う判断もテストで固定されています。

`docs/architecture.md`  
[OK] 契約、限界、satisfier、クライアント側ループ防止が明確に書かれています。

`resources/js/lib/recent-auth.ts`  
[OK] `409 + code` で判定しており、`two_factor_required` の誤食を避けています。

`resources/js/pages/Settings/Security.svelte`  
[Warning] 上記の stale state 混在があります。それ以外の `onDelegated` / `enrollmentStepUpRetried` / 世代管理の方向性は妥当です。

`tests/Architecture/RecentAuthRouteTest.php`  
[OK] 共有述語への委譲と allowlist 追加は妥当です。

`tests/Architecture/TwoFactorStepUpInventoryTest.php`  
[OK] deny-by-default、exact-fit 母集団、non-exemptible 固定、stale/dead exemption 検出が揃っています。

`tests/Feature/Auth/TwoFactorEnableStepUpTest.php`  
[OK] `force=true` による seed/recovery code 差し替えと `confirmed_at` 不変を正しく固定しています。

`tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php`  
[OK] stale/fresh の負のコントロールがあり、秘密が返らないことも確認されています。

`tests/Feature/Organizations/TwoFactorEnforcementTest.php`  
[Suggestion] “passkey-only” は `User` factory が password を持たないか未確認です。到達性テストとしては十分ですが、名前どおり固定するなら password/social 不在を明示するとより強いです。

`tests/Support/Security/RecentAuthMiddleware.php`  
[OK] 判定点の単一化は妥当です。同一 alias 重複を保証範囲に含めない説明も適切です。

`tests/js/lib/recent-auth.test.ts`  
[OK] 409 判定の正負コントロールが揃っています。

未確認: `composer test:browser` の最終結果、devnotes 実測ログファイルの実在。

CHANGES_REQUESTED