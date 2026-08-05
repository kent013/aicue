仮説は「サーバ上の satisfier 可否と、現在端末で実行可能な satisfier 可否の乖離が残っていないか」。成功条件は、passkey-only ユーザーが対応・非対応ブラウザの双方で説明可能な回復導線を持つことです。

### ファイル別判定

`ConfirmRecentAuthController.php` / `RecentAuthStatusDto.php` / `RecentAuthStatusResource.php`

判定: 問題なし。

`passkeyAvailable` の feature gate、TOTP とログイン可否を分離した点、`canSatisfy` への算入は設計意図どおりです。キルスイッチにも連動しています。

`RecentAuthModal.svelte` / `ConfirmRecentAuth.svelte`

[Critical] passkey-only ユーザーがWebAuthn非対応ブラウザで引き続き行き止まりになります。

サーバは `passkeyAvailable=true` により `canSatisfy=true` を返しますが、両画面とも次の条件でボタンを隠します。

```svelte
{#if passkeyAvailable && passkeySupported}
```

password・SSOがない場合、パスキーボタンは表示されず、`canSatisfy=false` 用の回復案内も表示されません。つまり「アカウントには手段があるが、この端末では実行不能」という状態が未表現です。

`passkeyAvailable && !passkeySupported` の警告と回復導線を追加し、この組み合わせを両画面でテストしてください。`canSatisfy` はアカウント側能力のままで構いません。

インラインの fetch+204 と全画面の Inertia POST+intended の分離自体は妥当です。

`passkeys.ts`

判定: 問題なし。

ceremonyのみの `confirmPasskeyCredential()` と送信まで担う `confirmWithPasskey()` の分離は、transportの違いを明確に表現しています。

`PasskeySection.svelte` / `SettingsSecurityPasskey.test.ts`

判定: 問題なし。

登録payloadの `{ name, credential }` はvendor契約と一致し、クライアント側テストでも固定されています。

`PasskeyRouteAccessTest.php`

[Warning] nested payloadの「rulesを通過した」テストが空振りし得ます。

```php
expect($response->json('errors.credential.0'))
    ->not->toBe('The credential field is required.');
```

`errors.credential.0` が存在しない場合も通るため、validation段を通過した証明になりません。必須フィールドについて `assertJsonMissingValidationErrors()` を使うか、ceremony検証段の既知エラーを明示的に固定してください。

`PasswordUpdateSessionInvalidationTest.php`

判定: 問題なし。

成功時の監査記録と検証失敗時の非記録が両方向で固定され、Round 1のCriticalは解消しています。

`PasskeyRecentAuthInvalidationTest.php` / `RecentAuthMethodStampingTest.php`

判定: 受容可能。

成立するattestation/assertionなしでは実HTTP完走が難しいため、vendor event契約、実削除経路、login失敗境界、`allowsLogin()`を組み合わせる方針は妥当です。残余リスクも文書化されています。

`docs/*`

判定: 問題なし。

モデル、Factory、運用上の鍵ローテーション、ブラウザ保証範囲、テンプレート逸脱まで記録されています。

### 全体判定

**CHANGES_REQUESTED**

Round 1の3件のCriticalは解消されています。ただし、今回追加した `canSatisfy=true` とクライアントのfeature detectionの組み合わせにより、WebAuthn非対応端末で新しい行き止まりが残っています。ここを両方のrecent-auth画面で処理すれば、承認可能な状態です。