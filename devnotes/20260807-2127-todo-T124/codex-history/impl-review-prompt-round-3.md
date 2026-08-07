# 実装レビュー Round 3 (T124)

Round 2 の [Warning] 1 件に対応した。

## [Warning] precheck の根拠記述が保持できる画面状態を誇張している

**対応した。** 指摘のとおり実装と食い違っていた。`enableTwoFactor()` の precheck が守るのは
enrollment の**開始**操作であり、成立後の分岐では直後に `resetEnrollmentAssets()` が走るため、
この時点で QR / セットアップキー / 入力中コードは**存在しない**。
「それらが失われる」は起こり得ない主張だった。

記述を 2 箇所とも「**設定画面から離脱せず**、再認証成立後に開始操作をその場で再開できる」へ
狭め、さらに「守っているのは離脱の回避であって QR / 入力中コードの保持ではない
(この時点で素材はまだ存在しない。素材取得**後**の鮮度切れは loadEnrollmentAssets() の
409 分岐が担当する)」という但し書きを明示した (次に読む人が同じ誤解をしないようにするため)。

実装 (precheck を enable の前段に置く順序) は**変えていない**。

## 修正後の再検証

- `pnpm lint` passed / `pnpm typecheck` passed
- `pnpm test` 126 files / 1258 tests passed
- (PHP 側・ブラウザ側は本修正がコメントとドキュメントのみのため Round 2 の実測から不変)

差分が Round 2 の指摘に正しく応えているかを確認し、
**APPROVED** または **CHANGES_REQUESTED** で締めてほしい。

---

## 該当ファイルの差分 (git diff HEAD -- resources/js/pages/Settings/Security.svelte docs/architecture.md)

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index 3336b46..4ef752f 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -932,3 +932,80 @@ ## 外部 fake 配線の不変条件 (T119)
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
+単一ハンドラ (`registerRecentAuthRedirectHandler`) が confirm 画面へ**全画面遷移**する。
+precheck ならモーダルで完結するので**設定画面から離脱せず**、再認証成立後に
+enrollment の開始操作をその場で再開できる。
+守っているのは離脱の回避であって QR / 入力中コードの保持ではない
+(開始 POST の時点で素材はまだ存在しない。素材取得**後**の鮮度切れは下の 409 分岐が担当する)。
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
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index c10c8d9..4817449 100644
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
 
@@ -292,26 +399,48 @@
         });
     }
 
+    /**
+     * 有効化開始。POST /user/two-factor-authentication は recent-auth 必須になった (T124) ため
+     * precheck を前段に置く。
+     * ★順序が重要: step-up は enrollment の**最初**の操作にする。precheck 無しで POST すると
+     *   Inertia mutation が 409 (`recent_auth_required`) を受け、単一ハンドラ
+     *   (registerRecentAuthRedirectHandler) が confirm 画面へ**全画面遷移**する。
+     *   precheck ならモーダルで完結するので**設定画面から離脱せず**、成立後に開始操作を
+     *   その場で再開できる (既存 3 呼び出し側と同じ流儀)。
+     *   ※ここで守るのは離脱の回避であって QR / 入力中コードの保持ではない
+     *     (この時点では素材はまだ存在しない。素材取得後の鮮度切れは
+     *      loadEnrollmentAssets() の 409 分岐が担当する)。
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
@@ -458,6 +587,28 @@
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
@@ -468,7 +619,7 @@
                                 {#snippet action()}
                                     <Button
                                         variant="ghost"
-                                        onclick={() => void loadEnrollmentAssets()}
+                                        onclick={retryEnrollmentAssets}
                                         loading={loadingEnrollmentAssets}
                                         testId="retry-enrollment-assets-button"
                                     >

```
