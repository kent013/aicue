# 概念設計: twofa-recent-auth

## 背景・課題

bug-hunt finding **F-H3 (High, authz_bypass)**。

2 要素認証の管理操作のうち、**第二要素の無効化 (`two-factor.disable`, `DELETE /user/two-factor-authentication`)** が step-up 再認証 (recent-auth) を一切要求せず、通常セッション認証のみで実行できる。セッションハイジャック時、攻撃者はパスワードを知らないまま被害者の 2FA を無効化でき、以後アカウント乗っ取りの障壁が消える。

### 根本原因

- 2FA 管理操作は Fortify 標準ルート (`vendor/laravel/fortify/routes/routes.php`) で定義される。
- 本アプリは Fortify 標準の `password.confirm` を撤去 (`config/fortify.php` の `twoFactorAuthentication(['confirmPassword' => false])`) し、step-up を generic recent-auth (15 分窓・password または再SSO) へ統一している。
- Fortify 登録ルートへ recent-auth を後付けする配線 `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` は存在するが、その対象 `RECENT_AUTH_ROUTE_NAMES` に含まれるのは **`two-factor.recovery-codes` / `two-factor.regenerate-recovery-codes` のみ**で、`two-factor.disable` が抜けている。

### finding の記述との差分 (重要)

finding F-H3 は「disable と regenerate-recovery-codes が step-up を要求しない」と記述するが、**現行コードでは `two-factor.regenerate-recovery-codes` (および `two-factor.recovery-codes`) は既に recent-auth 配線済み** (`RECENT_AUTH_ROUTE_NAMES` 登録済み・`RecentAuthRouteTest` / `TwoFactorRecoveryCodesStepUpTest` で CI 固定)。したがって finding のうち regenerate 部分は既に解消済みで、**残存する真の bypass は `two-factor.disable` の 1 経路**である。本設計はこの残存ギャップを塞ぐ。

## 改善アイデア

`two-factor.disable` を既存の recent-auth 後付け配線に載せ、無効化を step-up 必須にする。実装は **既存の確立パターンをそのまま踏襲**する（新機構は作らない）:

1. `FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` に `'two-factor.disable'` を追加 → `attachRecentAuthToSensitiveRoutes()` の booted callback が recent-auth middleware を後付けする。
2. `RecentAuthRouteTest` の allowlist (`recentAuthRequiredRouteNames()`) に `'two-factor.disable'` を追加 → 付与漏れを CI で固定。
3. Feature テストで stale 遮断 / fresh 通過を HTTP 経由検証（`TwoFactorRecoveryCodesStepUpTest` と同形式）。
4. フロント `Settings/Security.svelte` の `disableTwoFactor()` を既存の `guardWithRecentAuth(action)` でラップし、stale 時に再認証モーダルを挟んでから無効化を再開する（UX を regenerate と揃える）。

### disable の resume 契約（regenerate と同一）

`guardWithRecentAuth(action)` は `action`（= `router.delete("/user/two-factor-authentication", {...})` を実行する closure）を受け取り、`withRecentAuth` で precheck する:

- **fresh**: `action` を即実行 → disable 成功。
- **stale**: `pendingAction = action` を退避し再認証モーダルを開く。ユーザーが password 再入力 / 再SSO を完了すると `resumePendingAction()` が退避した closure を再呼び出しし、disable を再開する。resume で再送されるのは**冪等な DELETE のみ**（二重実行しても結果は同じ）なので安全。
- **status 取得失敗（delegated）**: `onFresh` にフォールバックして `action` を実行 → server の recent-auth middleware が最終ゲートとして stale を 409 で弾く。

この契約は現行の regenerate（`regenerateRecoveryCodes`）と完全同型であり、新しい resume 機構は追加しない。**最終ゲートは常に server の `recent-auth` middleware**であり、フロント precheck は UX 補助にすぎない。

## 期待効果

