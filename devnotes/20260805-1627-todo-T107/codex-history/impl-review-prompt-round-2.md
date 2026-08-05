# Round 2: Round 1 の指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] RecentAuthStatusContractTest が SocialAccount を手組みしている (Factory 規約違反)

- 判断: **対応する**
- 根拠: 詳細設計「テストデータは必ず Factory で生成 (`Model::create()` 手組み禁止)」に反する。
  調査したところ **`SocialAccountFactory` はそもそも存在しなかった** (設計書は「既存の social account factory」を
  前提にしていたが、これが誤り)。AGENTS.md 実装規約「新規モデル追加時は Factory の追加と
  `docs/factories.md` への追記が必須」の観点でも、既存モデルの Factory 欠落は埋めるべき穴である。
- 対応内容:
  - `database/factories/SocialAccountFactory.php` を新設
    (既定 provider は `google` = `config('template.social_providers')` に capability 宣言があり
     recent-auth の step-up satisfier として数えられる provider。`provider(string)` state を用意)
  - `app/Models/SocialAccount.php` に `HasFactory` を追加 (PHPDoc `@use HasFactory<SocialAccountFactory>` 付き)
  - `docs/factories.md` の Factory 一覧に `SocialAccountFactory` の行を追記
  - contract テストを `SocialAccount::factory()->for($user)->create(['provider' => 'google'])` へ書き換え
  - 既存の `LoginMethodRetentionTest` / `RecentAuthTest` の手組みヘルパは本 TODO のスコープ外
    (既存テストの書き換えは差分を無闇に広げるため見送り。新規分のみ規約準拠にした)

## [Warning] PasskeySection: createPasskeyCredential() が throw すると registering が true のまま残る

- 判断: **対応する**
- 根拠: 施策 11 の目的は「登録ボタンが loading のまま固まらない (詰まない)」ことそのものであり、
  outcome 経路だけ守って throw 経路を落とすのは不変条件の穴。指摘のとおり。
- 対応内容:
  - `startCeremonyAndPost()` の `await createPasskeyCredential()` を `try/catch` で包み、
    catch 時に `operationError` (Alert) を出して `registering = false` に戻す
  - 回帰テストを追加:
    `tests/js/pages/SettingsSecurityPasskey.test.ts`
    「ceremony が throw しても Alert を出して loading が固まらない」
    (Alert の描画・`aria-busy` 解除・POST しないことを固定)

## 再検証

- `composer phpstan`: OK (783 files)
- `pnpm typecheck` / `pnpm lint`: OK
- `pnpm test tests/js/pages/SettingsSecurityPasskey.test.ts`: 28 passed
- 全レーン再実行は Round 2 送付前に実施


---

## 修正差分 (Round 1 指摘分のみ)

