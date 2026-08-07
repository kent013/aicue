# 実装レビュー Round 2 (T124: 2FA 秘密 GET と enable の step-up 化)

Round 1 の指摘への対応と、その後に発生した**外部要因 (main の取り込み)** への追随を報告する。
使命・禁止事項・思考原則・ツール使用制限は Round 1 のプロンプトのものを引き続き適用する。

## Round 1 指摘への対応

### [Warning] `retryEnrollmentAssets()` が `enrollmentAssetsFailed` をリセットしていない
**対応した。** ただし修正箇所は `retryEnrollmentAssets()` ではなく
**`loadEnrollmentAssets()` の冒頭**にした (結果表示の**単一初期化点**にするため。
呼び出し側ごとにリセットを書くと、将来の新しい呼び出し側で同じ漏れが再発する)。

```
async function loadEnrollmentAssets(): Promise<void> {
    const generation = ++enrollmentGeneration;
    loadingEnrollmentAssets = true;
    enrollmentAssetsFailed = false;
    enrollmentStepUpBlocked = false;
    ...
```

`enrollmentStepUpRetried` (自動再開の上限) は**ここでは戻していない**。
戻すと 409 → 自動再開 → 409 → 自動再開 … が無限に回るため。
上限を戻せるのは人間の操作 (`retryEnrollmentAssets`) と enrollment の破棄
(`resetEnrollmentAssets`) だけである。

### [Warning] 上記の遷移テストが無い
**対応した。** `it('500 で取得失敗した後に再試行して 409 になったら取得失敗 Alert を残さない')`
を追加した。500 → `enrollment-assets-error` 表示 → 再試行 → 409 かつ status 500 で
`enrollment-step-up-blocked` へ遷移し、`enrollment-assets-error` が**消えている**ことを固定する。

### [Suggestion] "passkey-only" が Factory 既定への暗黙依存
**対応した。** `forceFill(['password' => null])` で password を実際に外し、
`expect($member->password)->toBeNull()` と `socialAccounts()->count() === 0` を
テスト内で明示固定した。

### [未確認] `composer test:browser` の結果 / devnotes 実測ログの実在
下の「全検証レーンの実測」に記載。実測ログは
`devnotes/20260807-2127-todo-T124/impl-step2-fail-observation.md` (Step A) と
`devnotes/20260807-2127-todo-T124/mutation-evidence.md` (Step C m1〜m8 + main 取り込み後の再実行)
に実在する。

## 外部要因: main の取り込みで設計の前提が 1 つ失効した (重点的に見てほしい)

実装セッションの中断中に main へ **T125 (inline throttle の 6 named レーン分離)** が
マージされた。これにより、設計書が施策 4 の順序 (precheck を enable の前段に置く) の
根拠として書いていた

> inline throttle の共有 bucket (同一 actor の全 inline route が 1 bucket) の残量が
> 最大の時点で recent-auth.password (max 6 = 最小) を通す

という前提が**失効した**。現在は
`two-factor.enable/confirm/disable/regenerate` = `two-factor-manage` (10/min)、
`recent-auth.password` = `password-verify` (6/min) で**別 bucket**である。

さらに実測 (throwaway テストで `X-RateLimit-Remaining` を観測) で、
`ThrottleRequests` は middleware priority により `RequireRecentAuth` より**先**に走ることを
確認した (鮮度切れの GET でも残数が減る。html は 302 / json は 409 を返しつつ枠は消費する)。
したがって「precheck が throttle 枠を守る」という説明も主従が逆だった。

**判断**: 施策 4 の**実装 (順序) は変えていない**。変えたのは根拠記述だけで、
固定したい本命を「409 の全画面遷移で enrollment 途中の画面状態 (QR / セットアップキー /
入力中コード) を失わないこと」に置き直した。precheck 無しで POST すると
`registerRecentAuthRedirectHandler` が confirm 画面へ全画面遷移するためである。
この書き換えは `resources/js/pages/Settings/Security.svelte` の `enableTwoFactor` docblock と
`docs/architecture.md` §2FA 面の step-up 契約 §クライアント側 に入っている。

**この判断が妥当か、また記述が実測と食い違っていないかを確認してほしい。**

## 同じく main 取り込みに伴う波及: AuthThrottleCoverageTest

設計は「2FA 秘密 GET レーン検査は recent-auth 追加で 409/302 になり 429 の観測ができなくなる」
と予測し、fresh session の付与を**計画された波及変更**としていた。
実測では throttle が先に走るため**テストは変更なしでも green だった**。

しかしそれは「302 を数えて 429 に到達している」状態であり、
秘密 GET が壊れても緑のままになる (検査意図が薄れる) ため、設計どおり
3 テストへ `$this->withSession(freshRecentAuthSession());` を 1 行ずつ足した。
**閾値・limiter 名・アサーションは 1 文字も変えていない。**

併せて、テスト名と本文にあった「認証強度は後続 TODO B2 (recent-auth 化) の担当」という
記述が T124 の完了で事実と食い違うため、指し先を T124 側の gate 群へ更新した
(「本テストが green でも秘密の保護が済んだことは意味しない」という誤読防止の主旨は保持)。

## 全検証レーンの実測 (すべてこの worktree で実行済み)

| レーン | 結果 |
|---|---|
| `composer test` | passed 3783 / skipped 2 / tests 3785 / assertions 15244 |
| `composer phpstan` (level 10) | No errors (812 files) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | passed (eslint) |
| `pnpm typecheck` | passed (tsc --noEmit) |
| `pnpm test` | 126 files / 1258 tests passed |
| `pnpm build` | built |
| `pnpm typecheck:packages` / `build:packages` / `test:packages` | passed / passed / 10 files 106 tests passed |
| `composer test:browser` | chromium 11 passed 3 skipped / webkit 11 passed 3 skipped |

mutation (m1〜m8) は main 取り込み**後**に全件再実行し、8 件すべて期待どおり fail することと
revert 残置ゼロを確認済み (`mutation-evidence.md` の末尾に追記)。

## レビュー観点 (Round 1 と同じ)

- 使命・禁止事項・セキュリティ不変条件に反していないか
- テストが**空振り green** になっていないか (負のコントロールの有無)
- 保証範囲を**誇張**した記述が無いか (実測と食い違う説明が残っていないか)
- 後方互換の並走 / 死んだコード / 二重管理が生まれていないか

最後に **APPROVED** または **CHANGES_REQUESTED** で締めること。

---

## 現在の差分 (git diff HEAD -- app/ resources/ tests/ routes/ config/ bootstrap/ docs/ AGENTS.md)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 319ce21..e9e72ae 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -391,3 +391,32 @@ ## ドメイン固有規約
    分類は**観測のためであり制御フローを変えない**。`unknown` は「写像表に一致が無かった」
    ことを意味し、写像表の値としては禁止。詳細と運用契約は
    `docs/architecture.md` §オートリチャージの失敗分類。
+8. **2FA 面の step-up (recent-auth) 規約**: route 名に `two-factor` を含む route は
+   **recent-auth 系 middleware をちょうど 1 種類持つ**か、`TwoFactorStepUpExemption` +
+   30 文字以上の根拠付きで exemption inventory へ登録する
+   (`TwoFactorStepUpInventoryTest` が deny-by-default で強制。母集団は **exact-fit**)。
+   - 「1 種類」は `recent-auth` (無条件) と `recent-auth.on-email-change` (条件付き) の
+     **同居**を禁じる意味である。同一 alias の重複登録は `Router::uniqueMiddleware()` が
+     畳むため実行時に観測できず、検査対象にしていない (誇張しない)。
+   - **exemption にできない 6 本**が gate に名指しで固定されている:
+     (a) 秘密の開示 3 本 = `two-factor.qr-code` / `two-factor.secret-key` /
+     `two-factor.recovery-codes`、
+     (b) 第二要素の除去・差し替え 3 本 = `two-factor.enable` / `two-factor.disable` /
+     `two-factor.regenerate-recovery-codes`。
+     throttle (`two-factor-secret-read`) は**連続取得の回数上限**であって step-up の
+     代替ではない。
+   - (b) に `two-factor.enable` が入るのは、Fortify の `force=true` が seed とリカバリコードを
+     再生成する一方で `two_factor_confirmed_at` を触らないためである。開けたままにすると
+     **奪取セッションから永久ロックアウトを作れる**。
+   - 組織管理側の 2 本 (`organizations.members.two-factor.reset` /
+     `organizations.two-factor-requirement.update`) は目録の母集団には入るが
+     non-exemptible 名指しには入れない (脅威系統が違い、`RecentAuthRouteTest` の
+     allowlist が既に固定している)。
+   - **保証範囲を誇張しない**: セレクタは名前ベースであり、`mfa.*` 等の別名で
+     第二要素へ触る route には**沈黙する**。別名の route を足すときは inventory の
+     母集団設計も同時に見直すこと。
+   - step-up を新しい面に課すときは **satisfier の到達性**を必ず確認する。
+     2FA 必須組織のゲート (`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`) は
+     password / 再SSO / **passkey** の 3 satisfier をすべて通す (どれか 1 つでも欠けると
+     その手段しか持たないユーザーが詰む)。詳細は `docs/architecture.md`
+     §2FA 面の step-up (recent-auth) 契約。
diff --git a/app/Enums/Security/TwoFactorStepUpExemption.php b/app/Enums/Security/TwoFactorStepUpExemption.php
new file mode 100644
index 0000000..157d211
--- /dev/null
+++ b/app/Enums/Security/TwoFactorStepUpExemption.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 「route 名に `two-factor` を含む route が recent-auth (step-up) を持たないことが正しい」と
+ * 裁定された理由の分類。
+ *
+ * `tests/Architecture/TwoFactorStepUpInventoryTest.php` が deny-by-default で
+ * 「recent-auth を持つ」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
+ * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ *
+ * ★case は「route の識別子」ではなく「**免除してよい理由の型**」である
+ *   (ThrottleCoverageExemption と同じ流儀。1 route 1 case にすると enum が route 名の
+ *    写しになり、「同じ理由の免除が増えていないか」という目録の主目的が消える)。
+ *
+ * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
+ *   当てはまる case が無ければ、それは「recent-auth を貼るべき route」である。
+ */
+enum TwoFactorStepUpExemption: string
+{
+    /**
+     * 未認証 (guest) で到達する第二要素チャレンジ面。
+     *
+     * 適用条件 (すべて満たすこと):
+     *  - route middleware に `guest:` guard を持ち、認証済みでは到達できない
+     *  - session に認証主体が存在せず、**step-up の概念が定義不能**である
+     *  - その route 自体が第二要素の検証側 (satisfier) であり、
+     *    自分自身に step-up を要求すると構造的に詰む
+     */
+    case PreAuthChallengeSurface = 'pre_auth_challenge_surface';
+
+    /**
+     * 成立に「その場では生成できない秘密の所持証明」を要求する route。
+     *
+     * 適用条件 (すべて満たすこと):
+     *  - 成立条件が TOTP コード等の**所持証明**であり、session 保持だけでは成立しない
+     *  - 応答が秘密を**開示しない**
+     *  - 既存の第二要素を**除去・差し替えしない**
+     */
+    case ProofOfSecretPossessionRequired = 'proof_of_secret_possession_required';
+}
diff --git a/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php b/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
index 0b31ec5..bd2c378 100644
--- a/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
+++ b/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
@@ -54,6 +54,14 @@ final class RequireTwoFactorForEnforcedOrganizations
         'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
         'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
         'recent-auth.password' => 'password による step-up 完了',
