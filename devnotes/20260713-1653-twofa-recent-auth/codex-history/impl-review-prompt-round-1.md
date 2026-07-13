# 使命・禁止事項・思考原則

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件（関連分）
- 機微操作 route は recent-auth（step-up 再認証）で保護し、付与漏れは Architecture テスト（`RecentAuthRouteTest`）で強制する。

## 思考原則 — 全議論に適用
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示しているか常に問え。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: レビュアー役割

あなたは Laravel 12 + Fortify + Svelte 5 (runes) + Inertia.js + TypeScript アプリのコードレビュアーです。
本 diff は TODO T023「2FA無効化(disable)に再認証(recent-auth)を必須化する」の実装です。

## レビュー観点
1. **設計との一致性**: 下記詳細設計書の意図（disable 経路に recent-auth を後付け配線、非 enforced org のみ実効、enforced org は 422 先行で不変）を満たしているか
2. **正確性**: middleware 順序（web group の BlockTwoFactorDisableForEnforcedOrganizations が route-level recent-auth より先行）、409/302 の分岐、fresh/stale 判定
3. **PHPStan level 10 適合性**: 型・null 安全・`@var list<string>` 維持
4. **DTO/JsonResource パターン**: `response()->json()` 直書きなし（既存 RecentAuthRequiredResource 再利用）
5. **テスト網羅性**: Architecture(付与漏れ検出) + Feature(HTTP 実効: stale 遮断/fresh 通過) + フロント component(fresh 1回/stale 遮断+二重モーダル回避/キャンセル時 pending 破棄/resume 1回)
6. **セキュリティ**: destructive closure (disable) の残置防止（$effect による pending 破棄）が正しく機能し、キャンセル後に別操作で誤って resume されないか
7. **Atomic Design 準拠 / DESIGN.md 準拠**: Security.svelte はページ層。既存 helper/component (withRecentAuth / RecentAuthModal) を再利用し、新規 atom/molecule 追加や hex 直書きはない

## 出力形式
- ファイルごとに判定
- Critical / Warning / Suggestion に分類
- 最後に全体判定: **APPROVED** または **CHANGES_REQUESTED**

---

# user: 詳細設計書と実装差分

## 詳細設計書（要旨）

施策:
- S1: `two-factor.disable` へ recent-auth を後付け配線（`FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` に 1 要素追加）
- S2: Architecture allowlist (`RecentAuthRouteTest`) に `two-factor.disable` 登録
- S3: disable step-up の Feature テスト新規（`TwoFactorDisableStepUpTest`）＋既存 `TwoFactorEnforcementTest` L315-324（非 enforced org self-disable）に fresh セッション付与＋共有ヘルパ `freshRecentAuthSession()` を `tests/Pest.php` に集約
- S4: フロント disable 前段 precheck（regenerate と同型に `withRecentAuth` でラップ）＋二重モーダル回避（stale 時は確認ダイアログを先に閉じる）＋キャンセル時 pending 破棄 `$effect`＋component テスト
- S5: `config/fortify.php` の TODO コメント追従（disable を「対応済み」へ）

設計の核心:
- 新規ルート・DTO・Resource・モデルは一切追加しない。既存 recent-auth 機構へ `two-factor.disable` を 1 経路追加するだけ。
- middleware 順序: enforced org の 422 (web group、BlockTwoFactorDisableForEnforcedOrganizations) が recent-auth (route-level) より先行するため、enforced org 準拠ユーザーの体験（422）は不変。recent-auth が実効するのは非 enforced org の self-disable 許可ユーザーのみ。
- `TwoFactorEnforcementTest` の enforced org 2 件（422/redirect）は変更不要（422 先行）。非 enforced org の self-disable テスト（L315-324）のみ recent-auth を満たすため fresh セッション付与。
- フロント: stale 時の二重モーダル（確認ダイアログ + recent-auth ダイアログ）の focus trap 競合を避けるため確認ダイアログを先に畳む。`RecentAuthModal` にキャンセル callback がないため、モーダルが閉じたら pending の destructive closure を破棄する `$effect` を追加（resumePendingAction は action をローカル退避後に null 化するので二重実行でも安全）。

## テスト結果
- composer test: 1563 passed / 2 skipped / 0 failed
- composer phpstan: No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck: clean
- pnpm test (vitest): 480 passed（うち SettingsSecurity.test.ts 15 passed）
- pnpm build: OK

## 実装差分（git diff）

