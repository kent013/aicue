# Round 3: Round 2 指摘への対応

# 対応マトリクス: impl-review Round 2

## [Critical] passkey-only ユーザーが WebAuthn 非対応ブラウザで行き止まりになる

- 判断: **対応する**
- 根拠: 指摘のとおり。`canSatisfy` は **サーバ判定 = アカウントに手段があるか**であり、
  WebAuthn の feature detection は**クライアントにしか無い**。両者を突き合わせていなかったため、
  「password 欄も SSO ボタンも passkey ボタンも出ないのに `canSatisfy=true` なので
  回復案内も出ない」という**無言の行き止まり**が残っていた。
  これは今回 `canSatisfy` に `passkeyAvailable` を算入したことで**新たに作り込んだ**穴であり、
  Critical 相当という判断に同意する。
- 対応内容:
  - 両 UI に `executableHere = passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported)`
    を導出 (`$derived`) し、`canSatisfy=true && !executableHere` の分岐を新設
  - `RecentAuthModal.svelte`: `recent-auth-unsupported-here` に理由
    (「このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
      パスキーを登録した端末・ブラウザで開き直すと再認証できます」) を表示
  - `Auth/ConfirmRecentAuth.svelte`: `confirm-unsupported-here` に同じ理由 +
    **ログアウト導線** (guest としてパスワード再設定する既存の回復手順) を提示
    (全画面のほうは離脱できないと本当に詰むため、行動可能な CTA を必ず置く)
  - `canSatisfy` の意味は**アカウント側能力のまま**にした (指摘どおり)。
    サーバに端末能力を持ち込むと、リクエストごとに変わる値を session 判定に混ぜることになる
  - テスト:
    - `tests/js/pages/ConfirmRecentAuthPasskey.test.ts` に 3 本
      (非対応 → 理由 + ログアウト導線 / 対応 → 理由を出さずパスキー導線 /
       password があれば非対応でも理由を出さない)
    - `tests/js/pages/SettingsSecurityPasskey.test.ts` に 2 本 (モーダル側の同等ケース)
  - `docs/auth-security-mechanisms.md` §1 に「`canSatisfy` は端末能力ではない」旨と
    両 UI の導出式を明記

## [Warning] nested payload テストが空振りしうる (`not->toBe(...)` はキー不在でも通る)

- 判断: **対応する**
- 根拠: 指摘のとおり。`errors.credential.0` が存在しない場合も通ってしまい、
  「rules 段を通過した」証明にならない (本レポジトリが多用している空振り防止の作法にも反する)。
- 対応内容:
  - `assertJsonPath('errors.credential.0', 'Invalid credential format.')` で
    **ceremony デシリアライズ段の既知エラーを完全一致で固定** (実測で確認)
  - あわせて `assertJsonMissingValidationErrors(['name', 'credential.id', 'credential.rawId',
    'credential.type', 'credential.response'])` を追加し、rules 段を通過したことを直接表明

## [受容] 登録の実 HTTP 経路 / allowsLogin deny の実 HTTP 経路

- Round 2 で「受容可能」の判定を得たため方針を維持する。


---

## 修正差分 (Round 2 → Round 3)