```diff
diff --git a/app/Models/SocialAccount.php b/app/Models/SocialAccount.php
index e12c890..de2f71d 100644
--- a/app/Models/SocialAccount.php
+++ b/app/Models/SocialAccount.php
@@ -4,6 +4,8 @@
 
 namespace App\Models;
 
+use Database\Factories\SocialAccountFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 
@@ -13,6 +15,9 @@
  */
 class SocialAccount extends Model
 {
+    /** @use HasFactory<SocialAccountFactory> */
+    use HasFactory;
+
     /** @var list<string> */
     protected $fillable = [
         'provider',
diff --git a/database/factories/SocialAccountFactory.php b/database/factories/SocialAccountFactory.php
new file mode 100644
index 0000000..57f481e
--- /dev/null
+++ b/database/factories/SocialAccountFactory.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories;
+
+use App\Models\SocialAccount;
+use App\Models\User;
+use Illuminate\Database\Eloquent\Factories\Factory;
+
+/**
+ * @extends Factory<SocialAccount>
+ */
+final class SocialAccountFactory extends Factory
+{
+    /** @var class-string<SocialAccount> */
+    protected $model = SocialAccount::class;
+
+    /**
+     * SSO 連携 (provider + provider_user_id で一意)。
+     *
+     * 既定 provider は `google` (config('template.social_providers') に capability 宣言があり、
+     * recent-auth の step-up satisfier として数えられる provider)。satisfier に数えられない
+     * provider を試す場合は state で上書きすること。
+     *
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'user_id' => User::factory(),
+            'provider' => 'google',
+            'provider_user_id' => (string) fake()->unique()->numerify('##################'),
+        ];
+    }
+
+    /** provider を明示する (capability 宣言の有無で挙動が変わるテスト用) */
+    public function provider(string $provider): static
+    {
+        return $this->state(fn (): array => ['provider' => $provider]);
+    }
+}
diff --git a/docs/factories.md b/docs/factories.md
index 23171ba..ba04075 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -17,6 +17,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `UserFactory` | User | `unverified()`, `ssoOnly()` (password null + 認証済み), `withTwoFactor()` (本物の TOTP secret + recovery codes + confirmed) |
 | `AdminUserFactory` | AdminUser | `withMfa()` |
 | `PasskeyFactory` | Passkey | — (`for($user)` で所有者を指定。WebAuthn ceremony を伴わない経路 = 削除 / 一覧 / 手段カウント / 認可 用の最小 credential。実 ceremony の検証は vendor の WebAuthn helper で credential を生成すること) |
+| `SocialAccountFactory` | SocialAccount | `provider(string)` (`for($user)` で所有者を指定。既定 provider は `google` = recent-auth の step-up satisfier として数えられる provider) |
 | `OrganizationFactory` | Organization | `personal()`, `freePersonal($declarer)`, `grandfathered()`, `signupGranted()`, `withBillingContact(?$email, ?$name)` (請求先連絡先。CipherSweet 暗号化列) |
 | `CustomTeamFactory` | CustomTeam | — |
 | `ProjectFactory` | Project | `forOrganization($org)` |
diff --git a/resources/js/components/features/auth/PasskeySection.svelte b/resources/js/components/features/auth/PasskeySection.svelte
index 86beb4c..2acf039 100644
--- a/resources/js/components/features/auth/PasskeySection.svelte
+++ b/resources/js/components/features/auth/PasskeySection.svelte
@@ -1,6 +1,7 @@
 <script lang="ts">
     import { router } from "@inertiajs/svelte";
     import { KeyRound } from "@lucide/svelte";
+    import { tick } from "svelte";
     import Alert from "@/components/atoms/Alert.svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
@@ -14,7 +15,6 @@
         isPasskeySupported,
         type PasskeyListItem,
     } from "@/lib/passkeys";
-    import { addToast } from "@/lib/stores/toast";
 
     /**
      * セキュリティ設定のパスキーカード。
@@ -22,6 +22,8 @@
      * 契約:
      * - 登録 / 削除は **recent-auth 必須**。precheck は呼び出し側 (page) が持つ `guard` に委譲する
      *   (再認証モーダルはページに 1 つだけ置き、二重モーダルを作らない)。
+     *   `guard` は precheck の結果を返す Promise であり、**precheck 区間も loading で覆う**
+     *   (待ち時間中の連打で ceremony が多重起動し pending action が上書きされるのを塞ぐ)。
      * - 登録は ceremony (fetch) → **Inertia `router.post`** で送る (transport 契約)。
      *   成功 flash はサーバ (`back()->with('success')`) を単一の源とし client 楽観 toast を出さない。
      * - 削除は ConfirmDialog → `router.delete`。ログイン手段が 0 になる場合サーバは
@@ -29,6 +31,9 @@
      *   (**無言失敗にしない**)。
      * - **必須条件未充足でボタンを disabled にしない** (AGENTS.md 禁止事項 8)。
      *   非対応端末でも押せて、押下時にエラーを出す。
+     * - **非フィールド起因の操作失敗は Alert** (DESIGN.md §Alert)。ceremony 失敗・端末非対応は
+     *   押したその場に残る Alert に出す (Toast は画面外へ飛ぶ一時通知であり、押下直後に
+     *   読ませたい失敗理由の提示先として使わない)。フィールド起因 (名前) だけが FormField。
      */
     interface Props {
         passkeys?: PasskeyListItem[];
@@ -37,8 +42,11 @@
         twoFactorEnabled?: boolean;
         /** EnsureLoginMethodRemains の拒否メッセージ ($page.props.errors.login_method) */
         loginMethodError?: string;
-        /** recent-auth precheck。fresh なら即実行、stale なら再認証モーダルを挟んで再開する */
-        guard: (action: () => void) => void;
+        /**
+         * recent-auth precheck。fresh なら即実行、stale なら再認証モーダルを挟んで再開する。
+         * 戻り値は実行した分岐 (precheck 区間を loading で覆うために待つ)。
+         */
+        guard: (action: () => void) => Promise<"fresh" | "stale" | "delegated">;
     }
 
     let {
@@ -56,60 +64,130 @@
     })();
 
     let newPasskeyName = $state("");
-    let nameError = $state("");
+
+    /**
+     * DESIGN.md §FormField: 押下時に出した client エラーは入力に追随させる
+     * (stale invalid を残さない)。新規は「提示開始 boolean + $derived 文言」で書く。
+     */
+    let nameErrorShown = $state(false);
+    /** サーバ由来 (422) のエラーは入力で消さない (DESIGN.md の例外規定) */
+    let serverNameError = $state<string | null>(null);
+    /** 非フィールド起因の操作失敗 (ceremony 失敗・端末非対応・登録 POST 失敗) */
+    let operationError = $state("");
+
+    const trimmedName = $derived(newPasskeyName.trim());
+    const clientNameError = $derived(
+        nameErrorShown && trimmedName === "" ? "パスキーの名前を入力してください。" : "",
+    );
+    const nameError = $derived(serverNameError ?? clientNameError);
+
+    /** ceremony ～ POST 完了まで (削除側と同じ作法で onStart/onFinish が握る) */
     let registering = $state(false);
+    /** precheck (/recent-auth/status) 実行中。ceremony/POST 中は registering が覆う */
+    let prechecking = $state(false);
+    const busy = $derived(prechecking || registering);
 
-    function registerPasskey(): void {
-        if (registering) return;
-        // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
-        if (!supported) {
-            addToast(
-                "error",
-                "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。",
-            );
+    /**
+     * ceremony → POST。`registering` は ceremony 開始時に立て、
+     * cancelled / unsupported / failed で終わったときだけ戻す
+     * (`finally` で一律解除すると POST 完了前に解除され、連打で ceremony が多重に走る)。
+     */
+    async function startCeremonyAndPost(capturedName: string): Promise<void> {
+        registering = true;
+
+        // ceremony は outcome を返す契約だが、想定外の throw (ラッパの前提崩れ・拡張機能の割込み等)
+        // でも loading を固定させない。**ボタンが押せないまま残ることが本施策で潰す詰みそのもの**。
+        let outcome: Awaited<ReturnType<typeof createPasskeyCredential>>;
+        try {
+            outcome = await createPasskeyCredential();
+        } catch {
+            operationError = "パスキーの登録を開始できませんでした。時間をおいて再度お試しください。";
+            registering = false;
             return;
         }
-        const name = newPasskeyName.trim();
-        if (name === "") {
-            nameError = "パスキーの名前を入力してください。";
+
+        if (outcome.status === "cancelled") {
+            // キャンセルは失敗として騒がない (再試行導線を残す)
+            registering = false;
+            return;
+        }
+        if (outcome.status === "unsupported") {
+            operationError = "このブラウザはパスキーに対応していません。";
+            registering = false;
+            return;
+        }
+        if (outcome.status === "failed") {
+            operationError = outcome.message;
+            registering = false;
             return;
         }
-        nameError = "";
 
-        guard(() => {
-            void (async () => {
-                registering = true;
-                try {
-                    const outcome = await createPasskeyCredential();
-                    if (outcome.status === "cancelled") return;
-                    if (outcome.status === "unsupported") {
-                        addToast("error", "このブラウザはパスキーに対応していません。");
-                        return;
-                    }
-                    if (outcome.status === "failed") {
-                        addToast("error", outcome.message);
-                        return;
-                    }
-                    router.post(
-                        "/user/passkeys",
-                        { name, credential: outcome.value },
-                        {
-                            preserveScroll: true,
-                            onSuccess: () => {
-                                newPasskeyName = "";
-                            },
-                            onError: () => {
-                                addToast("error", "パスキーの登録に失敗しました。");
-                            },
-                        },
-                    );
-                } finally {
+        router.post(
+            "/user/passkeys",
+            { name: capturedName, credential: outcome.value },
+            {
+                preserveScroll: true,
+                onStart: () => {
+                    registering = true;
+                },
+                onFinish: () => {
                     registering = false;
-                }
-            })();
-        });
+                },
+                onSuccess: () => {
+                    newPasskeyName = "";
+                    nameErrorShown = false;
+                },
+                onError: (errors) => {
+                    // フィールド起因は FormField へ、それ以外は Alert へ
+                    const nameMessage = (errors as Record<string, unknown>).name;
+                    serverNameError = typeof nameMessage === "string" ? nameMessage : null;
+                    if (serverNameError === null) {
+                        operationError =
+                            "パスキーの登録に失敗しました。時間をおいて再度お試しください。";
+                    }
+                },
+            },
+        );
     }
 
+    async function registerPasskey(): Promise<void> {
+        if (busy) return;
+        operationError = "";
+        // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
+        if (!supported) {
+            operationError =
+                "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。";
+            return;
+        }
+        nameErrorShown = true;
+        serverNameError = null;
+        if (trimmedName === "") return; // 文言は $derived が出す
+
+        // 押下時点の名前を確定させる (再認証モーダルを挟む間に入力欄が編集されても揺れない)
+        const capturedName = trimmedName;
+
+        prechecking = true;
+        try {
+            // fresh なら guard の中で action (ceremony → POST) が走り、registering が引き継ぐ。
+            // stale / delegated ならモーダル側へ委譲されるので、ここで precheck を閉じてよい。
+            await guard(() => void startCeremonyAndPost(capturedName));
+        } finally {
+            prechecking = false;
+        }
+    }
+
+    /* ---- ログイン手段保持 guard の拒否 Alert にフォーカスを移す (見落とさせない) ----
+       リカバリコード panel (Settings/Security) と同じ作法 (tabindex=-1 + bind:this + tick)。 */
+    let loginMethodAlert = $state<HTMLDivElement | null>(null);
+    let lastFocusedLoginMethodError = $state<string | undefined>(undefined);
+
+    $effect(() => {
+        const message = loginMethodError;
+        if (message === undefined || message === lastFocusedLoginMethodError) return;
+        lastFocusedLoginMethodError = message;
+        void tick().then(() => loginMethodAlert?.focus());
+    });
+
     let deleteTarget = $state<PasskeyListItem | null>(null);
     let deleteDialogOpen = $state(false);
     let deleting = $state(false);
@@ -122,7 +200,7 @@
     function confirmDelete(): void {
         const target = deleteTarget;
         if (target === null) return;
-        guard(() => {
+        void guard(() => {
             router.delete(`/user/passkeys/${target.id}`, {
                 preserveScroll: true,
                 onStart: () => {
@@ -159,16 +237,26 @@
 
     <div class="mt-4 flex flex-col gap-4">
         {#if loginMethodError}
-            <Alert type="danger" title="削除できません" testId="passkey-login-method-error">
-                {loginMethodError}
-                {#snippet action()}
-                    <div class="flex flex-wrap gap-3">
-                        <Button variant="ghost" href="/settings" testId="passkey-add-password">
-                            パスワードを設定する
-                        </Button>
-                    </div>
-                {/snippet}
-            </Alert>
+            <div bind:this={loginMethodAlert} tabindex="-1">
+                <Alert type="danger" title="削除できません" testId="passkey-login-method-error">
+                    {loginMethodError}
+                    このページの「ソーシャルログイン連携」から外部アカウントを連携するか、
+                    下のフォームから別のパスキーを登録することもできます。
+                    {#snippet action()}
+                        <div class="flex flex-wrap gap-3">
+                            <!--
+                              遷移先 /settings は password 未設定ユーザーには「パスワードを設定」
+                              フォームを出す (施策 7)。この Alert が出るのは「削除するとログイン手段が
+                              0 になる」= password を持たないユーザーだけなので
+                              (LoginMethodInventory の投影評価)、CTA は必ず踏破可能。
+                            -->
+                            <Button variant="ghost" href="/settings" testId="passkey-add-password">
+                                パスワードを設定する
+                            </Button>
+                        </div>
+                    {/snippet}
+                </Alert>
+            </div>
         {/if}
 
         {#if !passkeyLoginAvailable && twoFactorEnabled}
@@ -219,6 +307,9 @@
         {/if}
 
         <div class="flex flex-col gap-3">
+            {#if operationError}
+                <Alert type="danger" testId="passkey-operation-error">{operationError}</Alert>
+            {/if}
             <FormField label="パスキーの名前" id="passkey-name" error={nameError}>
                 {#snippet children({ id, describedBy, invalid })}
                     <Input
@@ -233,7 +324,11 @@
                 {/snippet}
             </FormField>
             <div>
-                <Button onclick={registerPasskey} loading={registering} testId="register-passkey-button">
+                <Button
+                    onclick={() => void registerPasskey()}
+                    loading={busy}
+                    testId="register-passkey-button"
+                >
                     パスキーを登録
                 </Button>
             </div>
diff --git a/tests/Feature/Auth/RecentAuthStatusContractTest.php b/tests/Feature/Auth/RecentAuthStatusContractTest.php
new file mode 100644
index 0000000..54ddf51
--- /dev/null
+++ b/tests/Feature/Auth/RecentAuthStatusContractTest.php
@@ -0,0 +1,109 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\SocialAccount;
+use App\Models\User;
+
+/*
+ * `/recent-auth/status` の **JSON 契約**を過不足なく固定する (T107 施策 3)。
+ *
+ * クライアント (resources/js/lib/recent-auth.ts) は strict parse に変えた:
+ * field が欠けた応答を既定値で補完すると「サーバは手段があると言っているのに UI に出ない」
+ * = 監査 F-1 と同じ詰みが通信境界で再演するため、契約不成立は null (delegated) に倒す。

```