```diff
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index e8853c2..54e36ce 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -38,9 +38,14 @@ class FortifyServiceProvider extends ServiceProvider
 {
     /**
      * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
-     * リカバリコードは TOTP を伴わないログイン成立手段 = 第二要素の bypass 経路そのものなので、
-     * 表示 (GET) / 再生成 (POST) の双方を機微操作として扱う
-     * (姉妹操作: organizations.members.two-factor.reset / settings.account.destroy 等と同基準)。
+     * いずれも「確立済み第二要素の bypass / 除去」経路であり、通常セッション認証だけで
+     * 到達させない (姉妹操作: organizations.members.two-factor.reset /
+     * settings.account.destroy 等と同基準)。
+     * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
+     * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
+     *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
+     *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
+     *     self-disable が許可される非 enforced 組織のユーザー。
      * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
      *
      * @var list<string>
@@ -48,6 +53,7 @@ class FortifyServiceProvider extends ServiceProvider
     private const RECENT_AUTH_ROUTE_NAMES = [
         'two-factor.recovery-codes',
         'two-factor.regenerate-recovery-codes',
+        'two-factor.disable',
     ];
 
     public function register(): void
diff --git a/config/fortify.php b/config/fortify.php
index 896523b..3bd1059 100644
--- a/config/fortify.php
+++ b/config/fortify.php
@@ -154,9 +154,10 @@
             // Fortify 標準の password.confirm (3h・パスワード限定) は無効化し、step-up を
             // generic recent-auth (15 分窓・パスワード or 再SSO) へ統一する。SSO-only ユーザーを
             // password 固定の確認画面で詰ませないため。
-            // recovery-codes (GET/POST) は FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()
-            // で recent-auth を後付け配線済み (RecentAuthRouteTest が CI 固定)。
-            // TODO(template): 残る 2FA 管理エンドポイント (enable/confirm/disable/qr-code/secret-key)
+            // recovery-codes (GET/POST) と disable (DELETE) は
+            // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() で recent-auth を
+            // 後付け配線済み (RecentAuthRouteTest が CI 固定)。
+            // TODO(template): 残る 2FA 管理エンドポイント (enable/confirm/qr-code/secret-key)
             // は step-up なしで到達可能。enable/confirm は enrollment 動線 (2FA 強制組織の
             // オンボーディング) と衝突しない設計を決めてから同方式で固めること
             // (参照: aigenba RequireRecentAuthOnFortifyRoutes / spirux attachFortifyRouteMiddleware)。
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index f87b6ef..4adbddf 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -60,6 +60,16 @@
         action?.();
     }
 
+    $effect(() => {
+        // 再認証モーダルが閉じたら pending の destructive closure を破棄 (キャンセル時の残置防止)。
+        // onConfirmed 経由の resume は action をローカルへ退避してから pendingAction を null 化するため
+        // (resumePendingAction: `const a = pendingAction; pendingAction = null; a?.();`)、
+        // 本 effect と二重で走っても resume が先に action を握っており安全。
+        if (!recentAuthOpen) {
+            pendingAction = null;
+        }
+    });
+
     /** QR 確認待ち (有効化開始済みだが未確認) */
     let confirming = $state(false);
     let enabling = $state(false);
@@ -213,20 +223,35 @@
     let disabling = $state(false);
 
     function disableTwoFactor(): void {
-        router.delete("/user/two-factor-authentication", {
-            preserveScroll: true,
-            onStart: () => {
-                disabling = true;
-            },
-            onSuccess: () => {
+        // recent-auth 必須 (サーバが最終ゲート)。regenerateRecoveryCodes と同一の resume 契約。
+        const action = () => {
+            router.delete("/user/two-factor-authentication", {
+                preserveScroll: true,
+                onStart: () => {
+                    disabling = true;
+                },
+                onSuccess: () => {
+                    disableDialogOpen = false;
+                    confirming = false;
+                    qrSvg = null;
+                    recoveryCodes = [];
+                },
+                onFinish: () => {
+                    disabling = false;
+                },
+            });
+        };
+
+        void withRecentAuth({
+            onFresh: action,
+            onStale: (status) => {
+                // 二重モーダル回避: 確認ダイアログを閉じてから再認証ダイアログを開く。
                 disableDialogOpen = false;
-                confirming = false;
-                qrSvg = null;
-                recoveryCodes = [];
-            },
-            onFinish: () => {
-                disabling = false;
+                recentAuthStatus = status;
+                pendingAction = action;
+                recentAuthOpen = true;
             },
+            // delegated (status 取得失敗) は onFresh フォールバック = server middleware が最終ゲート。
         });
     }
 </script>
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index 283041e..c030111 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -34,6 +34,8 @@ function recentAuthRequiredRouteNames(): array
         // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が後付け配線)
         'two-factor.recovery-codes',
         'two-factor.regenerate-recovery-codes',
+        // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
+        'two-factor.disable',
     ];
 }
 
diff --git a/tests/Feature/Auth/TwoFactorDisableStepUpTest.php b/tests/Feature/Auth/TwoFactorDisableStepUpTest.php
new file mode 100644
index 0000000..2a19699
--- /dev/null
+++ b/tests/Feature/Auth/TwoFactorDisableStepUpTest.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+
+/*
+ * 2FA 無効化 (DELETE /user/two-factor-authentication, route two-factor.disable) の
+ * recent-auth (step-up) 配線。FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()
+ * が booted callback で recent-auth middleware を後付けする。ここではその実効性を HTTP 経由で
+ * 検証する。allowlist の付与漏れ検出は RecentAuthRouteTest (Architecture) 側。
+ */
+
+test('鮮度なしの DELETE 無効化 (XHR) は 409 recent_auth_required で 2FA を無効化しない', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->deleteJson('/user/two-factor-authentication')
+        ->assertStatus(409)
+        ->assertJson([
+            'code' => 'recent_auth_required',
+            'redirect' => route('recent-auth.confirm'),
+        ]);
+
+    $user->refresh();
+    expect($user->two_factor_secret)->not->toBeNull();
+    expect($user->two_factor_confirmed_at)->not->toBeNull();
+});
+
+test('鮮度なしの Inertia DELETE 無効化は 409 で 2FA を無効化しない', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->delete('/user/two-factor-authentication', [], ['X-Inertia' => 'true'])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+
+    $user->refresh();
+    expect($user->two_factor_secret)->not->toBeNull();
+});
+
+test('鮮度なしの通常 (非 XHR/非 Inertia) DELETE 無効化は recent-auth confirm へ 302 する', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->delete('/user/two-factor-authentication')
+        ->assertRedirect(route('recent-auth.confirm'));
+
+    $user->refresh();
+    expect($user->two_factor_secret)->not->toBeNull();
+});
+
+test('fresh なら DELETE が 2FA を無効化する', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->deleteJson('/user/two-factor-authentication')
+        ->assertOk();
+
+    $user->refresh();
+    expect($user->two_factor_secret)->toBeNull();
+    expect($user->two_factor_confirmed_at)->toBeNull();
+});
diff --git a/tests/Feature/Organizations/TwoFactorEnforcementTest.php b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
index 90fe2a6..ccbe5d8 100644
--- a/tests/Feature/Organizations/TwoFactorEnforcementTest.php
+++ b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
@@ -317,6 +317,7 @@ function tfeResetUrl(Organization $organization, User $member): string
     $member = tfeAddMember($organization, 'enabled');
 
     $this->actingAs($member)
+        ->withSession(freshRecentAuthSession()) // recent-auth を満たす (step-up 済み相当)
         ->delete('/user/two-factor-authentication')
         ->assertRedirect();
 
diff --git a/tests/Pest.php b/tests/Pest.php
index 41b1019..2255566 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -128,6 +128,18 @@ function createOrganizationWithOwner(string $name = 'テスト組織'): array
     return [$organization, $owner];
 }
 
+/**
+ * recent-auth (step-up) を確実に満たす fresh session 値。
+ * 窓は config('auth.recent_auth_timeout')(既定 900s)。注入時点の elapsed≈0 で窓に対し十分 fresh。
+ * recent-auth を要する route を「step-up 済み相当」で叩くテストは withSession() でこれを注入する。
+ *
+ * @return array{recent_auth_at: int}
+ */
+function freshRecentAuthSession(): array
+{
+    return ['recent_auth_at' => now()->timestamp];
+}
+
 /**
  * 組織を有償プラン契約状態にする (plan_code + Cashier subscription 行)。
  * plan_code は $fillable 外の状態キー (webhook 同期のみ) のため forceFill で明示代入。
diff --git a/tests/js/pages/SettingsSecurity.test.ts b/tests/js/pages/SettingsSecurity.test.ts
index cc332d3..7617963 100644
--- a/tests/js/pages/SettingsSecurity.test.ts
+++ b/tests/js/pages/SettingsSecurity.test.ts
@@ -13,8 +13,9 @@ import Security from "@/pages/Settings/Security.svelte";
  */
 
 // router.post をモックし、page は 2FA 状態を書き換えられる可変オブジェクトにする
-const { routerPostMock, pageState, addToastMock } = vi.hoisted(() => ({
+const { routerPostMock, routerDeleteMock, pageState, addToastMock } = vi.hoisted(() => ({
     routerPostMock: vi.fn(),
+    routerDeleteMock: vi.fn(),
     pageState: {
         props: {} as Record<string, unknown>,
         url: "/settings/security",
@@ -24,7 +25,7 @@ const { routerPostMock, pageState, addToastMock } = vi.hoisted(() => ({
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
-    router: { post: routerPostMock, delete: vi.fn() },
+    router: { post: routerPostMock, delete: routerDeleteMock },
     page: pageState,
 }));
 
@@ -93,6 +94,7 @@ afterEach(() => {
     cleanup();
     vi.unstubAllGlobals();
     routerPostMock.mockReset();
+    routerDeleteMock.mockReset();
     addToastMock.mockReset();
     fetchMock.mockReset();
 });
@@ -118,6 +120,12 @@ async function confirmRegenerate(): Promise<void> {
     await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
 }
 
+/** 無効化ダイアログを開いて確定する (recent-auth precheck が挟まるため DELETE は async) */
+async function confirmDisable(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("disable-two-factor-button"));
+    await fireEvent.click(screen.getByRole("button", { name: "無効化する" }));
+}
+
 describe("Settings/Security リカバリコード再生成 (F-10)", () => {
     it("2FA 有効時に再生成ボタンが表示され、disabled ではない", () => {
         render(Security, { props: {} });
@@ -295,3 +303,91 @@ describe("Settings/Security リカバリコード表示 (recent-auth precheck)",
         expect(requestedUrls).not.toContain("/user/two-factor-recovery-codes");
     });
 });
+
+describe("Settings/Security 2FA 無効化 (recent-auth precheck)", () => {
+    it("fresh なら DELETE /user/two-factor-authentication が exactly once 発火する", async () => {
+        stubFetchRoutes({ recent: true });
+        render(Security, { props: {} });
+
+        await confirmDisable();
+
+        await waitFor(() => {
+            expect(routerDeleteMock).toHaveBeenCalledWith(
+                "/user/two-factor-authentication",
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        expect(routerDeleteMock).toHaveBeenCalledTimes(1);
+        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
+    });
+
+    it("stale なら再認証モーダルを開き確認ダイアログを閉じ、DELETE しない (二重モーダル回避)", async () => {
+        stubFetchRoutes({ recent: false });
+        render(Security, { props: {} });
+
+        await confirmDisable();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(routerDeleteMock).not.toHaveBeenCalled();
+        // 二重モーダル回避: 無効化確認ダイアログ (disable-two-factor-dialog) は閉じている
+        expect(screen.queryByTestId("disable-two-factor-dialog")).toBeNull();
+    });
+
+    it("stale → 再認証キャンセルで pending を破棄し、後続の別操作 resume でも DELETE しない", async () => {
+        stubFetchRoutes({ recent: false });
+        render(Security, { props: {} });
+
+        // 1. disable を stale で開始 → 再認証モーダル表示
+        await confirmDisable();
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+
+        // 2. 再認証をキャンセル (open=false) → $effect が pendingAction を破棄
+        await fireEvent.click(screen.getByRole("button", { name: "キャンセル" }));
+        await waitFor(() => {
+            expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
+        });
+
+        // 3. 別操作 (再生成) を stale → 再認証成功させても disable closure は resume されない
+        await confirmRegenerate();
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
+            target: { value: "current-password" },
+        });
+        await fireEvent.click(screen.getByTestId("recent-auth-submit"));
+
+        // regenerate (POST) は resume されるが、破棄された disable (DELETE) は一度も発火しない
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalled();
+        });
+        expect(routerDeleteMock).not.toHaveBeenCalled();
+    });
+
+    it("stale → 再認証成功で保留していた DELETE を exactly once 再開する", async () => {
+        stubFetchRoutes({ recent: false });
+        render(Security, { props: {} });
+
+        await confirmDisable();
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+
+        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
+            target: { value: "current-password" },
+        });
+        await fireEvent.click(screen.getByTestId("recent-auth-submit"));
+
+        await waitFor(() => {
+            expect(routerDeleteMock).toHaveBeenCalledWith(
+                "/user/two-factor-authentication",
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        expect(routerDeleteMock).toHaveBeenCalledTimes(1);
+    });
+});

```

## design system 参照
- 触れたフロントは `resources/js/pages/Settings/Security.svelte`（pages 層）のみ。新規 component/atom/molecule なし。アイコン追加なし。hex 直書きなし。既存 `@/lib/recent-auth` の `withRecentAuth` / 既存 `RecentAuthModal` organism を再利用。