```diff
diff --git a/docs/auth-security-mechanisms.md b/docs/auth-security-mechanisms.md
index 5efae24..01427e4 100644
--- a/docs/auth-security-mechanisms.md
+++ b/docs/auth-security-mechanisms.md
@@ -4,7 +4,7 @@ # 認証・セキュリティ横断機構
 
 ## 概要
 
-テンプレート共通のセキュリティ横断機構を 4 つ束ねて記述する。いずれも特定のドメインに属さず、
+テンプレート共通のセキュリティ横断機構を 6 つ束ねて記述する。いずれも特定のドメインに属さず、
 リクエスト / デプロイの横断層で動く。
 
 1. **機微操作の再認証 (recent-auth / step-up)** — Critical Action の直前に「直近の再認証」を要求する。
@@ -13,6 +13,9 @@ ## 概要
    `no-store` baseline、production 起動時 / デプロイ前の fail-fast。
 4. **SSO email の信頼方針 (email trust policy)** — IdP が主張する email を検証済みとして
    扱ってよいかを provider ごとに宣言し、宣言のないものは fail-closed に倒す。
+5. **パスキー (WebAuthn)** — Fortify + laravel/passkeys のログイン / 再認証 / 管理経路と、
+   アプリ側が被せる不変条件 (所有者スコープ binder・応答契約・TOTP との関係)。
+6. **ログイン手段保持 guard** — ログイン手段が 0 になる操作を、投影後評価と行ロックで止める。
 
 MCP / CLI の OAuth 認可については [docs/mcp-oauth.md](mcp-oauth.md)、公開面の全体像は
 [docs/architecture.md](architecture.md) を参照。
@@ -37,16 +40,33 @@ ### 時間窓の判定契約
 ### 成立 (satisfier) と session state
 
 - session state の**唯一の writer** は `RecentAuthState`。satisfier 成立時に `recent_auth_at` /
-  `recent_auth_method` (`password` | `sso` | `login`) / `recent_auth_provider` を dedicated key に書く。
+  `recent_auth_method` (`password` | `sso` | `login` | `passkey`) / `recent_auth_provider` を dedicated key に書く。
   Fortify の `auth.password_confirmed_at` には**書かない** (意味汚染回避、横断標準は `recent_auth_at` が正本)。
 - 成立時は `session()->migrate(true)` で session ID を rotate する (OWASP: 権限上昇時の session fixation 対策)。
   `regenerate()` と違い **CSRF token は維持**するため、XHR モーダルや別タブの進行中フォームを壊さない。
-- satisfier は 2 経路: password 再入力 (`ConfirmRecentAuthController::confirmPassword`) と
-  再SSO (`SocialAuthController` の `intent=step-up`)。password 未設定 (SSO-only) は password 経路を **fail-closed** で拒否し、
+- satisfier は 3 経路: password 再入力 (`ConfirmRecentAuthController::confirmPassword`)、
+  再SSO (`SocialAuthController` の `intent=step-up`)、パスキー検証
+  (`StampRecentAuthOnPasskeyVerified`。`POST /passkeys/confirm`)。
+  **どの手段が使えるかはサーバの `/recent-auth/status` が単一の源** (`passwordSet` /
+  `availableProviders` / `passkeyAvailable`)。画面ごとに判定を持たせない
+  (持たせると passkey しか持たないユーザーが特定画面でだけ詰む)。
+  `canSatisfy` はこの 3 つの論理和であり、**パスキーは TOTP の有無に関係なく再認証に使える**
+  (`PasskeyLoginPolicy` が縛るのは login のみ)。
+- ⚠ **`canSatisfy` は「アカウントに手段があるか」であり「この端末で実行できるか」ではない**。
+  WebAuthn の feature detection はクライアントにしか無いため、パスキーしか持たないユーザーが
+  非対応ブラウザで開くと「手段はあるのに何も出ない」無言の行き止まりになりうる。
+  両 UI (`RecentAuthModal` / `Auth/ConfirmRecentAuth`) は
+  `passwordSet || availableProviders || (passkeyAvailable && passkeySupported)` を
+  クライアント側で導出し、成立しない場合は**理由と回復導線を明示**する
+  (`recent-auth-unsupported-here` / `confirm-unsupported-here`)。password 未設定 (SSO-only) は password 経路を **fail-closed** で拒否し、
   再SSO へ誘導する。step-up 可能な provider は `config('template.social_providers.*.capability')` から解決 (未宣言は satisfier 不可)。
 - fresh login (`Login` event、web guard・非 recaller) は `StampRecentAuthOnLogin` が `method='login'` で自動 stamp する。
   ログイン直後の機微操作で「もう 1 回」の二重壁を消す。remember-me による自動復元 (`viaRemember()`) は fresh 扱いしない (fail-closed)。
 - 認証要素変更 (password / email / 2FA / social link·unlink) 後は `RecentAuthState::clear()` で鮮度を失効させる。
+  **パスキーの登録 / 削除**は `ClearRecentAuthOnPasskeyChange` が実際に `clear()` を呼ぶ (2026-08-04 裁定 A。§5 参照)。
+- satisfier の集合 (= `RecentAuthState::confirm()` の呼び出し元) は
+  `tests/Architecture/RecentAuthRouteTest.php` の inventory が deny-by-default で固定する。
+  新しい satisfier を足すには inventory への登録が必須 (= step-up の成立条件が増えることを PR で必ず判断させる)。
 
 ### XHR / 画面応答の差 (`RequireRecentAuth`、alias `recent-auth`)
 
@@ -217,6 +237,112 @@ ### fail-closed と機械強制
 
 ---
 
+## 5. パスキー (WebAuthn)
+
+**実装**: `app/Providers/PasskeyServiceProvider.php`, `app/Models/Passkey.php`, `app/Http/Responses/Passkey/`, `app/Http/Routing/SelfScopedPasskeyBinder.php`, `app/Services/Auth/PasskeyLoginPolicy.php`, `app/Listeners/Auth/{ClearRecentAuthOnPasskeyChange,StampRecentAuthOnPasskeyVerified}.php`, `resources/js/lib/passkeys.ts`, `resources/js/components/features/auth/PasskeySection.svelte`
+
+route / controller / action / migration は **Fortify + laravel/passkeys が提供する**
+(`config/fortify.php` の `Features::passkeys(['confirmPassword' => false])` が唯一の有効化点 =
+**実質的なキルスイッチ**)。アプリ側 (`PasskeyServiceProvider`) は「vendor にアプリ固有の
+不変条件を被せる」ことだけを担う。
+
+### アプリが被せる 4 つの不変条件
+
+| # | 内容 | 理由 |
+|---|------|------|
+| 1 | **binder 差し替え** (`SelfScopedPasskeyBinder`) | vendor binder はグローバル id 解決 → controller の `abort_unless(..., 403)` に到達し **他人の passkey の存在が漏れる**。所有者スコープで解決し「他人」と「不在」を等しく 404 にする (セキュリティ不変条件 2)。非数値 / bigint 範囲外も 404 に倒す (pgsql 22P02 / 22003 の 500 化を防ぐ) |
+| 2 | **Response contract 上書き** (`app/Http/Responses/Passkey/`) | vendor 既定は `new JsonResponse(...)` の直返しで禁止事項 4 に触れる。加えて confirm 経路が書く `auth.password_confirmed_at` をここで**除去**する (recent-auth の「Fortify の鍵には書かない」契約を守る) |
+| 3 | **route middleware の後付け** | `recent-auth` (登録 / 削除)、`ensure-login-method` (削除)、`no-store` (guest の login-options)。順序は **recent-auth → ensure-login-method** (逆順だと stale なリクエストでも行ロックを取る) |
+| 4 | **login 認可** (`PasskeyLoginPolicy`) | TOTP confirmed ユーザーの passkey login を拒否する |
+
+配線は `$app->booted()` 内で最終上書きする (auto-discovery された
+`Laravel\Passkeys\PasskeysServiceProvider` との boot 順序が `bootstrap/providers.php` では
+保証されないため)。構成は `tests/Architecture/{PasskeyPackageContractTest,PasskeyRouteProtectionTest}.php` が固定する。
+
+### TOTP との関係 (c2c 未裁定に対する fail-closed 既定)
+
+vendor の `PasskeyLoginController::store()` は `$guard->login()` を直接呼び、Fortify の
+two-factor challenge を通らない。したがって **TOTP confirmed のユーザーは passkey login を
+拒否する** (assurance の後退を作らない)。判定は `PasskeyLoginPolicy` **1 箇所**に集約してあり、
+(a) vendor の login ゲート (b) `LoginMethodInventory` の passkey 判定 (c) Settings 画面の
+`passkeyLoginAvailable` prop が同時に反転する。裁定が出たらこのクラスだけを書き換える。
+
+**passkey は 2FA 準拠判定に算入しない**。2FA 必須組織の未準拠ユーザーは passkey を持っていても
+`RequireTwoFactorForEnforcedOrganizations` のゲートに掛かる。
+
+### credential 集合の変化 = recent-auth 失効 (2026-08-04 裁定 A)
+
+パスキーは単独でログインできる強い資格であり、集合が変わったら直前に済ませた本人確認は失効させる
+(家系統一原則)。`PasskeyRegistered` / `PasskeyDeleted` を `ClearRecentAuthOnPasskeyChange` が購読する。
+UX の実害は「登録直後のタップ 1 回」に限られる。
+**「登録直後の passkey を satisfier から除外する」強化オプションは裁定で見送り済み**
+(再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時)。
+
+### transport 契約 (client ↔ server)
+
+| operation | options 取得 | 送信 | 成功応答 |
+|-----------|-------------|------|---------|
+| 登録 | `fetch GET /user/passkeys/options` | Inertia `router.post('/user/passkeys')` | `back()->with('success')` |
+| 削除 | — | Inertia `router.delete('/user/passkeys/{id}')` | `back()->with('success')` |
+| 再認証 (インラインモーダル) | `fetch GET /passkeys/confirm/options` | `fetch POST /passkeys/confirm` | `204` + `no-store` |
+| 再認証 (全画面 confirm) | 同上 | Inertia `router.post('/passkeys/confirm')` | `redirect()->intended()` |
+| ログイン | `fetch GET /passkeys/login/options` | `fetch POST /passkeys/login` | JSON `{redirect}` |
+
+`@/lib/passkeys` の import 元は `tests/js/architecture/passkeys-import-isolation.test.ts` が
+allowlist で固定する (transport 契約の食い違いは**無言失敗**として現れるため)。
+
+### 運用上の注意
+
+- 設定は `APP_URL` から導出される (relying party id = ホスト、allowed origins = `[APP_URL]`)。
+  同一オリジン PWA 前提のため専用 env は持たない。
+- **`APP_KEY` をローテートすると user handle (`hash_hmac` の鍵が `APP_KEY`) が変わり、
+  登録済みパスキーが全件無効になる**。鍵ローテートを行う場合は
+  `PASSKEYS_USER_HANDLE_SECRET` 相当の固定値を `config/passkeys.php` に持たせる設計変更が必要。
+- 未認証の challenge 発行 (`GET /passkeys/login/options`) は `throttle:passkeys` (10/min) で絞る。
+  `config('fortify.limiters.passkeys')` が未設定だと Fortify が throttle を外し **無制限**になる。
+
+---
+
+## 6. ログイン手段保持 guard (`EnsureLoginMethodRemains`)
+
+**実装**: `app/Http/Middleware/EnsureLoginMethodRemains.php`, `app/Services/Auth/LoginMethodInventory.php`, `app/DataTransferObjects/Auth/{LoginMethodRemoval,LoginMethodSet}.php`
+
+ログイン手段を全部消して自分で締め出す事故は復旧コストが高く、現場を止める。
+手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する。
+
+- **評価するのは現在状態ではなく「操作が成功した後の投影状態」**。素朴に現在を数えると
+  削除対象自身が残存手段として数えられ、「唯一の passkey を削除できてしまう」= 意図と正反対になる。
+- **直列化規約 (TOCTOU 対策)**: middleware が (1) transaction を開き (2) 対象 User 行を
+  `lockForUpdate()` で取り (3) **ロック取得後に**投影を評価し (4) **同一 transaction 内で `$next()`**
+  を実行する。ロック取得順序は User → credential。
+- 手段の基準は「データが存在する」ではなく「**使える**」(`LoginMethodInventory`)。
+  config から外された provider や feature off の passkey は数えない (数えると guard が形骸化する)。
+- `canSatisfy` (recent-auth の step-up 成立可否) とは**別概念**。統合しないこと。
+
+### 応答契約 (transport で分岐)
+
+| リクエスト種別 | 応答 |
+|--------------|------|
+| Inertia | `302` (Inertia が DELETE では 303 に変換) + `errors.login_method` |
+| 純 XHR (`Accept: application/json`) | `422` + `{ code: 'login_method_required', message, settingsUrl }` (`no-store`) |
+| 通常フォーム | `back()->withErrors('login_method')` |
+
+**Inertia に 422 JSON を返さない** (protocol 違反で router が応答を解釈できず無言失敗する)。
+
+### 適用範囲の機械強制
+
+`tests/Architecture/LoginMethodRemovalRouteTest.php` が **両方向**で固定する。
+
+1. 認証系 URI 空間の破壊的 route は「guard 必須」か「理由付き免除」のどちらかに**必ず分類**される。
+2. **allowlist 外の route に付与してはならない** — `$next()` を transaction 内で実行するため、
+   streamed response / 外部 I/O / `afterCommit` でない queue dispatch を含む route に付けると
+   副作用範囲が急拡大する。
+
+将来 password 削除 / SSO 連携解除 route を追加するときも**必ずこの middleware を通す**
+(単一の直列化点。別経路を作ると TOCTOU が戻る)。
+
+---
+
 ## 関連ファイル
 
 | ファイル | 役割 |
@@ -228,6 +354,12 @@ ## 関連ファイル
 | `app/Listeners/Auth/StampRecentAuthOnLogin.php` | fresh login を recent-auth 成立として stamp (recaller 除外) |
 | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` | 認証済み応答の `no-store` baseline (bfcache 由来の PII 再表示防止) |
 | `app/Http/Controllers/Auth/SessionStatusController.php` | セッション有効性の軽量プローブ (`session.status`)。auth グループの外・guest でも 200 |
