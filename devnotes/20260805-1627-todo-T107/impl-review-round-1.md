レビュー仮説: 12 施策の実装自体は概ね設計に沿っているが、詳細設計の「テストデータは必ず Factory」制約と、登録フローの例外時 loading 復旧に抜けがないかを重点確認した。提示されたテスト結果は信頼するが、ツール制限に従いこちらでは再実行していない。

**指摘**

`tests/Feature/Auth/RecentAuthStatusContractTest.php`

[Critical] テストデータ生成が詳細設計の「テストデータは必ずFactoryで生成（Model::create() 手組み禁止）」に違反しています。SocialAccount を手組みして `save()` しているため、Factory 経由に直してください。

```php
$account = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-contract']);
$account->user()->associate($user);
$account->save();
```

この設計書は Factory 生成を明示しており、今回の新規 contract test がその規約を外しています。`SocialAccount::factory()->for($user)->create([...])` 相当で固定すべきです。

`resources/js/components/features/auth/PasskeySection.svelte`

[Warning] `startCeremonyAndPost()` は `createPasskeyCredential()` が例外を投げた場合に `registering` が `true` のまま残ります。施策 11 は loading 固定の解消が目的なので、例外経路も同じ不変条件に含めるべきです。

```ts
async function startCeremonyAndPost(capturedName: string): Promise<void> {
    registering = true;
    const outcome = await createPasskeyCredential();
```

`outcome.status === "failed"` は扱えていますが、throw 経路は `operationError` も `registering = false` も通りません。`try/catch` で Alert 表示と解除を入れるのが安全です。

**ファイル別判定**

`DESIGN.md`: OK。Alert / FormError / RecentAuthModal / RecentAuthRecoveryNotice の規約追記は施策 9・12 と一致。

`app/Actions/Fortify/UpdateUserPassword.php`: OK。副作用を `PasswordCredentialService` に寄せる変更は施策 6 と一致。

`app/DataTransferObjects/Auth/LoginMethodRequiredDto.php`: OK。`settingsUrl` 削除は施策 8 と一致。

`app/Enums/SecurityEventType.php`: OK。`PasswordSet` 追加と label 追加は施策 6 と一致。

`app/Http/Controllers/Settings/PasswordSetupController.php`: OK。薄い Controller、`back()->with(...)`、recent-auth 前提の設計に一致。

`app/Http/Controllers/Settings/ProfileController.php`: OK。`hasPassword` prop 追加は施策 7 と一致。

`app/Http/Middleware/EnsureLoginMethodRemains.php`: OK。phantom `settingsUrl` 契約を削除し、Resource 経由を維持。

`app/Http/Middleware/RequireRecentAuth.php`: OK。Inertia mutation の 409 で `url.intended` / `recent_auth.dropped_mutation` を保存し、純 XHR は汚さない分岐になっている。

`app/Http/Requests/Settings/SetPasswordRequest.php`: OK。Protected key guard と Password policy 経由。JSON 直書きなし。

`app/Http/Resources/Auth/LoginMethodRequiredResource.php`: OK。DTO 縮小に合わせて array shape も縮小。

`app/Services/Auth/PasswordCredentialService.php`: OK。`lockForUpdate()` による初回設定 TOCTOU 防止、commit 後副作用、他 device logout の集約は設計と一致。意図的逸脱の in-memory hash 反映も妥当。

`docs/auth-security-mechanisms.md`: OK。`settingsUrl` 削除と recovery notice 集約の契約更新あり。

`docs/supported-browsers.md`: OK。logout inventory の正本が molecule に更新されている。

`resources/js/app.ts`: OK。recent-auth 409 handler の登録と HMR dispose あり。

`resources/js/components/features/auth/PasskeySection.svelte`: Warning あり。施策 8〜11 の主要実装は入っているが、throw 時の loading 復旧が不足。

`resources/js/components/molecules/RecentAuthRecoveryNotice.svelte`: OK。molecule 配置、atoms のみ composition、`router.post("/logout")`、`/forgot-password` 直リンク排除は設計と一致。

`resources/js/components/organisms/RecentAuthModal.svelte`: OK。`status` 一本化、`status === null`、Alert 分離、RecoveryNotice 集約は施策 1・5・9 と一致。

`resources/js/lib/recent-auth.ts`: OK。strict parse と 409 handler は設計意図と一致。`invalid` ではなく `httpException` を使う逸脱は、提示された Inertia core 3.3.1 の前提に対して妥当。

`resources/js/pages/*`: OK。6 call-site の `status={recentAuthStatus}` 配線、Settings 初回設定分岐、ConfirmRecentAuth の molecule 利用はいずれも設計と一致。

`routes/web.php`: OK。`settings.password.store` は auth/verified 配下、recent-auth + throttle 付きで配置されている。

`tests/Architecture/*`: OK。recent-auth route gate と controller exemption が更新されている。

`tests/Feature/Auth/LoginMethodRetentionTest.php`: OK。`settingsUrl` phantom contract の再追加防止と CTA 根拠テストあり。

`tests/Feature/Auth/RecentAuthStatusContractTest.php`: Critical あり。contract 内容は良いが、SocialAccount の手組み生成が規約違反。

`tests/Feature/Auth/RecentAuthTest.php`: OK。409 intended / dropped mutation / pure XHR 非汚染 / open redirect 防止が入っている。

`tests/Feature/Settings/PasswordSetupTest.php`: OK。step-up 必須、fail-closed、監査、session 削除、prop 更新をカバー。

`tests/js/**`: OK。call-site inventory、logout inventory、strict parse、RecoveryNotice、RecentAuthModal、Settings/Passkey 回帰が追加されている。

**全体判定: CHANGES_REQUESTED**

実装方向は設計に沿っていますが、新規 Feature test の Factory 規約違反は詳細設計の明示制約に反するため、その修正まではマージ不可です。PasskeySection の例外時 loading 復旧も同時に直すのが妥当です。