+        // passkey による step-up (T124)。2FA 必須ゲート下の未準拠ユーザーは enrollment
+        // (two-factor.enable / qr-code / secret-key) に step-up を要求されるため、
+        // satisfier を password と再SSO だけに絞ると **passkey-only ユーザー**
+        // (password 未設定・SSO 未連携) が enrollment の入口で手段ゼロになり詰む。
+        // これらは satisfier 側であり、通すこと自体は 2FA ゲートの解除にならない
+        // (準拠判定は two_factor_confirmed_at のみが決める)。
+        'passkey.confirm-options' => 'passkey による step-up の challenge 発行',
+        'passkey.confirm' => 'passkey による step-up 完了',
         // {intent} は login/register/link/step-up 共用だが、認証済みユーザーの主用途は
         // step-up (SSO-only ユーザーの再認証)。link を許してもゲート解除にはならない
         'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 1810ce3..76884f2 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -58,15 +58,27 @@ class FortifyServiceProvider extends ServiceProvider
 {
     /**
      * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
-     * いずれも「確立済み第二要素の bypass / 除去」経路であり、通常セッション認証だけで
-     * 到達させない (姉妹操作: organizations.members.two-factor.reset /
+     * いずれも「第二要素の秘密の露出」または「確立済み第二要素の除去・差し替え」経路であり、
+     * 通常セッション認証だけで到達させない (姉妹操作: organizations.members.two-factor.reset /
      * settings.account.destroy 等と同基準)。
      * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
      * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
      *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
      *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
      *     self-disable が許可される非 enforced 組織のユーザー。
-     * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
+     * - qr-code / secret-key (GET, T124): TOTP seed そのものの露出。
+     *   Fortify の TwoFactorSecretKeyController は two_factor_secret を**復号して平文で返し**、
+     *   TwoFactorQrCodeController は otpauth:// URL (秘密を内包) を返す。どちらも
+     *   two_factor_confirmed_at を見ないため、**確立済み**第二要素の seed が読める。
+     *   throttle (two-factor-secret-read) は連続取得の回数上限であって step-up の代替ではない。
+     * - enable (POST, T124): Fortify の TwoFactorAuthenticationController は
+     *   `$request->boolean('force')` を EnableTwoFactorAuthentication へ渡す。
+     *   force=true は two_factor_secret と two_factor_recovery_codes を**再生成する一方で
+     *   two_factor_confirmed_at を触らない** (fortify v1.37.2 実査) ため、奪取セッションから
+     *   1 回叩くだけで「誰も知らない秘密で TOTP を要求し続ける」永久ロックアウトを作れる。
+     *   秘密の**読み出し**だけ塞いで**差し替え**を開けたままにしない。
+     * 付与漏れは RecentAuthRouteTest (allowlist) と TwoFactorStepUpInventoryTest
+     * (deny-by-default 目録) の 2 枚で検出する。
      *
      * @var list<string>
      */
@@ -74,6 +86,9 @@ class FortifyServiceProvider extends ServiceProvider
         'two-factor.recovery-codes',
         'two-factor.regenerate-recovery-codes',
         'two-factor.disable',
+        'two-factor.qr-code',
+        'two-factor.secret-key',
+        'two-factor.enable',
     ];
 
     /**
@@ -306,7 +321,8 @@ private function configureRateLimiters(): void
          *   AuthThrottleCoverageTest「認証は throttle より先に走る」が固定する。
          *
          * ★これは**連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
-         *   認証強度 (recent-auth 化) は aicue:T120 の後続 TODO B2 の担当。
+         *   認証強度は T124 で別途 recent-auth を後付けした (RECENT_AUTH_ROUTE_NAMES)。
+         *   この 2 つは役割が違うので、片方を理由にもう片方を外さないこと。
          */
         RateLimiter::for('two-factor-secret-read', fn (Request $request): Limit => Limit::perMinute(10)
             ->by(RateLimiterKeys::actorOrIp($request, 'two-factor-secret-read')));
diff --git a/docs/architecture.md b/docs/architecture.md
index 3336b46..c095ba6 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -932,3 +932,78 @@ ## 外部 fake 配線の不変条件 (T119)
   参照してよいのは配線点と fake storage signed route の受け口を含む 4 ファイルだけで、
   allowlist の件数はテストが固定している (増やすには理由コメントと併せて 2 箇所を触る摩擦がかかる)。
   **誤検出が出ても allowlist を足す方向へ倒さない** — それが gate の目的である。