+| `app/Providers/PasskeyServiceProvider.php` | laravel/passkeys の app アダプタ (binder / Response contract / middleware 後付け / login 認可) |
+| `app/Http/Routing/SelfScopedPasskeyBinder.php` | `{passkey}` を所有者スコープで解決 (他人 / 不在 / 不正型をすべて 404) |
+| `app/Services/Auth/PasskeyLoginPolicy.php` | passkey **ログイン**可否の単一判定点 (feature flag + TOTP) |
+| `app/Services/Auth/LoginMethodInventory.php` | 投影後のログイン手段集合 (`remainingAfter`) |
+| `app/Http/Middleware/EnsureLoginMethodRemains.php` | `ensure-login-method` alias。手段が 0 になる操作を投影後評価 + 行ロックで止める |
+| `app/Http/Middleware/NoStoreResponse.php` | `no-store` alias。guest route (passkey の login-options) の challenge をキャッシュさせない |
 | `resources/js/lib/bfcache-guard.ts` | bfcache 復元時のクライアント側秘匿・再検証 (Safari 対策。正本は `docs/supported-browsers.md`) |
 | `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` | 2FA 未準拠ユーザーの全画面ゲート (allowlist 外を 302 / 409) |
 | `app/Http/Middleware/BlockTwoFactorDisableForEnforcedOrganizations.php` | 準拠ユーザーの self-disable 到達を弾く (422 / back) |
diff --git a/resources/js/components/organisms/RecentAuthModal.svelte b/resources/js/components/organisms/RecentAuthModal.svelte
index dbe5d51..55c24d6 100644
--- a/resources/js/components/organisms/RecentAuthModal.svelte
+++ b/resources/js/components/organisms/RecentAuthModal.svelte
@@ -7,6 +7,7 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
     import { csrfToken } from "@/lib/csrf";
+    import { confirmWithPasskey, isPasskeySupported } from "@/lib/passkeys";
     import type { AvailableReauthProvider } from "@/lib/recent-auth";
     import { providerLabel } from "@/lib/social";
 
@@ -15,6 +16,8 @@
      * 「同一画面の再認証 (step-up) モーダル」。
      * - password 設定済みユーザー: パスワード再入力 → POST /recent-auth/password (XHR=204 成功)。
      * - 再SSO 可能な provider (availableProviders): reauthUrl へフルリダイレクト。