### 追加した回帰テスト (抜粋)

```
189-+        await waitFor(() => expect(createPasskeyCredentialMock).toHaveBeenCalledTimes(1));
190-+        await waitFor(() => expect(routerPostMock).toHaveBeenCalledTimes(1));
191-+    });
192-+
193:+    it("ceremony が throw しても Alert を出して loading が固まらない", async () => {
194-+        stubRecentAuth(true);
195-+        createPasskeyCredentialMock.mockRejectedValue(new Error("unexpected"));
196-+        render(Security, { props: {} });
197-+
198-+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
199-+            target: { value: "現場用スマホ" },
200-+        });
201-+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
202-+
203-+        expect(await screen.findByTestId("passkey-operation-error")).toBeInTheDocument();
204-+        await waitFor(() =>
205-+            expect(screen.getByTestId("register-passkey-button")).not.toHaveAttribute(
206-+                "aria-busy",
207-+                "true",
208-+            ),
209-+        );
210-+        expect(routerPostMock).not.toHaveBeenCalled();
211-+    });
212-+
213-+    it("stale でモーダルへ委譲した後にキャンセルしても登録ボタンが固まらない", async () => {
214-+        stubRecentAuth(false);
215-+        render(Security, { props: {} });

```

---

## 再検証結果 (全レーン)

- `composer test`: **2887 passed / 0 failed / 2 skipped** (2889 tests, 11510 assertions)
- `composer phpstan`: OK (No errors, level 10, 783 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint`: OK
- `pnpm typecheck`: OK
- `pnpm test`: **1202 passed / 0 failed** (123 files。throw 経路の回帰 1 件増)
- `pnpm build`: OK

---

上記 2 点の対応で全体判定を再評価してください。
新たな指摘があれば Critical / Warning / Suggestion に分類し、最後に
**全体判定: APPROVED / CHANGES_REQUESTED** を明記してください。