+
+## 2FA 面の step-up (recent-auth) 契約 (T124)
+
+第二要素そのものを扱う面は、**セッション認証だけでは到達させない**。
+機械強制は 2 枚 (`RecentAuthRouteTest` の allowlist + `TwoFactorStepUpInventoryTest` の
+deny-by-default 目録) で、判定述語は `Tests\Support\Security\RecentAuthMiddleware` に
+単一化してある (2 つの gate が別々に堅牢化されてドリフトするのを防ぐ)。
+
+### 何を守るか
+
+| 系統 | route | 開けたままにすると |
+|---|---|---|
+| (a) 秘密の開示 | `two-factor.qr-code` / `two-factor.secret-key` / `two-factor.recovery-codes` | 奪取セッションから TOTP seed を読み出して**第二要素を複製**できる (以後ログインが素通し) |
+| (b) 第二要素の除去・差し替え | `two-factor.enable` / `two-factor.disable` / `two-factor.regenerate-recovery-codes` | 正規ユーザーを**締め出せる** |
+
+(b) に `two-factor.enable` が入るのは、Fortify の `TwoFactorAuthenticationController` が
+`$request->boolean('force')` をそのまま `EnableTwoFactorAuthentication` へ渡し、
+**force=true が `two_factor_secret` と `two_factor_recovery_codes` を再生成する一方で
+`two_factor_confirmed_at` を触らない**ためである (fortify v1.37.2 実査)。
+奪取セッションから 1 回叩くだけで「誰も知らない秘密で TOTP を要求し続ける」
+**永久ロックアウト**が成立する。秘密の読み出しだけ塞いで差し替えを開けたままにしない。
+
+throttle (`two-factor-secret-read`) は**連続取得の回数上限**であって step-up の代替ではない。
+
+### 目録の契約 (`TwoFactorStepUpInventoryTest`)
+
+- 母集団は **route 名に `two-factor` を含む全 route** で、件数は **exact fit** (現在 11 本)。
+  vendor が 1 本足しても必ず差分として現れ、分類を強制できる。
+- 各 route は **recent-auth 系 middleware をちょうど 1 種類**持つか、
+  `App\Enums\Security\TwoFactorStepUpExemption` + 30 文字以上の根拠で免除登録する。
+  「1 種類」は `recent-auth` (無条件) と `recent-auth.on-email-change` (条件付き) の
+  **同居**を禁じる意味である。同一 alias の重複登録は `Router::uniqueMiddleware()` が畳むため
+  **実行時に観測できず**、検査対象にしていない (誇張しない)。
+- 上の表の **6 本は exemption にできない** (名指しで固定)。免除側へ移されたら fail する。
+- 免除は現在 3 件 (`two-factor.login` / `two-factor.login.store` = 未認証チャレンジ面、
+  `two-factor.confirm` = TOTP の所持証明が前提で秘密を開示しない) で、全体 cap と
+  case 別 cap の両方が exact fit。
+- 組織管理側の 2 本 (`organizations.members.two-factor.reset` /
+  `organizations.two-factor-requirement.update`) は母集団には入るが non-exemptible 名指しには
+  入れない (脅威系統が違い、`RecentAuthRouteTest` の allowlist が既に固定している)。
+- **保証範囲を誇張しない**: セレクタは名前ベースであり、`mfa.*` 等の別名で第二要素へ触る
+  route には**沈黙する**。別名の route を足すときは母集団設計も同時に見直すこと。
+
+### satisfier の到達性 (詰みを作らない側の契約)
+
+step-up を新しい面に課したら、**その面へ到達する前に step-up を満たせる手段**が
+必ず 1 つ以上あることを確認する。2FA 必須組織のゲート
+(`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`) は
+password (`recent-auth.password`) / 再SSO (`social.redirect` • `social.callback`) /
+**passkey** (`passkey.confirm-options` • `passkey.confirm`) の 3 satisfier をすべて通す。
+どれか 1 つでも欠けると、その手段しか持たないユーザーが enrollment の入口で手段ゼロになり詰む。
+`passkey.registration-options` / `passkey.store` / `passkey.destroy` は credential 集合を
+増減させる**管理**経路であり satisfier ではないので、allowlist に入れない
+(`TwoFactorEnforcementTest` の負のコントロールが固定)。
+
+### クライアント側 (enrollment 動線)
+
+`resources/js/pages/Settings/Security.svelte` は
+**step-up を enrollment の最初の操作に固定する** (有効化ボタン → precheck → POST)。
+precheck 無しで POST すると Inertia mutation が 409 (`recent_auth_required`) を受け、
+単一ハンドラ (`registerRecentAuthRedirectHandler`) が confirm 画面へ**全画面遷移**するため、
+enrollment 途中の画面状態 (QR / セットアップキー / 入力中コード) が失われる。
+precheck ならモーダルで完結し、成立後に同じ操作を再開できる。
+
+throttle の**巻き添え**は論点ではない (T125 でレーン分離済み。`two-factor-manage` 10/min と
+`password-verify` 6/min は別 bucket なので、2FA 操作の連打で再認証が 429 になる
+inline 時代の構造は残っていない)。ただし `ThrottleRequests` は middleware priority により
+`RequireRecentAuth` より**先**に走るため、鮮度切れの試行もレーンの枠を消費する
+(実測: 鮮度切れの GET でも `X-RateLimit-Remaining` が減る)。precheck はその無駄も避けるが、
+固定したい本命は画面状態を失わないことである。
+
+素材 (QR / セットアップキー) の 409 は「取得失敗」とは**別事象**として扱い、
+自動再開は 1 enrollment につき 1 回に制限する。status が取れない (delegated) ときは
+**再取得しない** — 再取得すると 409 → status 失敗 → 再取得 の無限ループになるため、
+`enrollment-step-up-blocked` の Alert と再認証ページ導線を出して**人間の操作**を待つ。
diff --git a/resources/js/lib/recent-auth.ts b/resources/js/lib/recent-auth.ts
index 6f2dfe4..10c0250 100644
--- a/resources/js/lib/recent-auth.ts
+++ b/resources/js/lib/recent-auth.ts
@@ -130,7 +130,24 @@ export async function withRecentAuth(handlers: {
 /** RecentAuthRequiredDto::CODE と対 (code 厳格一致で自分宛て応答のみ処理する) */
 const RECENT_AUTH_REQUIRED_CODE = "recent_auth_required";
 /** 遷移を許す唯一の着地 (サーバ由来 URL を無検証でグローバル遷移に使わない) */
-const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";
+export const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";
+
+/**
+ * XHR 応答が recent-auth の 409 契約か。**status だけでは判定しない**。
+ *
+ * 同じ 409 を `RequireTwoFactorForEnforcedOrganizations` も返す
+ * (`code: "two_factor_required"`) ため、status のみの判定は**誤食する**。
+ * body の形状は信用せず unknown から絞り込む型ガードにする
+ * (parseRecentAuthStatus と同じ流儀)。
+ *
+ * Inertia visit 側の判定 (recentAuthRedirectTarget) と同じ定数を共有し、
+ * 判定点を 2 つ作らない。
+ */
+export function isRecentAuthRequiredPayload(status: number, body: unknown): boolean {
+    if (status !== 409) return false;
+    if (typeof body !== "object" || body === null) return false;
+    return (body as Record<string, unknown>).code === RECENT_AUTH_REQUIRED_CODE;
+}
 
 /**
  * `httpException` の `event.detail.response` (Inertia core の `HttpExceptionResponse` =
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index c10c8d9..d260ac9 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -19,7 +19,12 @@
     import { Settings } from "@lucide/svelte";
     import { useForm } from "@inertiajs/svelte";
     import type { PasskeyListItem } from "@/lib/passkeys";
-    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
+    import {
+        withRecentAuth,
+        isRecentAuthRequiredPayload,
+        RECENT_AUTH_CONFIRM_PATH,
+        type RecentAuthStatus,
+    } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
     import { providerLabel } from "@/lib/social";
     import { addToast } from "@/lib/stores/toast";
@@ -72,8 +77,20 @@
      * precheck の結果 (fresh / stale / delegated) を **返す**。
      * PasskeySection は precheck 区間 (`/recent-auth/status` の待ち時間) を自前の loading で
      * 覆う必要があるため戻り値を待つ。結果に関心が無い呼び出し側は `void` で明示的に捨てる。
+     *
+     * ★`onDelegated` を **optional 第 2 引数**として受ける (T124)。
+     *   `withRecentAuth` は status 取得失敗時に `onDelegated ?? onFresh` を呼ぶため、
+     *   未指定だと「action をそのまま実行してサーバの最終ゲートに委ねる」挙動になる。
+     *   これは「1 回きりの mutation」なら正しいが、**409 を受けて自分を再実行する
+     *   呼び出し側では無限ループになる** (409 → status 失敗 → 再取得 → 409 …)。
+     *   そういう呼び出し側は必ず onDelegated を渡すこと。
+     *   既存 4 呼び出し側 (recovery codes 表示 / 再生成 / passkey guard / disable) は
+     *   無指定のままで挙動不変。
      */
-    function guardWithRecentAuth(action: () => void): Promise<"fresh" | "stale" | "delegated"> {
+    function guardWithRecentAuth(
+        action: () => void,
+        onDelegated?: () => void,
+    ): Promise<"fresh" | "stale" | "delegated"> {
         return withRecentAuth({
             onFresh: action,
             onStale: (status) => {
@@ -81,6 +98,7 @@
                 pendingAction = action;
                 recentAuthOpen = true;
             },
+            onDelegated,
         });
     }
 
@@ -151,13 +169,40 @@
         return typeof value === "string" && value.trim() !== "" ? value : null;
     }
 
-    /** 単一 endpoint から文字列 field を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null)。
-        表示文言も再試行導線も同一のため種別は区別しない。秘密が絡む経路なので console にも出さない。 */
-    async function fetchStringField(url: string, key: string): Promise<string | null> {
+    /**
+     * enrollment 素材 1 本の取得結果。
+     * `recentAuthRequired` は「取得失敗」とは**別事象**として上位へ返す
+     * (409 を「取得失敗」に畳むと、原因と対処が一致しない表示になり再試行が無限に失敗する)。
+     */
+    interface EnrollmentField {
+        value: string | null;
+        recentAuthRequired: boolean;
+    }
+
+    /**
+     * enrollment 素材の単一 endpoint を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null)。
+     * 表示文言も再試行導線も同一のため種別は区別しない。秘密が絡む経路なので console にも出さない。
+     *
+     * ★`Accept: application/json` は**必須**。これが無いと RequireRecentAuth の
+     *   expectsJson() が偽になり 302 が返って fetch がリダイレクトを追従するため、
+     *   409 判定が一度も成立しない (サーバ側 Feature テストが同じヘッダ条件で固定している)。
+     */
+    async function fetchEnrollmentField(url: string, key: string): Promise<EnrollmentField> {
         try {
-            return readStringField(await fetchJson<unknown>(url), key);
+            const response = await fetch(url, { headers: { Accept: "application/json" } });
+            if (!response.ok) {
+                const body: unknown = await response.json().catch(() => null);
+                return {
+                    value: null,
+                    recentAuthRequired: isRecentAuthRequiredPayload(response.status, body),
+                };
+            }
+            return {
+                value: readStringField(await response.json(), key),
+                recentAuthRequired: false,
+            };
         } catch {
-            return null;
+            return { value: null, recentAuthRequired: false };
         }
     }
 
@@ -170,30 +215,90 @@
      */
     let enrollmentGeneration = 0;
 
+    /**
+     * step-up を要求されたが状態を確認できず、モーダルを出せなかった状態。
+     * 「取得失敗」とは別事象なので別の状態・別の文言・別の導線で出す。
+     */
+    let enrollmentStepUpBlocked = $state(false);
+    /**
+     * 自動再開を 1 enrollment につき 1 回に制限するフラグ。
+     * サーバの鮮度判定が status と 409 で食い違う異常時でも必ず停止させるための上限であり、
+     * **ループを切るのは常に人間の操作**にする (再試行ボタンがこのフラグを戻す)。
+     */
+    let enrollmentStepUpRetried = false;
+
     /**
      * enrollment 素材 (QR + 手動セットアップキー) を取得する。
      * 2 つは独立に扱い、片方が取れれば enrollment を続行できる。
      * 両方失敗したときだけ「取得失敗 (再試行可)」として提示する。
+     *
+     * ★409 (step-up 要求) の集約はここ 1 箇所。個別 fetch から guardWithRecentAuth を呼ばない
+     *   (QR と secret-key は同一 session の同一鮮度判定なので**両方 409 になるのが通常**であり、
+     *    個別に呼ぶとモーダル 2 重起動と pendingAction 上書きが常時発生する)。
      */
     async function loadEnrollmentAssets(): Promise<void> {
         const generation = ++enrollmentGeneration;
         loadingEnrollmentAssets = true;
+        /*
+         * 前回の**結果表示**をここで一度に捨てる (取得結果に依らない単一の初期化点)。
+         * これが無いと 500 で取得失敗 → 再試行 → 409 の順に遷移したとき、409 分岐は
+         * enrollmentAssetsFailed を触らないため「再認証が必要です」と
+         * 「設定情報を取得できませんでした」が同時に出る (原因と対処が食い違う表示になる)。
+         * ★enrollmentStepUpRetried (自動再開の上限) はここでは戻さない。
+         *   戻すと 409 → 自動再開 → 409 → 自動再開 … が無限に回る。
+         *   上限を戻せるのは人間の操作 (retryEnrollmentAssets) と enrollment の破棄だけ。
+         */
+        enrollmentAssetsFailed = false;
+        enrollmentStepUpBlocked = false;
 
         const [qr, secret] = await Promise.all([
-            fetchStringField("/user/two-factor-qr-code", "svg"),
-            fetchStringField("/user/two-factor-secret-key", "secretKey"),
+            fetchEnrollmentField("/user/two-factor-qr-code", "svg"),
+            fetchEnrollmentField("/user/two-factor-secret-key", "secretKey"),
         ]);
 
         // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
         // (finally で戻すと古い run が新しい run の loading を消してしまう)
         if (generation !== enrollmentGeneration) return;
 
-        qrSvg = qr;
-        setupKey = secret;
-        enrollmentAssetsFailed = qr === null && secret === null;
+        // 鮮度切れは「取得失敗」ではない。再認証モーダルを 1 回だけ開き、成立後に同じ取得を再開する
+        if (qr.recentAuthRequired || secret.recentAuthRequired) {
+            loadingEnrollmentAssets = false;
+
+            // 自動再開の上限。ここを超えたら人間の操作 (再試行ボタン) を待つ
+            if (enrollmentStepUpRetried) {
+                enrollmentStepUpBlocked = true;
+                return;
+            }
+            enrollmentStepUpRetried = true;
+
+            void guardWithRecentAuth(
+                () => void loadEnrollmentAssets(),
+                // status 取得失敗 (delegated)。**再取得しない** (ここで再取得すると
+                // 409 → status 失敗 → 再取得 の無限ループになる)。
+                () => {
+                    enrollmentStepUpBlocked = true;
+                },
+            );
+
+            return;
+        }
+
+        qrSvg = qr.value;
+        setupKey = secret.value;
+        enrollmentAssetsFailed = qr.value === null && secret.value === null;
         loadingEnrollmentAssets = false;
     }
 
+    /**
+     * 手動再試行 (取得失敗 Alert / step-up 不能 Alert の両方から呼ぶ)。
+     * **自動再開の上限を戻すのはここだけ** (ループを切るのは常に人間の操作)。
+     * 結果表示のリセットは loadEnrollmentAssets() 側の単一初期化点が行う。
+     */
+    function retryEnrollmentAssets(): void {
+        enrollmentStepUpRetried = false;
+        void loadEnrollmentAssets();
+    }
+
     /**
      * enrollment 素材を画面から破棄する (開始時 / confirm 成功時 / 無効化成功時に呼ぶ)。
      * 世代を進めることで、進行中の取得結果が後から再格納されるのを防ぐ。
@@ -204,6 +309,8 @@
         qrSvg = null;
         setupKey = null;
         enrollmentAssetsFailed = false;
+        enrollmentStepUpBlocked = false;
+        enrollmentStepUpRetried = false;
         loadingEnrollmentAssets = false;
     }
 
@@ -292,26 +399,46 @@
         });
     }
 
+    /**
+     * 有効化開始。POST /user/two-factor-authentication は recent-auth 必須になった (T124) ため
+     * precheck を前段に置く。
+     * ★順序が重要: step-up は enrollment の**最初**の操作にする。precheck 無しで POST すると
+     *   Inertia mutation が 409 (`recent_auth_required`) を受け、単一ハンドラ
+     *   (registerRecentAuthRedirectHandler) が confirm 画面へ**全画面遷移**する。
+     *   enrollment の途中でこれが起きると画面状態 (QR / セットアップキー / 入力中コード) が
+     *   失われ、戻ってきた利用者は最初からやり直しになる。precheck ならモーダルで完結し、
+     *   成立後に同じ操作を再開できる (既存 3 呼び出し側と同じ流儀)。
+     * ★throttle の**巻き添え**はもう論点ではない (T125 でレーン分離済み)。
+     *   two-factor.enable/confirm は `two-factor-manage` (10/min)、
+     *   recent-auth.password は `password-verify` (6/min) で別 bucket なので、
+     *   2FA 操作の連打で再認証が 429 になる旧構造 (inline の 1 bucket 共有) は無い。
+     *   ただし ThrottleRequests は middleware priority により RequireRecentAuth より
+     *   **先**に走る (実測: 鮮度切れの GET でも X-RateLimit-Remaining が減る)。
+     *   つまり precheck 無しの POST は「成立し得ない試行」で two-factor-manage の枠を
+     *   削る。precheck はそれも避けるが、固定したい本命は**画面状態を失わないこと**である。
+     */
     function enableTwoFactor(): void {
-        // 再試行時に前回の素材・エラーを持ち越さない
-        resetEnrollmentAssets();
-        router.post(
-            "/user/two-factor-authentication",
-            {},
-            {
-                preserveScroll: true,
-                onStart: () => {
-                    enabling = true;
-                },
-                onSuccess: () => {
-                    confirming = true;
-                    void loadEnrollmentAssets();
-                },
-                onFinish: () => {
-                    enabling = false;
+        void guardWithRecentAuth(() => {
+            // 再試行時に前回の素材・エラーを持ち越さない
+            resetEnrollmentAssets();
+            router.post(
+                "/user/two-factor-authentication",
+                {},
+                {
+                    preserveScroll: true,
+                    onStart: () => {
+                        enabling = true;
+                    },
+                    onSuccess: () => {
+                        confirming = true;
+                        void loadEnrollmentAssets();
+                    },
+                    onFinish: () => {
+                        enabling = false;
+                    },
                 },
-            },
-        );
+            );
+        });
     }
 
     function confirmTwoFactor(event: SubmitEvent): void {
@@ -458,6 +585,28 @@
                             >
                                 認証アプリ設定用の情報を読み込んでいます…
                             </p>
+                        {:else if enrollmentStepUpBlocked}
+                            <!-- step-up を要求されたが状態を確認できずモーダルを出せなかった。
+                                 「取得失敗」とは別事象なので別文言・別導線で受ける (行き先のない詰みを作らない) -->
+                            <Alert
+                                type="warning"
+                                title="再認証が必要です"
+                                testId="enrollment-step-up-blocked"
+                            >
+                                2 要素認証の設定情報を表示するには再認証が必要です。
+                                <TextLink href={RECENT_AUTH_CONFIRM_PATH}>再認証ページ</TextLink>
+                                で本人確認を済ませてから、もう一度お試しください。
+                                {#snippet action()}
+                                    <Button
+                                        variant="ghost"
+                                        onclick={retryEnrollmentAssets}
+                                        loading={loadingEnrollmentAssets}
+                                        testId="retry-enrollment-step-up-button"
+                                    >
+                                        再試行
+                                    </Button>
+                                {/snippet}
+                            </Alert>
                         {:else if enrollmentAssetsFailed}
                             <Alert
                                 type="danger"
@@ -468,7 +617,7 @@
                                 {#snippet action()}
                                     <Button
                                         variant="ghost"
-                                        onclick={() => void loadEnrollmentAssets()}
+                                        onclick={retryEnrollmentAssets}
                                         loading={loadingEnrollmentAssets}
                                         testId="retry-enrollment-assets-button"
                                     >
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index 7956649..001910e 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -4,11 +4,11 @@
 
 use App\Http\Controllers\Auth\ConfirmRecentAuthController;
 use App\Http\Controllers\Auth\SocialAuthController;
-use App\Http\Middleware\RequireRecentAuth;
 use App\Listeners\Auth\StampRecentAuthOnLogin;
 use App\Listeners\Auth\StampRecentAuthOnPasskeyVerified;
 use Illuminate\Routing\Route as RoutingRoute;
 use Illuminate\Routing\Router;
+use Tests\Support\Security\RecentAuthMiddleware;
 
 /*
  * 機微操作 route に recent-auth middleware が付与されていることを CI で担保する (付与漏れ検出)。
@@ -42,6 +42,11 @@ function recentAuthRequiredRouteNames(): array
         'two-factor.regenerate-recovery-codes',
         // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
         'two-factor.disable',
+        // 2FA seed の露出 (T124)。qr-code は otpauth:// URL、secret-key は平文 seed を返す
+        'two-factor.qr-code',
+        'two-factor.secret-key',
+        // 2FA enrollment 開始 (T124)。force=true が seed とリカバリコードを差し替える
+        'two-factor.enable',
         // profile 更新 (email 変更時のみ条件付き step-up。配線は
         // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()。
         // routeHasRecentAuth は 'recent-auth.on-email-change' も str_starts_with で検出)
@@ -56,19 +61,14 @@ function recentAuthRequiredRouteNames(): array
     ];
 }
 
+/**
+ * 判定の実体は Tests\Support\Security\RecentAuthMiddleware に単一化してある (T124)。
+ * TwoFactorStepUpInventoryTest (deny-by-default 目録) と同じ述語を使い、
+ * 「一方は付いていると言い、他方は付いていないと言う」ドリフトを防ぐ。
+ */
 function routeHasRecentAuth(RoutingRoute $route): bool
 {
-    foreach ($route->gatherMiddleware() as $middleware) {
-        if (! is_string($middleware)) {
-            continue;
-        }
-        // alias 'recent-auth' / 'recent-auth:param' / 完全クラス名のいずれかを許容 (堅牢化)
-        if ($middleware === RequireRecentAuth::class || str_starts_with($middleware, 'recent-auth')) {
-            return true;
-        }
-    }
-
-    return false;
+    return RecentAuthMiddleware::isAttached($route);
 }
 
 test('機微操作 route 全件に recent-auth middleware が付与されている', function (): void {
diff --git a/tests/Architecture/TwoFactorStepUpInventoryTest.php b/tests/Architecture/TwoFactorStepUpInventoryTest.php
new file mode 100644
index 0000000..ad802b5
--- /dev/null
+++ b/tests/Architecture/TwoFactorStepUpInventoryTest.php
@@ -0,0 +1,290 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\TwoFactorStepUpExemption;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\Security\RecentAuthMiddleware;
+
+/*
+ * 2FA 面の step-up (recent-auth) 付与漏れ invariant (deny-by-default)。
+ *
+ * 「route 名に `two-factor` を含む route は recent-auth を持つか、
+ *   TwoFactorStepUpExemption + 30 文字以上の根拠で免除登録されている」を機械強制する。
+ *
+ * ★保証範囲 (誇張しない): セレクタは**名前ベース**である。`mfa.*` / `security.*` のような
+ *   別名で第二要素の状態に触る route を将来足した場合、本 gate は**沈黙する**。
+ *   別名で第二要素へ触る route を足すときは、この inventory の母集団設計も同時に見直すこと。
+ *   (命名規約そのものを強制する仕組みは意図的に作っていない = 過大)
+ *
+ * ★RecentAuthRouteTest (allowlist 型) との役割分担:
+ *   あちらは「機微操作の名指し表に付いているか」、こちらは「2FA 名前空間に未分類が無いか」。
+ *   判定述語は Tests\Support\Security\RecentAuthMiddleware に単一化してドリフトを防ぐ。
+ */
+
+/**
+ * 母集団セレクタ: route 名に `two-factor` を含む全 route。
+ *
+ * @return array<string, RoutingRoute>
+ */
+function twoFactorStepUpPopulation(): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+
+    /** @var array<string, RoutingRoute> $matched */
+    $matched = [];
+    foreach ($routes as $route) {
+        $name = $route->getName();
+        if (is_string($name) && str_contains($name, 'two-factor')) {
+            $matched[$name] = $route;   // route 名は一意
+        }
+    }
+    ksort($matched);
+
+    return $matched;
+}
+
+/**
+ * 母集団件数の **exact fit**。
+ * ★下限だけでは「セレクタが壊れて 0 件」は検出できても「Fortify が 1 本足した」を見逃す。
+ *   exact なら増減のどちらも必ず差分として現れ、分類の再検討を強制できる。
+ *   実測値 (php artisan route:list --json): Fortify 9 本 + アプリ 2 本 = 11 本。
+ */
+function twoFactorStepUpPopulationSize(): int
+{
+    return 11;
+}
+
+/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+function twoFactorStepUpReasonMinLength(): int
+{
+    return 30;
+}
+
+/** exemption 件数の上限。**現在値ちょうど** (exact fit)。上げる前に必ず再検討すること。 */
+function twoFactorStepUpExemptionCap(): int
+{
+    return 3;
+}
+
+/**
+ * case 別上限 (分類の偏り検出)。全体 cap とは役割が違うので array_sum で導出しない。
+ *
+ * @return array<string, int>
+ */
+function twoFactorStepUpExemptionCapByCase(): array
+{
+    return [
+        TwoFactorStepUpExemption::PreAuthChallengeSurface->value => 2,
+        // ★ここが膨らむ = 「秘密を開示する route を所持証明つきとして逃がした」疑い。
+        TwoFactorStepUpExemption::ProofOfSecretPossessionRequired->value => 1,
+    ];
+}
+
+/**
+ * **免除にできない route** の名指し固定。ここに載る route が exemption 側へ移されたり
+ * recent-auth を失ったら fail する (この gate の存在理由そのものを守る = 空振り防止の核)。
+ *
+ * 2 系統をまとめて持つ:
+ *  (a) 秘密の**開示** — 読めば第二要素を複製できる
+ *  (b) 第二要素の**除去・差し替え** — 書けば正規ユーザーを締め出せる
+ *
+ * ★組織管理側の 2 本 (organizations.members.two-factor.reset /
+ *   organizations.two-factor-requirement.update) は**入れない**。
+ *   脅威系統が違い (管理者操作であり Gate 認可が別途かかる)、
+ *   RecentAuthRouteTest の allowlist が既に名指しで固定しているため二重管理になる。
+ *
+ * @return list<string>
+ */
+function twoFactorNonExemptibleRoutes(): array
+{
+    return [
+        // (a) 秘密の開示
+        'two-factor.qr-code',        // otpauth:// URL (秘密を内包) と QR SVG
+        'two-factor.secret-key',     // 平文 TOTP seed
+        'two-factor.recovery-codes', // TOTP を伴わないログイン成立手段
+        // (b) 第二要素の除去・差し替え
+        'two-factor.enable',         // force=true が seed とリカバリコードを差し替える
+        'two-factor.disable',        // 第二要素そのものの除去
+        'two-factor.regenerate-recovery-codes', // bypass 手段の差し替え
+    ];
+}
+
+/**
+ * recent-auth を持たないことが正しいと裁定した route の inventory。
+ *
+ * @return array<string, array{TwoFactorStepUpExemption, string}>
+ */
+function twoFactorStepUpExemptions(): array
+{
+    $preAuth = TwoFactorStepUpExemption::PreAuthChallengeSurface;
+    $possession = TwoFactorStepUpExemption::ProofOfSecretPossessionRequired;
+
+    return [
+        'two-factor.login' => [$preAuth,
+            'guest:web guard 配下の未認証チャレンジ画面。session に認証主体が存在しないため '
+            .'step-up の鮮度判定 (recent_auth_at) が定義不能であり、ここに recent-auth を課すと '
+            .'ログインそのものが成立しなくなる。流量制限は fortify.limiters.two-factor が担当する。'],
+
+        'two-factor.login.store' => [$preAuth,
+            'two-factor.login と同一 URI の検証側。これ自体が第二要素の検証 = satisfier であり、'
+            .'自分自身に step-up を要求すると構造的に詰む。guest:web + throttle:two-factor で '
+            .'総当りは有界化されている。'],
+
+        'two-factor.confirm' => [$possession,
+            'enrollment の確認。成立には認証アプリが生成した TOTP コードの提示が必要で、'
+            .'session 保持だけでは成立しない (秘密の所持証明が前提)。応答は秘密を開示せず、'
+            .'既存の第二要素を除去も差し替えもしない (two_factor_confirmed_at を立てるだけ)。'
+            .'秘密の入手経路である qr-code / secret-key / enable 側に step-up を課してある。'],
+    ];
+}
+
+test('母集団が exact fit である (セレクタの空振り / vendor の route 追加を検出)', function (): void {
+    $population = twoFactorStepUpPopulation();
+    $expected = twoFactorStepUpPopulationSize();
+
+    expect(count($population))->toBe($expected,
+        '2FA route の母集団が '.count($population)."件 (期待 {$expected} 件) です。"
+        .'セレクタの空振り、または Fortify / アプリ側の route 増減が起きています。'
+        .'増えた route を分類してからこの数値を更新してください。'
+        .PHP_EOL.implode(PHP_EOL, array_keys($population)));
+});
+
+test('母集団の各 route は recent-auth 系 middleware をちょうど 1 種類持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
+    $inventory = twoFactorStepUpExemptions();
+    $violations = [];
+
+    foreach (twoFactorStepUpPopulation() as $name => $route) {
+        $count = RecentAuthMiddleware::countAttachedKinds($route);
+
+        if ($count === 1) {
+            continue;
+        }
+        if ($count === 0 && array_key_exists($name, $inventory)) {
+            continue;
+        }
+
+        $violations[] = $count === 0
+            ? "{$name}: recent-auth が無く exemption inventory にも未登録"
+            : "{$name}: 別種の recent-auth middleware が {$count} 本同居している"
+                .' (無条件 step-up と条件付き step-up の混在。契約は 1 種類ちょうど)';
+    }
+
+    expect($violations)->toBe([],
+        '2FA 面の step-up 付与が不正です。recent-auth を貼るか、貼らないことが正しい理由を '
+        .'twoFactorStepUpExemptions() に TwoFactorStepUpExemption + 具体的根拠付きで'
+        .'登録してください。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption inventory の key は現存する母集団 route (stale 検出)', function (): void {
+    $population = twoFactorStepUpPopulation();
+
+    $stale = [];
+    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
+        if (! array_key_exists($name, $population)) {
+            $stale[] = $name;
+        }
+    }
+
+    expect($stale)->toBe([],
+        'exemption inventory に現存しない route 名があります (削除/rename 済み): '.implode(', ', $stale));
+});
+
+test('exemption 登録された route は recent-auth を 1 本も持たない (死んだ exemption の検出)', function (): void {
+    $population = twoFactorStepUpPopulation();
+    $dead = [];
+
+    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
+        $route = $population[$name] ?? null;
+        if ($route instanceof RoutingRoute && RecentAuthMiddleware::isAttached($route)) {
+            $dead[] = $name;
+        }
+    }
+
+    expect($dead)->toBe([],
+        'recent-auth が付いているのに exemption が残っています (免除が形骸化しています)。'
+        .'inventory から削除してください: '.implode(', ', $dead));
+});
+
+test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
+    $minLength = twoFactorStepUpReasonMinLength();
+    $violations = [];
+
+    foreach (twoFactorStepUpExemptions() as $name => [$exemption, $reason]) {
+        if (! $exemption instanceof TwoFactorStepUpExemption) {
+            $violations[] = "{$name}: 第 1 要素が TwoFactorStepUpExemption ではありません";
+        }
+        if (mb_strlen($reason) < $minLength) {
+            $violations[] = "{$name}: 理由が {$minLength} 文字未満です";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption 件数が上限ちょうどを超えない (形骸化ガード)', function (): void {
+    $count = count(twoFactorStepUpExemptions());
+
+    expect($count)->toBeLessThanOrEqual(twoFactorStepUpExemptionCap(),
+        "exemption が {$count} 件あります。免除を増やす前に、その route に step-up を"
+        .'課せない構造的理由が本当にあるかを再検討してください。');
+});
+
+test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
+    $caps = twoFactorStepUpExemptionCapByCase();
+    $counts = [];
+
+    foreach (twoFactorStepUpExemptions() as [$exemption]) {
+        $counts[$exemption->value] = ($counts[$exemption->value] ?? 0) + 1;
+    }
+
+    $violations = [];
+
+    // 全 case が cap 表に載っていること (case 追加時の登録漏れ検出)。
+    // ★`expect()->toHaveKey($key, $value)` の第 2 引数は**期待値**であってメッセージではない
+    //   ため使わない (使うと cap 値と文言を比較して常に fail する)。
+    foreach (TwoFactorStepUpExemption::cases() as $case) {
+        if (! array_key_exists($case->value, $caps)) {
+            $violations[] = "case {$case->value} が cap 表に未登録です";
+        }
+    }
+
+    foreach ($counts as $value => $count) {
+        $cap = $caps[$value] ?? 0;
+        if ($count > $cap) {
+            $violations[] = "{$value}: {$count} 件 (上限 {$cap})";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('免除にできない route は必ず recent-auth 系 middleware をちょうど 1 種類持つ (免除側へ移されたら fail)', function (): void {
+    $population = twoFactorStepUpPopulation();
+    $inventory = twoFactorStepUpExemptions();
+    $violations = [];
+
+    foreach (twoFactorNonExemptibleRoutes() as $name) {
+        $route = $population[$name] ?? null;
+        if (! $route instanceof RoutingRoute) {
+            $violations[] = "{$name}: 母集団に存在しません (rename / 削除?)";
+
+            continue;
+        }
+        if (RecentAuthMiddleware::countAttachedKinds($route) !== 1) {
+            $violations[] = "{$name}: 秘密の開示 / 第二要素の差し替え経路なのに recent-auth 系 middleware が 1 種類ではありません";
+        }
+        if (array_key_exists($name, $inventory)) {
+            $violations[] = "{$name}: この route は exemption にできません";
+        }
+    }
+
+    expect($violations)->toBe([],
+        '秘密開示 / 第二要素差し替え route の step-up は免除できません (T124 の存在理由そのものです)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
diff --git a/tests/Feature/Auth/TwoFactorEnableStepUpTest.php b/tests/Feature/Auth/TwoFactorEnableStepUpTest.php
new file mode 100644
index 0000000..792763a
--- /dev/null
+++ b/tests/Feature/Auth/TwoFactorEnableStepUpTest.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\User;
+
+/*
+ * 2FA enrollment 開始 (POST /user/two-factor-authentication) の recent-auth (step-up) 配線 (T124)。
+ *
+ * ★この施策の中心的な脅威: Fortify の TwoFactorAuthenticationController は
+ *   `$request->boolean('force')` を EnableTwoFactorAuthentication へそのまま渡す。
+ *   force=true は two_factor_secret と two_factor_recovery_codes を**再生成する一方で
+ *   two_factor_confirmed_at を触らない**。つまり奪取セッションから 1 回叩くだけで
+ *   「誰も知らない秘密で TOTP を要求し続ける」= 正規ユーザーの永久ロックアウトが成立する。
+ *   秘密の**読み出し** (qr-code / secret-key) だけ塞いで**差し替え**を開けたままにしない。
+ */
+
+test('鮮度なしの POST enable (XHR) は 409 で two_factor_secret を作らない', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->postJson('/user/two-factor-authentication')
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+
+    $user->refresh();
+    expect($user->two_factor_secret)->toBeNull();
+});
+
+test('鮮度なしの POST enable force=true は確立済み seed とリカバリコードを差し替えない (ロックアウト回帰)', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+    $user->refresh();
+
+    // 前提の明示固定: Factory が confirmed_at を立てることに暗黙依存すると、
+    // Factory 変更で「**確立済み** 2FA に対する差し替え」というテストの意味が沈黙して薄れる。
+    expect($user->two_factor_confirmed_at)->not->toBeNull();
+
+    $beforeSecret = $user->two_factor_secret;
+    $beforeCodes = $user->two_factor_recovery_codes;
+    $beforeConfirmedAt = $user->two_factor_confirmed_at;
+
+    $this->actingAs($user)
+        ->postJson('/user/two-factor-authentication', ['force' => true])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+
+    $user->refresh();
+    expect($user->two_factor_secret)->toBe($beforeSecret);
+    expect($user->two_factor_recovery_codes)->toBe($beforeCodes);
+    expect($user->two_factor_confirmed_at?->toIso8601String())
+        ->toBe($beforeConfirmedAt?->toIso8601String());
+});
+
+test('鮮度なしの通常 POST enable は recent-auth confirm へ 302 する', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->post('/user/two-factor-authentication')
+        ->assertRedirect(route('recent-auth.confirm'));
+
+    $user->refresh();
+    expect($user->two_factor_secret)->toBeNull();
+});
+
+test('fresh なら force=true が seed を実際に差し替え、confirmed_at は触られない (負のコントロール)', function (): void {
+    // ★confirmed_at が不変であること自体が「誰も知らない秘密で TOTP を要求し続ける」
+    //   ロックアウトが成立する仕組みそのものである。この事実が変わったら設計の前提が
+    //   変わるのでテストで固定する。
+    $user = User::factory()->withTwoFactor()->create();
+    $user->refresh();
+
+    $beforeSecret = $user->two_factor_secret;
+    $beforeCodes = $user->two_factor_recovery_codes;
+    $beforeConfirmedAt = $user->two_factor_confirmed_at;
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->postJson('/user/two-factor-authentication', ['force' => true])
+        ->assertSuccessful();
+
+    $user->refresh();
+    expect($user->two_factor_secret)->not->toBe($beforeSecret);
+    expect($user->two_factor_recovery_codes)->not->toBe($beforeCodes);
+    expect($user->two_factor_confirmed_at?->toIso8601String())
+        ->toBe($beforeConfirmedAt?->toIso8601String());
+});
+
+test('2FA 必須組織の未準拠メンバーでも enable は 2FA ゲートに阻まれない (遮断理由は step-up 側だけ)', function (): void {
+    // RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES に two-factor.enable が
+    // 元から入っているため、recent-auth 追加後も遮断の理由が step-up 側だけであることを固定する。
+    [$organization] = createOrganizationWithOwner();
+    /** @var Organization $organization */
+    $organization->forceFill(['two_factor_required' => true])->save();
+
+    $member = attachOrganizationMember($organization);
+
+    $this->actingAs($member)
+        ->postJson('/user/two-factor-authentication')
+        ->assertStatus(409)
+        // two_factor_required ではないこと = 2FA ゲートではなく step-up が遮断している
+        ->assertJsonPath('code', 'recent_auth_required');
+});
diff --git a/tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php b/tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php
new file mode 100644
index 0000000..de4b42d
--- /dev/null
+++ b/tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use Laravel\Fortify\Fortify;
+
+/*
+ * 2FA seed を返す GET 2 本 (qr-code / secret-key) の recent-auth (step-up) 配線 (T124)。
+ *
+ * Fortify 登録ルートには FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が
+ * booted callback で recent-auth middleware を後付けする。ここではその実効性
+ * (stale で遮断 = 秘密を返さない / fresh で通過 = 秘密を返す) を HTTP 経由で検証する。
+ * 付与漏れ検出は RecentAuthRouteTest / TwoFactorStepUpInventoryTest (Architecture) 側。
+ *
+ * ★`Accept: application/json` を**実ヘッダで**指定する (getJson() ヘルパ任せにしない)。
+ *   フロント (Settings/Security.svelte) の素 fetch が同じ条件で 409 契約へ入ることの証拠にする。
+ */
+
+test('鮮度なしの GET QR コード (Accept: application/json) は 409 recent_auth_required で svg も url も返さない', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required')
+        ->assertJsonPath('redirect', route('recent-auth.confirm'))
+        ->assertJsonMissingPath('svg')
+        ->assertJsonMissingPath('url');
+});
+
+test('鮮度なしの GET セットアップキー (Accept: application/json) は 409 で secretKey を返さない', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required')
+        ->assertJsonMissingPath('secretKey');
+});
+
+test('鮮度なしの通常 GET (Accept: text/html) は recent-auth confirm へ 302 する', function (string $uri): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->get($uri, ['Accept' => 'text/html'])
+        ->assertRedirect(route('recent-auth.confirm'));
+})->with([
+    'qr-code' => ['/user/two-factor-qr-code'],
+    'secret-key' => ['/user/two-factor-secret-key'],
+]);
+
+test('fresh なら QR とセットアップキーが実際に秘密を返す (負のコントロール)', function (): void {
+    // 「常に失敗するから green」という空振りを排除する。遮断に意味があることの証拠。
+    $user = User::factory()->withTwoFactor()->create();
+    $user->refresh();
+
+    $secret = $user->two_factor_secret;
+    expect($secret)->toBeString();
+    $plainSecret = Fortify::currentEncrypter()->decrypt($secret);
+
+    $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])
+        ->assertOk()
+        ->assertJsonPath('svg', fn (mixed $svg): bool => is_string($svg) && $svg !== '');
+
+    $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
+        ->assertOk()
+        ->assertJsonPath('secretKey', $plainSecret);
+});
+
+test('409 応答には Cache-Control: no-store が付く (RequireRecentAuth 契約の回帰)', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $response = $this->actingAs($user)
+        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
+        ->assertStatus(409);
+
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+});
diff --git a/tests/Feature/Organizations/TwoFactorEnforcementTest.php b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
index 194d089..086265a 100644
--- a/tests/Feature/Organizations/TwoFactorEnforcementTest.php
+++ b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
@@ -6,6 +6,7 @@
 use App\Enums\SecurityEventType;
 use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
 use App\Models\Organization;
+use App\Models\Passkey;
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
 use App\Notifications\User\TwoFactorResetSecurityNotification;
@@ -232,6 +233,45 @@ function tfeResetUrl(Organization $organization, User $member): string
     }
 })->with(array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES));
 