+     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 → POST /passkeys/confirm (204)。
+     *   TOTP 有効ユーザーでも **再認証には使える** (PasskeyLoginPolicy が縛るのはログインのみ)。
      * - canSatisfy=false (再認証手段なし): 回復導線 (パスワードリセット) を案内する。
      * 認可の最終ゲートは各操作の recent-auth middleware (本モーダルは UX 補助)。
      */
@@ -23,6 +26,12 @@
         passwordSet?: boolean;
         availableProviders?: AvailableReauthProvider[];
         canSatisfy?: boolean;
+        /**
+         * パスキーでの再認証を提示するか。**サーバの `/recent-auth/status` が単一の源**
+         * (`RecentAuthStatus.passkeyAvailable`)。呼び出し側が独自に判定しない
+         * — 画面ごとに判定を持つと passkey しか持たないユーザーが特定画面でだけ詰む。
+         */
+        passkeyAvailable?: boolean;
         /** password satisfier 成功時 (204)。呼び出し側が pending action を再開する */
         onConfirmed: () => void;
     }
@@ -32,9 +41,46 @@
         passwordSet = false,
         availableProviders = [],
         canSatisfy = true,
+        passkeyAvailable = false,
         onConfirmed,
     }: Props = $props();
 
+    const passkeySupported = isPasskeySupported();
+    let passkeySubmitting = $state(false);
+
+    /**
+     * **この端末で実行できる** satisfier があるか。
+     * `canSatisfy` は「アカウントに手段があるか」(サーバ判定) であり、
+     * パスキーしか無いユーザーが WebAuthn 非対応ブラウザで開くと
+     * 「手段はあるが、この端末では実行できない」= 説明の無い行き止まりになる。
+     * その状態を明示的に表現して回復導線を出す。
+     */
+    const executableHere = $derived(
+        passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported),
+    );
+
+    async function submitPasskey(): Promise<void> {
+        if (passkeySubmitting) return;
+        passkeySubmitting = true;
+        error = "";
+        try {
+            const outcome = await confirmWithPasskey();
+            if (outcome.status === "ok") {
+                open = false;
+                onConfirmed();
+                return;
+            }
+            // キャンセルは失敗として騒がない (再試行導線を残す)
+            if (outcome.status === "cancelled") return;
+            error =
+                outcome.status === "unsupported"
+                    ? "このブラウザはパスキーに対応していません。"
+                    : outcome.message;
+        } finally {
+            passkeySubmitting = false;
+        }
+    }
+
     let password = $state("");
     let error = $state("");
     let submitting = $state(false);
@@ -45,6 +91,7 @@
             password = "";
             error = "";
             submitting = false;
+            passkeySubmitting = false;
         }
     });
 
@@ -121,10 +168,25 @@
             <FormError message={error} testId="recent-auth-error" />
         {/if}
 