- **セキュリティ不変条件の回復**: password 再入力または再SSO を伴わない**単独セッション侵害だけでは 2FA を無効化できなくなる**（step-up を強制）。姉妹操作 `organizations.members.two-factor.reset`（他人の 2FA 解除）と同一基準に揃い、「自分の 2FA 解除だけ無防備」という非対称を解消。
- **使命への貢献**: self-disable が許可される**非 enforced 組織**において、セッション侵害から認証境界と現場作業者の標準化マニュアル資産を守る。2FA 必須組織については既存の 422 拒否（`BlockTwoFactorDisableForEnforcedOrganizations`、recent-auth より先に走る）を**維持し後退させない**（本変更による改善ではなく、既存防御と衝突しないことを保証する）。
- **UX 一貫性**: disable も regenerate/API キー失効と同じ再認証モーダル導線になり、SSO-only ユーザーも fail-closed で再SSO に誘導され詰まない。

## 実装方針（概要）

| 層 | 変更 | 方式 |
|----|------|------|
| ルート配線 | `two-factor.disable` に recent-auth 付与 | `FortifyServiceProvider` の既存 const 配列に 1 要素追加（新規コードなし） |
| Architecture テスト | allowlist へ登録 | `RecentAuthRouteTest` の関数に 1 行追加 |
| Feature テスト | stale 遮断 / fresh 通過 | `TwoFactorRecoveryCodesStepUpTest` に倣い新規テスト |
| フロント | disable 前段 precheck | 既存 `guardWithRecentAuth()` で `router.delete` をラップ |
| ドキュメント | config TODO の追従 | `config/fortify.php` の TODO コメントから `disable` を「対応済み」へ更新 |

DTO/JsonResource は既存の `RecentAuthRequiredDto` + `RecentAuthRequiredResource`（middleware が 409 応答に使用）をそのまま利用。新規 DTO/Resource は不要。

## 制約・前提

- **login は recent_auth を stamp する** (`StampRecentAuthOnLogin` listener)。ログイン直後は 15 分窓内のため、正当ユーザーの通常フロー（ログイン → 設定画面で 2FA 無効化）で余計な再認証は発生しない。stale（ログイン後 15 分超）でのみ再認証を要求する。
- **2FA 必須組織の self-disable は本変更の影響を受けない**。`BlockTwoFactorDisableForEnforcedOrganizations`（`bootstrap/app.php` の web group `append`、global middleware）が enforced org の準拠ユーザーの `two-factor.disable` を **422 で拒否**する。web group middleware は route 付与の `recent-auth`（route-level）より**先**に走るため、enforced org のユーザーは recent-auth に到達する前に 422 で弾かれる（復旧は管理者の `organizations.members.two-factor.reset` 経由のみ）。したがって本変更は **非 enforced org（self-disable が許可される）ユーザーにのみ step-up を課す純粋な追加**であり、self-disable 成功後の遷移は既存 `TwoFactorDisabledResponse`（web: `back()->with('success')` / XHR: 200）のまま。
- middleware `RequireRecentAuth` は Inertia mutation（`X-Inertia` + 非 GET）を 409 + `{code, message, redirect}` で返す。`router.delete` は Inertia mutation のため、precheck を通さず stale で叩くと 409 を受ける。既存 `guardWithRecentAuth()` で precheck して UX を成立させる（最終ゲートは常に server middleware）。
- route:cache 下でも `attachRecentAuthToSensitiveRoutes()` は同一 Route instance に memoize 反映されるため有効（既存 docblock 参照）。
- PHPStan L10 / Pest / RefreshDatabase グローバル適用に準拠。

## スコープ外

- **`two-factor.enable` / `two-factor.confirm`**: 第二要素の *追加* はセキュリティのダウングレードではなく（2FA を付与する行為でアカウントが弱くなることはない）、bypass ではない。加えて 2FA 必須組織のオンボーディング（enrollment）動線と絡むため、`config/fortify.php` の TODO(template) が明示的に「衝突しない設計を決めてから固める」と保留している。本設計では触らない（finding F-H3 の対象外でもある）。
- **`two-factor.qr-code` / `two-factor.secret-key`**: TOTP secret を露出するが、意味を持つのは enrollment（confirm 前）フェーズであり、確立済み第二要素の bypass ではない。enable/confirm の enrollment 再設計と一体で扱うべきで、本設計では触らない。
- recent-auth の窓幅・method policy・モーダル UI 自体の変更。既存機構をそのまま使う。
- 他の Fortify エンドポイント（passkey 等）の step-up 見直し。
