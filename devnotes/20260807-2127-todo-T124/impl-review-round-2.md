**指摘**

[Warning] `resources/js/pages/Settings/Security.svelte` / `docs/architecture.md`  
precheck の新しい根拠は方向として妥当ですが、「enrollment 途中の画面状態 (QR / セットアップキー / 入力中コード) が失われる」という説明は、`enableTwoFactor()` の実際の位置と一致していません。

precheck 対象の POST は enrollment の開始操作であり、その直前に `resetEnrollmentAssets()` が走ります。この時点では通常、QR・セットアップキー・確認コードはまだ存在しません。素材取得後の鮮度切れは別途 `loadEnrollmentAssets()` の409処理が担当しています。

したがって、この precheck が直接守るのは「設定画面からの全画面離脱を避け、開始操作をモーダル成立後に再開すること」です。QR・入力中コードの保持まで保証すると、保証範囲を誇張します。次のような記述が実装と合います。

> precheck 無しでは開始 POST の409により確認画面へ全画面遷移する。precheck により設定画面から離脱せず、再認証成立後に enrollment 開始操作を再開できる。

なお、T125 後も precheck 自体を残す判断は妥当です。レーン分離によって旧来の共有 bucket 問題は消えていますが、全画面遷移を避けるUX上の役割と、不成立の POST による `two-factor-manage` 枠消費を避ける副次的効果は残っています。

**ファイル別判定**

- `AGENTS.md`: 問題なし。T125 後の失効した throttle 根拠を含まず、保証範囲も明示されています。
- `app/Enums/Security/TwoFactorStepUpExemption.php`: 問題なし。
- `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php`: 問題なし。passkey satisfier だけを許可し、管理経路は広げていません。
- `app/Providers/FortifyServiceProvider.php`: 問題なし。`enable force=true` のロックアウト経路と秘密 GET の配線が固定されています。
- `docs/architecture.md`: [Warning] 上記の画面状態保持に関する説明を狭める必要があります。T125 後の bucket と middleware 順序の説明自体は整合しています。
- `resources/js/lib/recent-auth.ts`: 問題なし。409とコードの両方を検査し、別の409を誤食しません。
- `resources/js/pages/Settings/Security.svelte`: [Warning] 実装は問題ありませんが、`enableTwoFactor()` の docblock が保持できる状態を誇張しています。
- `tests/Architecture/RecentAuthRouteTest.php`: 問題なし。
- `tests/Architecture/TwoFactorStepUpInventoryTest.php`: 問題なし。exact-fit、未知 route、stale/dead exemption、cap、non-exemptible が相互補完しており、main取り込み後の mutation 再実測もあります。
- `tests/Feature/Auth/TwoFactorEnableStepUpTest.php`: 問題なし。中心的な `force=true` 回帰と負のコントロールを固定しています。
- `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php`: 問題なし。
- `tests/Feature/Organizations/TwoFactorEnforcementTest.php`: 問題なし。passkey-only の前提が実データで固定されました。
- `tests/Feature/Security/AuthThrottleCoverageTest.php`: 問題なし。fresh session の追加により秘密を返す正常経路で limiter を検査でき、空振りを避けています。
- `tests/Support/Security/RecentAuthMiddleware.php`: 問題なし。
- `tests/js/lib/recent-auth.test.ts`: 問題なし。
- `tests/js/pages/SettingsSecurity.test.ts`: 問題なし。Round 1 の状態混在遷移が直接固定され、無限ループ・再開・負のコントロールも維持されています。
- devnotes / 全検証レーン: 提示された実測結果では確認済み。ブラウザ2レーンを含め全 green です。

CHANGES_REQUESTED