+test('2FA 必須ゲート下の passkey-only ユーザーは passkey step-up の challenge を取得できる (T124)', function (): void {
+    // enrollment (two-factor.enable / qr-code / secret-key) に step-up が課された結果、
+    // satisfier の到達性が enrollment の前提になった。password / 再SSO / passkey の
+    // どれか 1 つでも allowlist から漏れると、その手段しか持たないユーザーが入口で詰む。
+    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
+    $member = tfeAddMember($organization, 'pending');
+    // 「passkey-only」をテスト名だけの主張にしない: password を実際に外す
+    // (users.password は SSO-only ユーザーのため nullable)。SSO 連携も張らないので
+    // このユーザーの step-up 手段は passkey 1 本だけになる。
+    $member->forceFill(['password' => null])->save();
+    Passkey::factory()->for($member)->create();
+
+    $member->refresh();
+    expect($member->password)->toBeNull();
+    expect($member->socialAccounts()->count())->toBe(0);
+
+    $response = $this->actingAs($member)->getJson('/passkeys/confirm/options');
+
+    // 本施策の直接の回帰: ゲートによる settings.security への redirect でないこと
+    expect($response->headers->get('Location'))->not->toBe(route('settings.security'));
+    // 期待値は vendor controller の正常契約から確定している:
+    // Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController::index() は
+    // response()->json(['options' => ...]) を返す = 200。
+    // (「allowlist は通ったが実用上は壊れている」空振りを排除する)
+    $response->assertOk()->assertJsonStructure(['options']);
+});
+
+test('allowlist 外の passkey 管理 route はゲート中に settings.security へ 302 (T124 の負のコントロール)', function (): void {
+    // 「passkey なら何でも通す」になっていないことの証拠。registration-options は
+    // credential を**増やす**管理経路であり satisfier ではない。
+    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
+    $member = tfeAddMember($organization, 'pending');
+
+    $this->actingAs($member)
+        ->withSession(freshRecentAuthSession())
+        ->get('/user/passkeys/options')
+        ->assertRedirect(route('settings.security'));
+});
+
 test('非許可 route の代表はゲート中必ず settings.security へ 302', function (string $path): void {
     [$organization] = tfeCreateOrganization(twoFactorRequired: true);
     $member = tfeAddMember($organization, 'disabled');
@@ -271,7 +311,11 @@ function tfeResetUrl(Organization $organization, User $member): string
     $this->get(route('settings.security'))->assertOk();
 
     // 3. Fortify 実 POST で enrollment 開始
-    $this->post('/user/two-factor-authentication')->assertRedirect();
+    //    T124: enable は step-up 必須になった (force=true の seed 差し替えによる永久ロックアウト対策)。
+    //    実運用ではログイン直後 15 分は StampRecentAuthOnLogin により fresh なので、
+    //    ここでも「step-up 済み相当」の session を与えて enrollment 本体の遷移を検証する。
+    $this->withSession(freshRecentAuthSession())
+        ->post('/user/two-factor-authentication')->assertRedirect();
     $secret = decrypt($member->fresh()->two_factor_secret);
     expect($secret)->toBeString();
 
diff --git a/tests/Feature/Security/AuthThrottleCoverageTest.php b/tests/Feature/Security/AuthThrottleCoverageTest.php
index 15e0af0..ab16652 100644
--- a/tests/Feature/Security/AuthThrottleCoverageTest.php
+++ b/tests/Feature/Security/AuthThrottleCoverageTest.php
@@ -319,6 +319,10 @@ function throttleProbeResolvedClasses(string $routeName): array
     //   inline へ戻す変更を入れたらこのテストが落ちる。
     $user = User::factory()->withTwoFactor()->create();
     $this->actingAs($user);
+    // T124: 秘密 GET は recent-auth 必須になった。step-up 済み相当の session を与えて
+    // **実際に秘密が返る通常経路**でレーンを数える (鮮度切れの 302 を数えても
+    // 「連続取得の上限」の観測にならない)。閾値・limiter 名・アサーションは変えない。
+    $this->withSession(freshRecentAuthSession());
 
     for ($i = 1; $i <= 10; $i++) {
         expect($this->get('/user/two-factor-qr-code')->getStatusCode())
@@ -335,13 +339,16 @@ function throttleProbeResolvedClasses(string $routeName): array
         ->not->toBe(429, '2FA 管理 POST が 2FA 秘密 GET の巻き添えで 429 になりました');
 });
 
-test('2FA 秘密 GET は 11 回目で 429 — これは連続取得の回数上限であって認証強度ではない (認証強度は後続 TODO B2)', function (): void {
+test('2FA 秘密 GET は 11 回目で 429 — これは連続取得の回数上限であって認証強度ではない (認証強度は T124 の recent-auth)', function (): void {
     // ★誤読防止: ここで固定しているのは「回数の上限」だけである。
-    //   qr-code / secret-key / recovery-codes を **step-up なしで読めること自体**の是非は
-    //   aicue:T120 の後続 TODO B2 (recent-auth 化) の担当であり、本テストが green でも
+    //   qr-code / secret-key / recovery-codes を **step-up なしで読めないこと**は
+    //   T124 の recent-auth 配線 (RecentAuthRouteTest / TwoFactorStepUpInventoryTest /
+    //   TwoFactorSecretReadStepUpTest) の担当であり、本テストが green でも
     //   「秘密の保護が済んだ」ことは 1 ミリも意味しない。
     $user = User::factory()->withTwoFactor()->create();
     $this->actingAs($user);
+    // T124: step-up 済み相当の session (上のテストと同じ理由)
+    $this->withSession(freshRecentAuthSession());
 
     for ($i = 1; $i <= 10; $i++) {
         expect($this->get('/user/two-factor-secret-key')->getStatusCode())
@@ -359,6 +366,8 @@ function throttleProbeResolvedClasses(string $routeName): array
     //   残数が連続しなくなりここで落ちる。
     $user = User::factory()->withTwoFactor()->create();
     $this->actingAs($user);
+    // T124: step-up 済み相当の session (上のテストと同じ理由)
+    $this->withSession(freshRecentAuthSession());
 
     $uris = ['/user/two-factor-qr-code', '/user/two-factor-secret-key', '/user/two-factor-recovery-codes'];
     $previous = null;
diff --git a/tests/Support/Security/RecentAuthMiddleware.php b/tests/Support/Security/RecentAuthMiddleware.php
new file mode 100644
index 0000000..ea3d480
--- /dev/null
+++ b/tests/Support/Security/RecentAuthMiddleware.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Security;
+
+use App\Http\Middleware\RequireRecentAuth;
+use Illuminate\Routing\Route as RoutingRoute;
+
+/**
+ * route に recent-auth (step-up) middleware が付いているかの**唯一の判定点**。
+ *
+ * RecentAuthRouteTest (allowlist 型) と TwoFactorStepUpInventoryTest (deny-by-default 目録型)
+ * の 2 つの gate が同じ述語を使う。判定を各テストに複製すると、片方だけ堅牢化されて
+ * 「一方は付いていると言い、他方は付いていないと言う」ドリフトが起きる。
+ */
+final class RecentAuthMiddleware
+{
+    /**
+     * 実効 middleware 列に含まれる recent-auth 系 entry の**種類数**を返す。
+     *
+     * ★数えるのは「種類」であって「登録回数」ではない (誇張しない):
+     *   `Route::gatherMiddleware()` は `Router::uniqueMiddleware()` を通すため、
+     *   **同一文字列**の二重登録は framework が畳んで 1 本になる (実査: Laravel 12
+     *   `Routing/Router::uniqueMiddleware()` が値をキーに `$seen` で除去)。
+     *   したがって同一 alias の重複は**実行時に観測できず、振る舞いにも差が出ない**。
+     *   観測できない差分に gate を置くのは偽陽性を生むだけなので、そこは検査しない。
+     *
+     * ★検査する価値があるのは **別種の recent-auth が同居する**状態である。
+     *   例: `recent-auth` (無条件 step-up) と `recent-auth.on-email-change` (条件付き) が
+     *   同一 route に付くと、意図が矛盾した二重ゲートになる (どちらが真の契約か読めない)。
+     *   これは別文字列なので dedup されず、ここで 2 と数えられる。
+     *
+     * 受理する entry を**厳密に**限定する (`recent-authentication` のような将来の別 alias を
+     * 巻き込んで数えないため):
+     *   - `recent-auth` 完全一致
+     *   - `recent-auth:` 前方一致 (パラメータ付き)
+     *   - `recent-auth.` 前方一致 (`recent-auth.on-email-change` 等の派生 alias)
+     *   - `RequireRecentAuth::class` 完全一致
+     */
+    public static function countAttachedKinds(RoutingRoute $route): int
+    {
+        $count = 0;
+
+        foreach ($route->gatherMiddleware() as $middleware) {
+            if (! is_string($middleware)) {
+                continue;
+            }
+            if ($middleware === RequireRecentAuth::class
+                || $middleware === 'recent-auth'
+                || str_starts_with($middleware, 'recent-auth:')
+                || str_starts_with($middleware, 'recent-auth.')) {
+                $count++;
+            }
+        }
+
+        return $count;
+    }
+
+    /** 1 種類以上付いているか (allowlist 型 gate 用の薄いラッパ。既存の意味を変えない)。 */
+    public static function isAttached(RoutingRoute $route): bool
+    {
+        return self::countAttachedKinds($route) > 0;
+    }
+}
diff --git a/tests/js/lib/recent-auth.test.ts b/tests/js/lib/recent-auth.test.ts
index dbdaed3..5e3003f 100644
--- a/tests/js/lib/recent-auth.test.ts
+++ b/tests/js/lib/recent-auth.test.ts
@@ -32,6 +32,7 @@ vi.mock("@/lib/stores/toast", async (importOriginal) => ({
 
 import {
     fetchRecentAuthStatus,
+    isRecentAuthRequiredPayload,
     parseRecentAuthStatus,
     registerRecentAuthRedirectHandler,
     withRecentAuth,
@@ -256,3 +257,47 @@ describe("registerRecentAuthRedirectHandler (409 の単一ハンドラ)", () =>
         expect(unsubscribe).toHaveBeenCalledTimes(1);
     });
 });
+
+describe("isRecentAuthRequiredPayload (409 契約の型ガード。T124)", () => {
+    /*
+     * status だけでは判定しない。同じ 409 を RequireTwoFactorForEnforcedOrganizations も
+     * 返す (code: "two_factor_required") ため、status のみの判定は誤食する。
+     */
+
+    it("409 + code=recent_auth_required を true と判定する", () => {
+        expect(
+            isRecentAuthRequiredPayload(409, {
+                code: "recent_auth_required",
+                message: "この操作には直近の再認証が必要です。",
+                redirect: "/recent-auth/confirm",
+            }),
+        ).toBe(true);
+    });
+
+    it("409 + code=two_factor_required を false と判定する (2FA 必須ゲートの 409 を誤食しない)", () => {
+        expect(
+            isRecentAuthRequiredPayload(409, {
+                code: "two_factor_required",
+                message: "組織は 2 段階認証を必須としています。",
+                redirect: "/settings/security",
+            }),
+        ).toBe(false);
+    });
+
+    it.each([200, 302, 422, 500])("status %i は code が一致しても false", (status) => {
+        expect(isRecentAuthRequiredPayload(status, { code: "recent_auth_required" })).toBe(false);
+    });
+
+    it.each([
+        ["null", null],
+        ["文字列 (非 JSON 応答)", "<html>error</html>"],
+        ["配列", []],
+        ["数値", 1],
+        ["undefined", undefined],
+        ["code 欠損", { message: "x" }],
+        ["code が非文字列", { code: 1 }],
+    ])("body が %s でも例外を投げず false", (_label, body) => {
+        expect(() => isRecentAuthRequiredPayload(409, body)).not.toThrow();
+        expect(isRecentAuthRequiredPayload(409, body)).toBe(false);
+    });
+});
diff --git a/tests/js/pages/SettingsSecurity.test.ts b/tests/js/pages/SettingsSecurity.test.ts
index 7b17240..1b0c3c6 100644
--- a/tests/js/pages/SettingsSecurity.test.ts
+++ b/tests/js/pages/SettingsSecurity.test.ts
@@ -395,3 +395,302 @@ describe("Settings/Security 2FA 無効化 (recent-auth precheck)", () => {
         expect(routerDeleteMock).toHaveBeenCalledTimes(1);
     });
 });
+
+/*
+ * T124: enrollment (有効化開始 + 素材取得) の step-up precheck と 409 再開。
+ *
+ * サーバ側で POST /user/two-factor-authentication と GET /user/two-factor-{qr-code,secret-key} が
+ * recent-auth 必須になったため、
+ *  (a) 有効化ボタンは precheck を通す (stale なら POST せずモーダル)
+ *  (b) 素材の 409 は「取得失敗」ではなく step-up 要求として扱い、1 回だけ自動再開する
+ *  (c) status が取れない (delegated) ときは **再取得しない** (409 → status 失敗 → 再取得 の
+ *      無限ループを構造的に不能にする)
+ * を固定する。
+ */
+
+/** enrollment 素材 1 本の応答指定 */
+type FieldStub = { kind: "ok"; body: unknown } | { kind: "error"; status: number; body: unknown };
+
+const RECENT_AUTH_409: FieldStub = {
+    kind: "error",
+    status: 409,
+    body: {
+        code: "recent_auth_required",
+        message: "この操作には直近の再認証が必要です。",
+        redirect: "/recent-auth/confirm",
+    },
+};
+
+interface EnrollmentStubState {
+    /** /recent-auth/status の応答 (null = HTTP 500 で status が取れない) */
+    recent: boolean | null;
+    qr: FieldStub;
+    secret: FieldStub;
+}
+
+function fieldResponse(stub: FieldStub): unknown {
+    return stub.kind === "ok"
+        ? jsonResponse(true, 200, stub.body)
+        : jsonResponse(false, stub.status, stub.body);
+}
+
+/**
+ * enrollment 用 fetch mock。**可変 state** を返し、テスト側が途中で応答を差し替えられる
+ * (mock 実装は state を毎回読むため、差し替えは即座に効く)。
+ */
+function stubEnrollmentFetch(initial: Partial<EnrollmentStubState> = {}): EnrollmentStubState {
+    const state: EnrollmentStubState = {
+        recent: true,
+        qr: { kind: "ok", body: { svg: "<svg></svg>" } },
+        secret: { kind: "ok", body: { secretKey: "SETUPKEY123" } },
+        ...initial,
+    };
+
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/recent-auth/status")) {
+            if (state.recent === null) {
+                return Promise.resolve(jsonResponse(false, 500, {}));
+            }
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent: state.recent,
+                    passwordSet: true,
+                    availableProviders: [],
+                    passkeyAvailable: false,
+                    canSatisfy: true,
+                    confirmedAt: state.recent ? 1 : null,
+                }),
+            );
+        }
+        if (url.includes("/recent-auth/password")) {
+            return Promise.resolve(jsonResponse(true, 204, null));
+        }
+        if (url.includes("/user/two-factor-qr-code")) {
+            return Promise.resolve(fieldResponse(state.qr));
+        }
+        if (url.includes("/user/two-factor-secret-key")) {
+            return Promise.resolve(fieldResponse(state.secret));
+        }
+        return Promise.resolve(jsonResponse(true, 200, []));
+    });
+
+    return state;
+}
+
+/** 指定 URL 片を含む fetch 呼び出し回数 */
+function fetchCallCount(fragment: string): number {
+    return fetchMock.mock.calls.filter((call) => String(call[0]).includes(fragment)).length;
+}
+
+/**
+ * 2FA 未設定状態で描画し、有効化 → enable POST 成功 (onSuccess) まで進めて enrollment に入る。
+ *
+ * ★有効化ボタン自身の precheck は **常に fresh** で通す (ここは素材取得側の検証が目的)。
+ *   呼び出し側が指定した `state.recent` は POST 成立後に復元し、
+ *   素材の 409 を受けた precheck から効かせる。
+ * ★onSuccess の直前に fetch 呼び出し履歴を消す (以降の回数が素材取得だけを数える)。
+ */
+async function enterEnrollment(state: EnrollmentStubState): Promise<void> {
+    const recentForAssets = state.recent;
+    state.recent = true;
+
+    setTwoFactor(false);
+    render(Security, { props: {} });
+
+    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+    await waitFor(() => {
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/user/two-factor-authentication",
+            {},
+            expect.objectContaining({ preserveScroll: true }),
+        );
+    });
+
+    state.recent = recentForAssets;
+    fetchMock.mockClear();
+    lastVisitOptions().onSuccess?.();
+}
+
+describe("Settings/Security 有効化開始の step-up precheck (T124)", () => {
+    it("stale なら再認証モーダルを開き、enable を POST しない", async () => {
+        stubEnrollmentFetch({ recent: false });
+        setTwoFactor(false);
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("fresh なら enable を POST する (負のコントロール)", async () => {
+        stubEnrollmentFetch({ recent: true });
+        setTwoFactor(false);
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalledWith(
+                "/user/two-factor-authentication",
+                {},
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
+    });
+});
+
+describe("Settings/Security enrollment 素材の 409 (step-up) 処理 (T124)", () => {
+    it("素材取得が両方 409 でも再認証モーダルの起動は 1 回だけ (取得失敗にも畳まない)", async () => {
+        const state = stubEnrollmentFetch({
+            recent: false,
+            qr: RECENT_AUTH_409,
+            secret: RECENT_AUTH_409,
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(screen.getAllByTestId("recent-auth-modal")).toHaveLength(1);
+        // status の再取得は 1 回だけ (409 を受けた precheck)
+        expect(fetchCallCount("/recent-auth/status")).toBe(1);
+        // 409 を「取得失敗」に畳んでいない
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+    });
+
+    it("片方だけ 409 でも再認証モーダルへ倒す (部分的鮮度切れの一貫性)", async () => {
+        const state = stubEnrollmentFetch({
+            recent: false,
+            qr: RECENT_AUTH_409,
+            secret: { kind: "ok", body: { secretKey: "SETUPKEY123" } },
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+    });
+
+    it("409 以外の失敗 (500) は従来どおり取得失敗 Alert を出し、モーダルを開かない", async () => {
+        // 通常エラーを step-up へ誤分類しないことの負のコントロール
+        const state = stubEnrollmentFetch({
+            qr: { kind: "error", status: 500, body: {} },
+            secret: { kind: "error", status: 500, body: {} },
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-assets-error")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
+        expect(screen.queryByTestId("enrollment-step-up-blocked")).toBeNull();
+    });
+
+    it("500 で取得失敗した後に再試行して 409 になったら取得失敗 Alert を残さない", async () => {
+        // ★状態の混在回帰 (Codex impl-review R1 [Warning])。
+        //   409 分岐が enrollmentAssetsFailed を触らないと「再認証が必要です」と
+        //   「設定情報を取得できませんでした」が同時に出て、原因と対処が食い違う。
+        const state = stubEnrollmentFetch({
+            qr: { kind: "error", status: 500, body: {} },
+            secret: { kind: "error", status: 500, body: {} },
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-assets-error")).toBeInTheDocument();
+        });
+
+        // 再試行したら今度は step-up 要求 (409)。status も取れない = blocked へ倒れる
+        state.recent = null;
+        state.qr = RECENT_AUTH_409;
+        state.secret = RECENT_AUTH_409;
+        await fireEvent.click(screen.getByTestId("retry-enrollment-assets-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-step-up-blocked")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+    });
+
+    it("素材が 409 かつ /recent-auth/status が 500 のとき再取得ループしない", async () => {
+        // ★delegated ループ回帰。この設計の中心的な安全性テスト。
+        const state = stubEnrollmentFetch({
+            recent: null,
+            qr: RECENT_AUTH_409,
+            secret: RECENT_AUTH_409,
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-step-up-blocked")).toBeInTheDocument();
+        });
+
+        // 素材 2 本 + status 1 本で停止する (4 回目以降が発火しない)
+        expect(fetchCallCount("/user/two-factor-qr-code")).toBe(1);
+        expect(fetchCallCount("/user/two-factor-secret-key")).toBe(1);
+        expect(fetchCallCount("/recent-auth/status")).toBe(1);
+        expect(fetchMock.mock.calls).toHaveLength(3);
+
+        // 追加の tick を与えても増えない (非同期ループの取りこぼし検出)
+        await new Promise((resolve) => setTimeout(resolve, 30));
+        expect(fetchMock.mock.calls).toHaveLength(3);
+
+        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
+    });
+
+    it("step-up 不能 Alert の再試行ボタンは自動再開の上限を戻して再取得する", async () => {
+        const state = stubEnrollmentFetch({
+            recent: null,
+            qr: RECENT_AUTH_409,
+            secret: RECENT_AUTH_409,
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-step-up-blocked")).toBeInTheDocument();
+        });
+
+        // 人間の操作でループを切る = 上限が「詰み」にならないことの確認
+        state.qr = { kind: "ok", body: { svg: "<svg></svg>" } };
+        state.secret = { kind: "ok", body: { secretKey: "RESUMEDKEY" } };
+        await fireEvent.click(screen.getByTestId("retry-enrollment-step-up-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("two-factor-setup-key")).toHaveTextContent("RESUMEDKEY");
+        });
+        expect(screen.queryByTestId("enrollment-step-up-blocked")).toBeNull();
+    });
+
+    it("再認証成立後に素材取得が再開され QR とセットアップキーが表示される", async () => {
+        const state = stubEnrollmentFetch({
+            recent: false,
+            qr: RECENT_AUTH_409,
+            secret: RECENT_AUTH_409,
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+
+        // 再認証が成立したので素材は返るようになる
+        state.qr = { kind: "ok", body: { svg: "<svg></svg>" } };
+        state.secret = { kind: "ok", body: { secretKey: "RESUMEDKEY" } };
+
+        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
+            target: { value: "current-password" },
+        });
+        await fireEvent.click(screen.getByTestId("recent-auth-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("two-factor-setup-key")).toHaveTextContent("RESUMEDKEY");
+        });
+        expect(screen.getByTestId("two-factor-qr")).toBeInTheDocument();
+    });
+});

```