-        {#if availableProviders.length > 0}
+        {#if passkeyAvailable && passkeySupported}
             {#if passwordSet}
                 <Divider label="または" />
             {/if}
+            <Button
+                variant="ghost"
+                fullWidth
+                loading={passkeySubmitting}
+                onclick={() => void submitPasskey()}
+                testId="recent-auth-passkey"
+            >
+                パスキーで再認証
+            </Button>
+        {/if}
+
+        {#if availableProviders.length > 0}
+            {#if passwordSet || (passkeyAvailable && passkeySupported)}
+                <Divider label="または" />
+            {/if}
             <div class="flex flex-col gap-2">
                 {#each availableProviders as provider (provider.provider)}
                     <Button
@@ -146,6 +208,17 @@
                     パスワードを設定して再認証する
                 </Button>
             </div>
+        {:else if !executableHere}
+            <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
+            <div
+                class="flex flex-col gap-2 text-caption text-text-secondary"
+                data-testid="recent-auth-unsupported-here"
+            >
+                <p>
+                    このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
+                    パスキーを登録した端末・ブラウザで開き直すと再認証できます。
+                </p>
+            </div>
         {/if}
     </div>
     {#snippet footer()}
diff --git a/resources/js/pages/Auth/ConfirmRecentAuth.svelte b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
index 051170c..f5acd2a 100644
--- a/resources/js/pages/Auth/ConfirmRecentAuth.svelte
+++ b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
@@ -7,6 +7,7 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import PasswordInput from "@/components/molecules/PasswordInput.svelte";
     import AuthLayout from "@/components/templates/AuthLayout.svelte";
+    import { confirmPasskeyCredential, isPasskeySupported } from "@/lib/passkeys";
     import type { AvailableReauthProvider } from "@/lib/recent-auth";
     import { providerLabel } from "@/lib/social";
 
@@ -16,6 +17,8 @@
      * intended URL へ戻る (server 側 redirect()->intended)。
      * - password 設定済みユーザー: password 再入力フォーム (POST /recent-auth/password)
      * - 再SSO 可能な provider: reauthUrl (/auth/{provider}/redirect/step-up) で再認証
+     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 (POST /passkeys/confirm、204)。
+     *   **パスキーしか持たないユーザーをこの画面で詰ませない**ための導線
      * - canSatisfy=false: 回復手順 (ログアウト → guest としてパスワード再設定) を案内。
      *   /forgot-password へ直接リンクしない — Fortify が `guest` middleware 付きで登録しており
      *   ログイン済みの本画面ユーザーはフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)
@@ -24,6 +27,8 @@
         appName?: string;
         passwordSet?: boolean;
         availableProviders?: AvailableReauthProvider[];
+        /** パスキーで再認証できるか (サーバが単一の源) */
+        passkeyAvailable?: boolean;
         canSatisfy?: boolean;
     }
 
@@ -31,9 +36,61 @@
         appName,
         passwordSet = false,
         availableProviders = [],
+        passkeyAvailable = false,
         canSatisfy = true,
     }: Props = $props();
 
+    const passkeySupported = isPasskeySupported();
+    let passkeyError = $state("");
+    let passkeyProcessing = $state(false);
+
+    /**
+     * **この端末で実行できる** satisfier があるか。
+     * `canSatisfy` は「アカウントに手段があるか」(サーバ判定)。パスキーしか無いユーザーが
+     * WebAuthn 非対応ブラウザで開くと「手段はあるが、この端末では実行できない」=
+     * 説明の無い行き止まりになるため、その状態を明示して回復導線を出す。
+     */
+    const executableHere = $derived(
+        passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported),
+    );
+
+    /**
+     * パスキーで再認証する。
+     *
+     * ceremony 結果は **Inertia の router.post で送る** (fetch ではない)。
+     * この画面は RequireRecentAuth の 302 fallback 着地であり、元 URL は
+     * サーバの `url.intended` にしか無い。Inertia で送れば
+     * PasskeyConfirmationResponse が `redirect()->intended()` を返し、元の操作画面へ戻る。
+     */
+    async function submitPasskey(): Promise<void> {
+        if (passkeyProcessing) return;
+        passkeyProcessing = true;
+        passkeyError = "";
+        try {
+            const outcome = await confirmPasskeyCredential();
+            if (outcome.status === "ok") {
+                router.post(
+                    "/passkeys/confirm",
+                    { credential: outcome.value },
+                    {
+                        onError: () => {
+                            passkeyError = "パスキーでの再認証に失敗しました。";
+                        },
+                    },
+                );
+                return;
+            }
+            // キャンセルは失敗として騒がない (再試行導線を残す)
+            if (outcome.status === "cancelled") return;
+            passkeyError =
+                outcome.status === "unsupported"
+                    ? "このブラウザはパスキーに対応していません。"
+                    : outcome.message;
+        } finally {
+            passkeyProcessing = false;
+        }
+    }
+
     const form = useForm({
         password: "",
     });
@@ -89,11 +146,31 @@
         <FormError message={form.errors.password} />
     {/if}
 
-    {#if availableProviders.length > 0}
+    {#if passkeyAvailable && passkeySupported}
         <div class="mt-6 flex flex-col gap-3">
             {#if passwordSet}
                 <Divider label="または" />
             {/if}
+            {#if passkeyError}
+                <FormError message={passkeyError} testId="confirm-passkey-error" />
+            {/if}
+            <Button
+                variant="ghost"
+                fullWidth
+                loading={passkeyProcessing}
+                onclick={() => void submitPasskey()}
+                testId="confirm-passkey-button"
+            >
+                パスキーで再認証
+            </Button>
+        </div>
+    {/if}
+
+    {#if availableProviders.length > 0}
+        <div class="mt-6 flex flex-col gap-3">
+            {#if passwordSet || (passkeyAvailable && passkeySupported)}
+                <Divider label="または" />
+            {/if}
             {#each availableProviders as provider (provider.provider)}
                 <Button href={provider.reauthUrl} variant="ghost" fullWidth>
                     {providerLabel(provider.provider)}で再認証
@@ -113,6 +190,22 @@
                 ログアウトする
             </Button>
         </div>
+    {:else if !executableHere}
+        <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
+        <div
+            class="mt-6 flex flex-col gap-3 text-caption text-text-secondary"
+            data-testid="confirm-unsupported-here"
+        >
+            <p>
+                このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
+                パスキーを登録した端末・ブラウザで開き直すと再認証できます。
+                その端末が使えない場合は、いったんログアウトし、ログイン画面の
+                「パスワードをお忘れの方」からパスワードを設定してください。
+            </p>
+            <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
+                ログアウトする
+            </Button>
+        </div>
     {/if}
 
     {#snippet footer()}
diff --git a/tests/Feature/Auth/PasskeyRouteAccessTest.php b/tests/Feature/Auth/PasskeyRouteAccessTest.php
new file mode 100644
index 0000000..fd8f6f3
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyRouteAccessTest.php
@@ -0,0 +1,170 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\User;
+
+/*
+ * passkey route の到達制御 (未認証 / step-up / 他人の credential / キャッシュ / throttle)。
+ *
+ * WebAuthn ceremony 自体はブラウザ API を要するため自動化しない。
+ * ここで固定するのは **ceremony に到達する前の関門**。
+ */
+
+test('未認証は passkey 登録 options に到達できない', function (): void {
+    $this->get('/user/passkeys/options')->assertRedirect('/login');
+});
+
+test('未認証は passkey 削除に到達できない', function (): void {
+    $this->delete('/user/passkeys/1')->assertRedirect('/login');
+});
+
+test('recent-auth 鮮度切れの Inertia mutation は 409 (step-up 要求)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->post('/user/passkeys', ['name' => 'テスト'])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+});
+
+test('recent-auth 鮮度切れの登録 options 取得は confirm 画面へ誘導される', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->get('/user/passkeys/options')
+        ->assertRedirect(route('recent-auth.confirm'));
+});
+
+/*
+ * **他人の passkey と不在 id が同じ 404 になること** (AGENTS.md セキュリティ不変条件 2)。
+ * vendor 実装のままだと controller の `abort_unless(..., 403)` に到達し、
+ * 403 と 404 の差で他人の passkey の存在が漏れる。
+ */
+test('他人の passkey の削除は 404 (403 にしない)', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    $passkey = Passkey::factory()->for($other)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertNotFound();
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
+});
+
+test('不在 id の削除も同じ 404 (存在を漏らさない)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/999999')
+        ->assertNotFound();
+});
+
+test('非数値 id の削除は 500 ではなく 404 (pgsql 22P02 の回避)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/abc')
+        ->assertNotFound();
+});
+
+test('bigint 範囲外の id の削除も 404 (pgsql 22003 の回避)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/99999999999999999999999999')
+        ->assertNotFound();
+});
+
+test('guest の login options 応答は no-store (challenge をキャッシュさせない)', function (): void {
+    $response = $this->get('/passkeys/login/options');
+
+    $response->assertOk();
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+});
+
+test('passkeys limiter が未認証の challenge 発行を絞る', function (): void {
+    // limiter は 10/min。11 回目で 429 になる
+    for ($i = 0; $i < 10; $i++) {
+        $this->get('/passkeys/login/options')->assertOk();
+    }
+
+    $this->get('/passkeys/login/options')->assertStatus(429);
+});
+
+/*
+ * **登録 POST の request shape を vendor 契約に固定する**。
+ *
+ * vendor の PasskeyRegistrationRequest は
+ * `name` + `credential.{id,rawId,type,response}` の **nested** 形を要求する。
+ * client (PasskeySection.svelte) が `{ name, credential }` で送ることと対になっており、
+ * ここがズレると **登録が全面的に失敗する** (WebAuthn ceremony は自動化できないため、
+ * shape の食い違いは validation 段でしか検出できない)。
+ */
+test('登録 POST は nested な credential を要求する (flat 展開は validation で落ちる)', function (): void {
+    $user = User::factory()->create();
+
+    // 設計書の初稿にあった flat 形 ({ name, ...credential }) は credential 必須で落ちる
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->postJson('/user/passkeys', [
+            'name' => 'テスト',
+            'id' => 'abc',
+            'rawId' => 'abc',
+            'type' => 'public-key',
+            'response' => ['clientDataJSON' => 'x'],
+        ])
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('credential');
+});
+
+test('登録 POST の nested credential は rules を通過し ceremony 検証まで進む', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->postJson('/user/passkeys', [
+            'name' => 'テスト',
+            'credential' => [
+                'id' => 'abc',
+                'rawId' => 'abc',
+                'type' => 'public-key',
+                'response' => ['clientDataJSON' => 'x'],
+            ],
+        ]);
+
+    // rules() は通過し、**passedValidation() の ceremony デシリアライズ**で落ちる。
+    // 「必須項目が足りない」ではなく「中身が不正」という別の 422 であることを
+    // メッセージの完全一致で固定する (`not->toBe(...)` だとキー不在でも通り空振りする)。
+    $response->assertStatus(422);
+    $response->assertJsonPath('errors.credential.0', 'Invalid credential format.');
+    // name も rules を通過している (nested 形が rules 段で拒否されていない証明)
+    $response->assertJsonMissingValidationErrors(['name', 'credential.id', 'credential.rawId', 'credential.type', 'credential.response']);
+});
+
+/*
+ * ログイン route は **guest session に鮮度を残さない**。
+ * (VerifyPasskey は allowsLogin より前に PasskeyVerified を dispatch するため、
+ *  StampRecentAuthOnPasskeyVerified の本人性バインドが唯一の防御線になる)
+ */
+test('passkey login の失敗は guest session に recent_auth を残さない', function (): void {
+    $response = $this->postJson('/passkeys/login', [
+        'credential' => [
+            'id' => 'abc',
+            'rawId' => 'abc',
+            'type' => 'public-key',
+            'response' => ['clientDataJSON' => 'x'],
+        ],
+    ]);
+
+    $response->assertStatus(422);
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+    $this->assertGuest();
+});
diff --git a/tests/js/pages/ConfirmRecentAuthPasskey.test.ts b/tests/js/pages/ConfirmRecentAuthPasskey.test.ts
new file mode 100644
index 0000000..b031ea0
--- /dev/null
+++ b/tests/js/pages/ConfirmRecentAuthPasskey.test.ts
@@ -0,0 +1,202 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+
+/*
+ * confirm 画面 (302 fallback 着地) のパスキー導線。
+ *
+ * **パスキーしか持たないユーザーをこの画面で詰ませない**ことが目的。
+ * 送信は fetch ではなく **Inertia の router.post** で行う — 元 URL はサーバの
+ * `url.intended` にしか無く、PasskeyConfirmationResponse の
+ * `redirect()->intended()` 分岐に乗せる必要があるため。
+ */
+
+const { routerPostMock, confirmPasskeyCredentialMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    confirmPasskeyCredentialMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock, visit: vi.fn() },
+}));
+
+vi.mock("@/lib/passkeys", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/passkeys")>()),
+    confirmPasskeyCredential: confirmPasskeyCredentialMock,
+}));
+
+import ConfirmRecentAuth from "@/pages/Auth/ConfirmRecentAuth.svelte";
+
+const CREDENTIAL_FIXTURE = {
+    id: "cred-id",
+    rawId: "cred-raw-id",
+    type: "public-key",
+    response: { clientDataJSON: "aaa", authenticatorData: "bbb", signature: "ccc", userHandle: null },
+};
+
+function stubPasskeySupport(): void {
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: { create: vi.fn(), get: vi.fn() } },
+    });
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: function PublicKeyCredentialStub() {
+            // instanceof 判定にのみ使う
+        },
+    });
+}
+
+function removePasskeySupport(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+beforeEach(() => {
+    stubPasskeySupport();
+});
+
+afterEach(() => {
+    cleanup();
+    removePasskeySupport();
+    routerPostMock.mockReset();
+    confirmPasskeyCredentialMock.mockReset();
+});
+
+describe("Auth/ConfirmRecentAuth パスキー導線", () => {
+    it("passkeyAvailable=false ならパスキーボタンを出さない", () => {
+        render(ConfirmRecentAuth, {
+            props: { passwordSet: true, passkeyAvailable: false, canSatisfy: true },
+        });
+
+        expect(screen.queryByTestId("confirm-passkey-button")).toBeNull();
+    });
+
+    it("パスキーしか無いユーザーでもパスキーで再認証できる (詰まない)", async () => {
+        confirmPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
+
+        render(ConfirmRecentAuth, {
+            props: { passwordSet: false, passkeyAvailable: true, canSatisfy: true },
+        });
+
+        // 「再認証手段が設定されていません」の行き止まり表示は出ない
+        expect(screen.queryByText(/再認証手段が設定されていません/)).toBeNull();
+
+        await fireEvent.click(screen.getByTestId("confirm-passkey-button"));
+
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalledWith(
+                "/passkeys/confirm",
+                { credential: CREDENTIAL_FIXTURE },
+                expect.anything(),
+            );
+        });
+    });
+
+    it("ceremony 失敗は同画面にエラーを出し POST しない (回復導線を残す)", async () => {
+        confirmPasskeyCredentialMock.mockResolvedValue({
+            status: "failed",
+            message: "パスキーの認証を開始できませんでした。",
+        });
+
+        render(ConfirmRecentAuth, {
+            props: { passwordSet: true, passkeyAvailable: true, canSatisfy: true },
+        });
+
+        await fireEvent.click(screen.getByTestId("confirm-passkey-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("confirm-passkey-error")).toHaveTextContent(
+                "パスキーの認証を開始できませんでした。",
+            );
+        });
+        expect(routerPostMock).not.toHaveBeenCalled();
+        // パスワード欄は残る
+        expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
+    });
+
+    it("キャンセルはエラーを出さず POST もしない (騒がない)", async () => {
+        confirmPasskeyCredentialMock.mockResolvedValue({ status: "cancelled" });
+
+        render(ConfirmRecentAuth, {
+            props: { passwordSet: true, passkeyAvailable: true, canSatisfy: true },
+        });
+
+        await fireEvent.click(screen.getByTestId("confirm-passkey-button"));
+
+        await waitFor(() => {
+            expect(confirmPasskeyCredentialMock).toHaveBeenCalled();
+        });
+        expect(screen.queryByTestId("confirm-passkey-error")).toBeNull();
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("非対応ブラウザではパスキーボタンを出さない", () => {
+        removePasskeySupport();
+        render(ConfirmRecentAuth, {
+            props: { passwordSet: true, passkeyAvailable: true, canSatisfy: true },
+        });
+
+        expect(screen.queryByTestId("confirm-passkey-button")).toBeNull();
+    });
+});
+
+/*
+ * **「アカウントには手段があるが、この端末では実行できない」を無言にしない**。
+ *
+ * `canSatisfy` はサーバ判定 (アカウント側の能力) であり、WebAuthn の feature detection は
+ * クライアント側にしか無い。passkey しか持たないユーザーが非対応ブラウザで開くと
+ * 「password 欄も SSO も passkey ボタンも出ないが canSatisfy=true なので回復案内も出ない」
+ * という説明の無い行き止まりになる。
+ */
+describe("Auth/ConfirmRecentAuth この端末では実行できない状態", () => {
+    it("passkey のみ + 非対応ブラウザなら理由と回復導線を出す", () => {
+        removePasskeySupport();
+
+        render(ConfirmRecentAuth, {
+            props: {
+                passwordSet: false,
+                availableProviders: [],
+                passkeyAvailable: true,
+                canSatisfy: true,
+            },
+        });
+
+        expect(screen.getByTestId("confirm-unsupported-here")).toBeInTheDocument();
+        expect(screen.getByRole("button", { name: "ログアウトする" })).toBeInTheDocument();
+    });
+
+    it("対応ブラウザなら「この端末では実行できない」案内を出さない", () => {
+        render(ConfirmRecentAuth, {
+            props: {
+                passwordSet: false,
+                availableProviders: [],
+                passkeyAvailable: true,
+                canSatisfy: true,
+            },
+        });
+
+        expect(screen.queryByTestId("confirm-unsupported-here")).toBeNull();
+        expect(screen.getByTestId("confirm-passkey-button")).toBeInTheDocument();
+    });
+
+    it("password があれば非対応ブラウザでも案内を出さない (実行可能な手段が残る)", () => {
+        removePasskeySupport();
+
+        render(ConfirmRecentAuth, {
+            props: {
+                passwordSet: true,
+                availableProviders: [],
+                passkeyAvailable: true,
+                canSatisfy: true,
+            },
+        });
+
+        expect(screen.queryByTestId("confirm-unsupported-here")).toBeNull();
+        expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
+    });
+});
diff --git a/tests/js/pages/SettingsSecurityPasskey.test.ts b/tests/js/pages/SettingsSecurityPasskey.test.ts
new file mode 100644
index 0000000..eb21519
--- /dev/null
+++ b/tests/js/pages/SettingsSecurityPasskey.test.ts
@@ -0,0 +1,412 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import Security from "@/pages/Settings/Security.svelte";
+
+/*
+ * セキュリティ設定のパスキーカード (T106 施策 6)。
+ * - 非対応 / 作成不可の端末に理由を出す (ボタンは disabled にしない = AGENTS.md 禁止事項 8)
+ * - 2FA 有効時は「ログインには使えないが再認証には使える」を明示する (誤認防止)
+ * - 登録 / 削除は recent-auth precheck を通す (stale なら再認証モーダル)
+ * - EnsureLoginMethodRemains の拒否 (errors.login_method) を画面に出す (無言失敗にしない)
+ */
+
+const { routerPostMock, routerDeleteMock, pageState, addToastMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    routerDeleteMock: vi.fn(),
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/settings/security",
+    },
+    addToastMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock, delete: routerDeleteMock },
+    page: pageState,
+}));
+
+vi.mock("@/lib/stores/toast", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
+    addToast: addToastMock,
+}));
+
+/*
+ * 登録 ceremony はブラウザ API を要するため、ラッパの戻り値だけを差し替えて
+ * **送信 payload の shape** を固定する (vendor の PasskeyRegistrationRequest は
+ * `{ name, credential: {...} }` の nested 形を要求する。サーバ側の対の固定は
+ * tests/Feature/Auth/PasskeyRouteAccessTest.php)。
+ */
+const { createPasskeyCredentialMock } = vi.hoisted(() => ({
+    createPasskeyCredentialMock: vi.fn(),
+}));
+
+vi.mock("@/lib/passkeys", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/passkeys")>()),
+    createPasskeyCredential: createPasskeyCredentialMock,
+}));
+
+const CREDENTIAL_FIXTURE = {
+    id: "cred-id",
+    rawId: "cred-raw-id",
+    type: "public-key",
+    response: { clientDataJSON: "aaa", attestationObject: "bbb" },
+};
+
+const fetchMock = vi.fn();
+
+function setPageProps(options: { twoFactor?: boolean; errors?: Record<string, string> } = {}): void {
+    pageState.props = {
+        appName: "AI-CUE",
+        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: options.twoFactor ?? false } },
+        errors: options.errors ?? {},
+    };
+}
+
+/** WebAuthn 対応端末を偽装する */
+function stubPasskeySupport(creatable = true): void {
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: { create: vi.fn(), get: vi.fn() } },
+    });
+    const publicKeyCredential = function PublicKeyCredentialStub() {
+        // instanceof 判定にのみ使う
+    } as unknown as typeof PublicKeyCredential;
+    (
+        publicKeyCredential as unknown as {
+            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+        }
+    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(creatable);
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: publicKeyCredential,
+    });
+}
+
+function removePasskeySupport(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
+    return { ok, status, json: () => Promise.resolve(body) };
+}
+
+function stubRecentAuth(recent: boolean, passkeyAvailable = false): void {
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/recent-auth/status")) {
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent,
+                    passwordSet: true,
+                    availableProviders: [],
+                    passkeyAvailable,
+                    canSatisfy: true,
+                    confirmedAt: recent ? 1 : null,
+                }),
+            );
+        }
+        return Promise.resolve(jsonResponse(false, 500, {}));
+    });
+}
+
+const passkeys = [
+    {
+        id: 7,
+        name: "現場用スマホ",
+        authenticator: "iCloud Keychain",
+        lastUsedAt: "2026-08-01T00:00:00+09:00",
+        createdAt: "2026-07-01T00:00:00+09:00",
+    },
+];
+
+beforeEach(() => {
+    setPageProps();
+    stubPasskeySupport();
+    vi.stubGlobal("fetch", fetchMock);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    removePasskeySupport();
+    routerPostMock.mockReset();
+    routerDeleteMock.mockReset();
+    addToastMock.mockReset();
+    fetchMock.mockReset();
+    createPasskeyCredentialMock.mockReset();
+});
+
+describe("Settings/Security パスキーカード", () => {
+    it("非対応ブラウザでは理由を出すが登録ボタンは disabled にしない", () => {
+        removePasskeySupport();
+        render(Security, { props: {} });
+
+        expect(screen.getByTestId("passkey-unsupported")).toBeInTheDocument();
+        expect(screen.getByTestId("register-passkey-button")).not.toBeDisabled();
+    });
+
+    it("非対応ブラウザで登録を押すと理由をトーストで出す (無言失敗にしない)", async () => {
+        removePasskeySupport();
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        expect(addToastMock).toHaveBeenCalledWith("error", expect.stringContaining("対応していません"));
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("プラットフォーム認証器が使えない端末には作成不可の理由を出す", async () => {
+        stubPasskeySupport(false);
+        render(Security, { props: {} });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("passkey-not-creatable")).toBeInTheDocument();
+        });
+    });
+
+    it("2FA 有効時は「ログイン不可・再認証は可」を明示する", () => {
+        setPageProps({ twoFactor: true });
+        render(Security, { props: { passkeyLoginAvailable: false } });
+
+        expect(screen.getByTestId("passkey-2fa-notice")).toBeInTheDocument();
+    });
+
+    it("2FA 無効かつ passkeyLoginAvailable なら 2FA 注意書きを出さない", () => {
+        render(Security, { props: { passkeyLoginAvailable: true } });
+
+        expect(screen.queryByTestId("passkey-2fa-notice")).toBeNull();
+    });
+
+    it("登録済みパスキーを一覧表示する", () => {
+        render(Security, { props: { passkeys } });
+
+        expect(screen.getByTestId("passkey-list")).toBeInTheDocument();
+        expect(screen.getByText("現場用スマホ")).toBeInTheDocument();
+        expect(screen.getByTestId("passkey-count")).toHaveTextContent("1 件登録済み");
+    });
+
+    it("名前未入力の登録はエラー表示のみで ceremony を開始しない", async () => {
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        expect(screen.getByText("パスキーの名前を入力してください。")).toBeInTheDocument();
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("削除は確認ダイアログを挟み、確認までは DELETE しない", async () => {
+        stubRecentAuth(true);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+
+        // 一覧側にも同名が出るためダイアログ本体で照合する
+        expect(screen.getByTestId("delete-passkey-dialog")).toHaveTextContent("現場用スマホ");
+        expect(routerDeleteMock).not.toHaveBeenCalled();
+    });
+
+    it("確認後は recent-auth precheck を通して DELETE する", async () => {
+        stubRecentAuth(true);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(routerDeleteMock).toHaveBeenCalledWith(
+                "/user/passkeys/7",
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
+    });
+
+    it("recent-auth が stale なら再認証モーダルを開き DELETE しない", async () => {
+        stubRecentAuth(false);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(routerDeleteMock).not.toHaveBeenCalled();
+    });
+
+    it("ログイン手段保持 guard の拒否メッセージを画面に出す (無言失敗にしない)", () => {
+        setPageProps({ errors: { login_method: "この操作を行うと、ログインする手段がなくなります。" } });
+        render(Security, { props: { passkeys } });
+
+        const alert = screen.getByTestId("passkey-login-method-error");
+        expect(alert).toBeInTheDocument();
+        expect(alert).toHaveTextContent("ログインする手段がなくなります");
+        // 回復導線 (別のログイン手段を追加する) を同画面に出す
+        expect(screen.getByTestId("passkey-add-password")).toBeInTheDocument();
+    });
+
+    // passkey 導線の可否は **サーバの status が単一の源** (画面側で判定しない)
+    it("status の passkeyAvailable が true なら再認証モーダルにパスキー導線が出る", async () => {
+        stubRecentAuth(false, true);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-passkey")).toBeInTheDocument();
+        });
+    });
+
+    it("status の passkeyAvailable が false ならパスキー導線を出さない", async () => {
+        stubRecentAuth(false, false);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
+    });
+});
+
+describe("パスキー登録の送信契約", () => {
+    it("ceremony 成功時に { name, credential } の nested 形で POST する", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({
+            status: "ok",
+            value: CREDENTIAL_FIXTURE,
+        });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalledWith(
+                "/user/passkeys",
+                { name: "現場用スマホ", credential: CREDENTIAL_FIXTURE },
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        // recent-auth precheck を経由している (登録も step-up 必須)
+        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
+    });
+
+    it("recent-auth が stale なら再認証モーダルを開き ceremony を開始しない", async () => {
+        stubRecentAuth(false);
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(createPasskeyCredentialMock).not.toHaveBeenCalled();
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("ceremony キャンセルは POST せず、エラートーストも出さない (騒がない)", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({ status: "cancelled" });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => {
+            expect(createPasskeyCredentialMock).toHaveBeenCalled();
+        });
+        expect(routerPostMock).not.toHaveBeenCalled();
+        expect(addToastMock).not.toHaveBeenCalled();
+    });
+
+    it("ceremony 失敗はエラーを出して POST しない", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({
+            status: "failed",
+            message: "パスキーの登録を開始できませんでした。",
+        });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => {
+            expect(addToastMock).toHaveBeenCalledWith(
+                "error",
+                "パスキーの登録を開始できませんでした。",
+            );
+        });
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+});
+
+/*
+ * **「アカウントには手段があるが、この端末では実行できない」を無言にしない**
+ * (RecentAuthModal 側。confirm 画面側は ConfirmRecentAuthPasskey.test.ts)。
+ */
+describe("再認証モーダル: この端末では実行できない状態", () => {
+    function stubPasskeyOnlyStatus(): void {
+        fetchMock.mockImplementation((input: RequestInfo | URL) => {
+            const url = String(input);
+            if (url.includes("/recent-auth/status")) {
+                return Promise.resolve(
+                    jsonResponse(true, 200, {
+                        recent: false,
+                        passwordSet: false,
+                        availableProviders: [],
+                        passkeyAvailable: true,
+                        canSatisfy: true,
+                        confirmedAt: null,
+                    }),
+                );
+            }
+            return Promise.resolve(jsonResponse(false, 500, {}));
+        });
+    }
+
+    it("passkey のみ + 非対応ブラウザなら理由を出す (無言の行き止まりにしない)", async () => {
+        removePasskeySupport();
+        stubPasskeyOnlyStatus();
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-unsupported-here")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
+    });
+
+    it("対応ブラウザなら理由ではなくパスキー導線を出す", async () => {
+        stubPasskeyOnlyStatus();
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-passkey")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("recent-auth-unsupported-here")).toBeNull();
+    });
+});

```

---

## テスト結果 (Round 3)

- `composer test`: **2809 tests / 2807 passed / 0 failed / 2 skipped** (11280 assertions)
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm test` (vitest): **115 files / 1050 tests passed** (Round 2 の 1045 から +5)

## 確認してほしい点

1. `canSatisfy` (アカウント能力) と `executableHere` (端末能力) の分離が妥当か。
   サーバ側に端末能力を持ち込まない判断で問題ないか
2. 全画面 confirm では**行動可能な CTA (ログアウト)** を置き、インラインモーダルでは
   理由の提示に留めた (モーダルはキャンセルで閉じられ、背後の画面に留まれるため)。
   この非対称が妥当か
3. 空振り防止の観点で `assertJsonPath` + `assertJsonMissingValidationErrors` の組合せが十分か

これで残指摘が無ければ **APPROVED** を明示してほしい。
