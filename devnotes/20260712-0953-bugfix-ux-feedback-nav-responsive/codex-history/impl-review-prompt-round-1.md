# 実装レビュー依頼: T013 UX整備 (F-03/F-06 feedback欠落・F-08 ナビ不統一・F-14 モバイル横スクロール)

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

シニア Laravel/Svelte エンジニアとして、以下の実装 diff を最終レビューせよ。
これは修正サイクル後の最終確認レビューである。前回レビューで Critical / Warning はゼロだった。

設計正本: `/workspace/devnotes/20260712-0953-bugfix-ux-feedback-nav-responsive/detailed-design.md`
(Codex 詳細レビュー Round 3 で APPROVED 済み)

観点:
1. 設計 (detailed-design.md) との乖離がないか
2. セキュリティ不変条件 (enumeration 抑止・flash キー統一) の破れがないか
3. Svelte 5 runes / DS token / component 階層規約 (AGENTS.md) の違反がないか
4. テストが不変条件を固定できているか (削除・弱体化がないか)
5. 禁止事項 1-8 への抵触がないか

出力フォーマット:
- `## 判定` — APPROVED または CHANGES_REQUESTED
- `## Critical` — 修正必須の問題 (なければ「なし」)
- `## Warning` — 推奨修正 (なければ「なし」)
- `## Suggestion` — 任意の改善案

検証結果 (参考): composer test 1510 passed / phpstan 0 errors / pint passed / eslint・tsc clean / vitest はマシン負荷起因のタイムアウトフレークを除き対象テスト全 pass。

---

## レビュー対象 diff (main..todo/T013)

```diff
diff --git a/app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php b/app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php
index cfd8b2d..8feb50e 100644
--- a/app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php
+++ b/app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php
@@ -15,10 +15,13 @@
  *
  * Fortify 標準は user 在/不在で異なるレスポンス (成功 flash vs エラー) を返すため
  * account enumeration を許してしまう。user 在/不在を問わず常に同一の
- * 「送信しました」flash を返して抑止する。
+ * 「送信しました」flash (キーは success = flash-to-toast の消費対象) を返して抑止する。
  *
  * 成功 (SuccessfulPasswordResetLinkRequestResponse) / 失敗
  * (FailedPasswordResetLinkRequestResponse) の両契約を本クラスに差し替える。
+ *
+ * `STATUS_MESSAGE` は Fortify の status 言語キーに対応するメッセージ内容の意味であり、
+ * flash キー名 (`success`) とは無関係。
  */
 final class EnumerationSafePasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponseContract, SuccessfulPasswordResetLinkRequestResponseContract
 {
@@ -40,7 +43,9 @@ public function toResponse($request): JsonResponse|RedirectResponse
             return new JsonResponse(['message' => self::STATUS_MESSAGE], 200);
         }
 
-        return back()->with('status', self::STATUS_MESSAGE);
+        // flash キー統一ポリシー: web 向け操作成功 flash は success に統一する
+        // (status は flash-to-toast が意図的に gating しており toast にならない = F-06)
+        return back()->with('success', self::STATUS_MESSAGE);
     }
 
     /**
diff --git a/app/Http/Responses/Fortify/VerificationNotificationSentResponse.php b/app/Http/Responses/Fortify/VerificationNotificationSentResponse.php
new file mode 100644
index 0000000..104ac62
--- /dev/null
+++ b/app/Http/Responses/Fortify/VerificationNotificationSentResponse.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Fortify;
+
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;
+
+/**
+ * 認証メール再送信後のレスポンス (Fortify contract bind)。
+ *
+ * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
+ * status を意図的に gating (toast 化しない)。再送信の完了を toast でフィードバック
+ * するため、web は `success` キーへ寄せる (flash キー統一ポリシー:
+ * web 向け操作成功 flash は success に統一する。FortifyResponseTest が正本)。
+ *
+ * wantsJson (XHR / API) の raw JSON は「Fortify 固定契約の互換維持」であり
+ * 禁止事項 4 (response()->json() 直書き) の例外に該当する。このパターンは
+ * app/Http/Responses/Fortify/ に閉じ、通常のアプリ endpoint へ波及させない。
+ */
+final class VerificationNotificationSentResponse implements EmailVerificationNotificationSentResponseContract
+{
+    private const string SUCCESS_MESSAGE = '認証メールを再送信しました。';
+
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): JsonResponse|RedirectResponse
+    {
+        // JSON 分岐は差し替え元の Fortify 既定 (wantsJson / 202) をそのまま踏襲する
+        // (既存 3 クラスは expectsJson だが、本クラスは挙動互換を最優先する)
+        if ($request->wantsJson()) {
+            return new JsonResponse('', 202);
+        }
+
+        return back()->with('success', self::SUCCESS_MESSAGE);
+    }
+}
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index eb0244f..b8c1b31 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -13,6 +13,7 @@
 use App\Http\Responses\Fortify\RecoveryCodesGeneratedResponse;
 use App\Http\Responses\Fortify\RegisterResponse;
 use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
+use App\Http\Responses\Fortify\VerificationNotificationSentResponse;
 use Illuminate\Cache\RateLimiting\Limit;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\RateLimiter;
@@ -21,6 +22,7 @@
 use Inertia\Inertia;
 use Inertia\Response as InertiaResponse;
 use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
+use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;
 use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
 use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
 use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse as RecoveryCodesGeneratedResponseContract;
@@ -39,6 +41,7 @@ public function register(): void
         $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
         $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
         $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
+        $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
         // forgot-password は成功/失敗の両契約を enumeration-safe な同一応答へ差し替える。
         // Fortify は constructor に status を渡して make するため bind (非 singleton)
         $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
diff --git a/resources/js/components/templates/AppLayout.svelte b/resources/js/components/templates/AppLayout.svelte
index f593565..d8b5b13 100644
--- a/resources/js/components/templates/AppLayout.svelte
+++ b/resources/js/components/templates/AppLayout.svelte
@@ -1,55 +1,91 @@
 <script lang="ts">
     import type { Snippet } from "svelte";
-    import { page } from "@inertiajs/svelte";
-    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
+    import { page, router } from "@inertiajs/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
     import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
     import NotificationBell from "@/components/molecules/NotificationBell.svelte";
-    import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
-    import type { NotificationSharedProps } from "@/types/notification";
+    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import { consumeFlash } from "@/lib/stores/flash-to-toast";
 
     /**
      * 認証済み画面用レイアウト (最小骨格)。
      * Phase 2 (組織・Team・Project 導入) でサイドバー・組織切替・通知センターを拡張する。
      * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
+     * ログイン中は通知ベル・設定・ログアウトを全ページ常設する (F-08: ナビ統一)。
+     * ログアウト POST はこのレイアウトの単一ハンドラに一本化する (ページ側に実装を残さない)。
      */
     interface Props {
         appName: string;
         children: Snippet;
-        /** ヘッダー右側 (ユーザーメニュー等) */
+        /** ヘッダー右側のページ固有の追加アクション (常設ナビの左に並ぶ) */
         headerActions?: Snippet;
     }
 
     let { appName, children, headerActions }: Props = $props();
 
+    // shared props は backend (HandleInertiaRequests) が真実。lib/shared-props.ts の型で読む
+    const shared = $derived(page.props as unknown as SharedProps);
+
     $effect(() => {
-        consumeFlash(page.props.flash as FlashPayload | undefined);
+        consumeFlash(shared.flash);
     });
 
     // メール未認証のソフトゲート案内 (organizations.store / invitations.store は
     // verified.or-back で back + error flash になるため、常設バナーで導線を先出しする)。
-    const auth = $derived(page.props.auth as { user?: { emailVerified?: boolean } | null } | undefined);
-    const showEmailBanner = $derived(auth?.user != null && auth.user.emailVerified === false);
-
-    // 通知センターの未読数 (shared props)。ログイン時のみベルを常設する
-    const notifications = $derived(
-        page.props.notifications as NotificationSharedProps | undefined,
+    const showEmailBanner = $derived(
+        shared.auth?.user != null && shared.auth.user.emailVerified === false,
     );
-    const showBell = $derived(auth?.user != null);
+
+    // ログイン時のみベル + アカウントナビ (設定/ログアウト) を常設する
+    // (invitations.accept 等、ゲスト到達がある AppLayout ページでは出さない)
+    const showAccountNav = $derived(shared.auth?.user != null);
+
+    let loggingOut = $state(false);
+
+    // ログアウト (二重送信ガード。失敗時も onFinish で解除され再試行できる)
+    function logout(): void {
+        if (loggingOut) return;
+        router.post(
+            "/logout",
+            {},
+            {
+                onStart: () => {
+                    loggingOut = true;
+                },
+                onFinish: () => {
+                    loggingOut = false;
+                },
+            },
+        );
+    }
 </script>
 
 <ToastContainer />
 
 <div class="flex min-h-screen flex-col bg-neutral text-text">
     <header class="border-b border-border bg-surface">
-        <div class="mx-auto flex max-w-6xl items-center justify-between px-8 py-3">
-            <a href="/dashboard" class="text-h3 text-primary">{appName}</a>
-            <div class="flex items-center gap-3">
-                {#if showBell}
-                    <NotificationBell unreadCount={notifications?.unreadCount ?? 0} />
-                {/if}
+        <!-- 375px 方針: ロゴは shrink-0、右側アクション群は flex-wrap で行内折り返し (2 段化) -->
+        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-8 py-3">
+            <a href="/dashboard" class="shrink-0 text-h3 text-primary">{appName}</a>
+            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
                 {#if headerActions}
                     {@render headerActions()}
                 {/if}
+                {#if showAccountNav}
+                    <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
+                    <TextLink href="/settings" testId="nav-settings">設定</TextLink>
+                    <Button
+                        variant="ghost"
+                        size="sm"
+                        onclick={logout}
+                        loading={loggingOut}
+                        testId="nav-logout"
+                    >
+                        ログアウト
+                    </Button>
+                {/if}
             </div>
         </div>
     </header>
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index 74e542a..61abd0b 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -232,7 +232,10 @@
                 {/if}
                 <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                     {#each members as member (member.id)}
-                        <li class="flex items-center justify-between gap-4 py-3">
+                        <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14)。操作ブロックは要素単位で折り返し可 -->
+                        <li
+                            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
+                        >
                             <div class="min-w-0">
                                 <div class="flex items-center gap-2">
                                     <p class="truncate text-body">{member.name}</p>
@@ -255,7 +258,7 @@
                                     />
                                 {/if}
                             </div>
-                            <div class="flex shrink-0 items-center gap-2">
+                            <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                                 {#if canResetTwoFactor(member)}
                                     <Button
                                         variant="danger-ghost"
@@ -364,9 +367,12 @@
                         data-testid="invitation-list"
                     >
                         {#each invitations as invitation (invitation.id)}
-                            <li class="flex items-center justify-between gap-4 py-3">
-                                <p class="truncate text-body">{invitation.email}</p>
-                                <div class="flex shrink-0 items-center gap-3">
+                            <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14) -->
+                            <li
+                                class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
+                            >
+                                <p class="min-w-0 truncate text-body">{invitation.email}</p>
+                                <div class="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
                                     <p class="text-caption text-text-secondary">
                                         {invitation.roleLabel} ・ 期限 {invitation.expiresAt}
                                     </p>
diff --git a/resources/js/pages/Dashboard.svelte b/resources/js/pages/Dashboard.svelte
index f46024c..fb3a46b 100644
--- a/resources/js/pages/Dashboard.svelte
+++ b/resources/js/pages/Dashboard.svelte
@@ -1,5 +1,5 @@
 <script lang="ts">
-    import { page, router } from "@inertiajs/svelte";
+    import { page } from "@inertiajs/svelte";
     import { Bell, Building, Camera, FolderPlus, HardDrive, Loader, Ticket } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
@@ -30,23 +30,6 @@
     const isEditor = $derived(dashboard.role === "editor");
     const isShooter = $derived(dashboard.role === "shooter");
 
-    let loggingOut = $state(false);
-
-    function logout(): void {
-        router.post(
-            "/logout",
-            {},
-            {
-                onStart: () => {
-                    loggingOut = true;
-                },
-                onFinish: () => {
-                    loggingOut = false;
-                },
-            },
-        );
-    }
-
     /** バイト数の可読表記 (残容量タイルの subtext 用) */
     function formatBytes(bytes: number): string {
         if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
@@ -151,14 +134,8 @@
     </Card>
 {/snippet}
 
+<!-- 設定/ログアウトのヘッダーナビは AppLayout が常設する (F-08。page-local に持たない) -->
 <AppLayout {appName}>
-    {#snippet headerActions()}
-        <TextLink href="/settings">設定</TextLink>
-        <Button variant="ghost" size="sm" onclick={logout} loading={loggingOut}>
-            ログアウト
-        </Button>
-    {/snippet}
-
     <h1 class="text-h2">{user?.name ?? ""} さん、ようこそ</h1>
     <p class="mt-1 text-caption text-text-secondary">今日のアクティビティを確認しましょう。</p>
 
diff --git a/tests/Feature/Auth/FortifyResponseTest.php b/tests/Feature/Auth/FortifyResponseTest.php
index 25c7e38..fde291b 100644
--- a/tests/Feature/Auth/FortifyResponseTest.php
+++ b/tests/Feature/Auth/FortifyResponseTest.php
@@ -3,11 +3,17 @@
 declare(strict_types=1);
 
 use App\Models\User;
+use Illuminate\Auth\Notifications\VerifyEmail;
 use Illuminate\Support\Facades\Notification;
 
 /*
- * Fortify Response contract bind (app/Http/Responses/Fortify/) の応答契約。
+ * Fortify Response contract bind (app/Http/Responses/Fortify/) の応答契約の正本。
  * Login / Register の redirect 契約は AuthenticationTest / RegistrationTest が担う。
+ *
+ * flash キー統一ポリシー: web 向け操作成功 flash は success に統一する。
+ * status は flash-to-toast (resources/js/lib/stores/flash-to-toast.ts) が意図的に
+ * gating しており toast にならないため使わない。bind 済みの TwoFactorDisabledResponse /
+ * RecoveryCodesGeneratedResponse も success キーで実装済み (2FA 系 Feature テストが担保)。
  */
 
 test('forgot-password は user 在/不在で同一応答 (enumeration 抑止)', function (): void {
@@ -21,10 +27,33 @@
         'email' => 'missing@example.com',
     ]);
 
-    // どちらも同一の status flash + redirect back (成功/失敗を区別させない)
+    // どちらも同一の success flash + redirect back (成功/失敗を区別させない)。
+    // 同一メッセージだけでなく同一キーであることも固定する (片側だけ status が
+    // 残る誤実装も enumeration 差分になるため検出する)
     $existing->assertRedirect('/forgot-password');
     $missing->assertRedirect('/forgot-password');
-    $existing->assertSessionHas('status', 'パスワードリセット用のリンクをメールで送信しました。');
-    $missing->assertSessionHas('status', 'パスワードリセット用のリンクをメールで送信しました。');
+    $existing->assertSessionHas('success', 'パスワードリセット用のリンクをメールで送信しました。');
+    $missing->assertSessionHas('success', 'パスワードリセット用のリンクをメールで送信しました。');
+    $existing->assertSessionMissing('status');
+    $missing->assertSessionMissing('status');
     $missing->assertSessionDoesntHaveErrors();
 });
+
+test('認証メール再送は success flash を返す (web)', function (): void {
+    // verification.send は auth:web + throttle:6,1 (config fortify.limiters.verification)。
+    // 本テストは 1 リクエストのみ発行し throttle 上限に構造的に触れない
+    // (middleware の抑制はしない。並列実行でもユーザー毎にレートキーは独立)。
+    // JSON 分岐は Fortify 元実装互換のため wantsJson/202 を維持している
+    // (既存 3 クラスの expectsJson とあえて揃えない)。
+    Notification::fake();
+    $user = User::factory()->unverified()->create();
+
+    $response = $this->actingAs($user)
+        ->from('/email/verify')
+        ->post('/email/verification-notification');
+
+    $response->assertRedirect('/email/verify');
+    $response->assertSessionHas('success', '認証メールを再送信しました。');
+    $response->assertSessionMissing('status');
+    Notification::assertSentTo($user, VerifyEmail::class);
+});
diff --git a/tests/js/components/templates/AppLayout.test.ts b/tests/js/components/templates/AppLayout.test.ts
new file mode 100644
index 0000000..1a32c55
--- /dev/null
+++ b/tests/js/components/templates/AppLayout.test.ts
@@ -0,0 +1,121 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import { createRawSnippet } from "svelte";
+import { page } from "@inertiajs/svelte";
+import AppLayout from "@/components/templates/AppLayout.svelte";
+import type { AuthUser } from "@/lib/shared-props";
+
+// router をモックし page state は実物を使う (テスト毎に props を差し替える)
+const { routerMock } = vi.hoisted(() => ({
+    routerMock: { post: vi.fn() },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: routerMock,
+}));
+
+/*
+ * AppLayout の常設アカウントナビ (F-08: ナビ統一) の単一の真実。
+ * 全 AppLayout 利用ページのナビ表示はこの template テストで代表する
+ * (ページ個別のナビテストは追加しない)。
+ */
+
+const children = createRawSnippet(() => ({
+    render: () => `<div data-testid="page-content">content</div>`,
+}));
+
+function authUser(): AuthUser {
+    return {
+        id: 1,
+        name: "テスト 太郎",
+        email: "test@example.com",
+        emailVerified: true,
+        twoFactorEnabled: false,
+    };
+}
+
+function setPageProps(props: Record<string, unknown>): void {
+    page.props = props as typeof page.props;
+}
+
+afterEach(() => {
+    cleanup();
+    routerMock.post.mockReset();
+    setPageProps({});
+});
+
+describe("templates/AppLayout", () => {
+    it("ログイン中は設定リンク (/settings) とログアウトボタン・通知ベルを常設する", () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+        });
+        render(AppLayout, { props: { appName: "AI-CUE", children } });
+
+        // Inertia Link は href を絶対 URL に正規化するため pathname で比較する
+        const settingsHref = screen.getByTestId("nav-settings").getAttribute("href") ?? "";
+        expect(new URL(settingsHref, "http://localhost").pathname).toBe("/settings");
+        expect(screen.getByTestId("nav-logout")).toBeInTheDocument();
+        expect(screen.getByTestId("notification-bell")).toBeInTheDocument();
+        expect(screen.getByTestId("page-content")).toBeInTheDocument();
+    });
+
+    it("ログアウトボタン押下で POST /logout が呼ばれる", async () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+        });
+        render(AppLayout, { props: { appName: "AI-CUE", children } });
+
+        await fireEvent.click(screen.getByTestId("nav-logout"));
+
+        expect(routerMock.post).toHaveBeenCalledTimes(1);
+        expect(routerMock.post.mock.calls[0][0]).toBe("/logout");
+    });
+
+    it("auth.user が null なら設定/ログアウト/ベルを描画しない (ゲスト到達ページの回帰)", () => {
+        setPageProps({ auth: { user: null } });
+        render(AppLayout, { props: { appName: "AI-CUE", children } });
+
+        expect(screen.queryByTestId("nav-settings")).toBeNull();
+        expect(screen.queryByTestId("nav-logout")).toBeNull();
+        expect(screen.queryByTestId("notification-bell")).toBeNull();
+        expect(screen.getByTestId("page-content")).toBeInTheDocument();
+    });
+
+    it("ログアウトボタンは disabled でない (禁止事項 8 の系)", () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+        });
+        render(AppLayout, { props: { appName: "AI-CUE", children } });
+
+        expect(screen.getByTestId("nav-logout")).not.toBeDisabled();
+    });
+
+    it("notifications が undefined でもクラッシュせず unreadCount 0 相当で描画する", () => {
+        // partial reload で shared props の閉包が省略されるケース・テスト環境での
+        // 未定義ケースの両方をカバー (shared.notifications?.unreadCount ?? 0 の回帰固定)
+        setPageProps({ auth: { user: authUser() } });
+        render(AppLayout, { props: { appName: "AI-CUE", children } });
+
+        expect(screen.getByTestId("notification-bell")).toBeInTheDocument();
+        expect(screen.queryByTestId("unread-badge")).toBeNull();
+    });
+
+    it("ページ固有の headerActions snippet と常設ナビが共存する (常設ナビは各 1 個)", () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+        });
+        const headerActions = createRawSnippet(() => ({
+            render: () => `<button type="button" data-testid="page-action">ページ操作</button>`,
+        }));
+        render(AppLayout, { props: { appName: "AI-CUE", children, headerActions } });
+
+        expect(screen.getByTestId("page-action")).toBeInTheDocument();
+        expect(screen.getAllByTestId("nav-settings")).toHaveLength(1);
+        expect(screen.getAllByTestId("nav-logout")).toHaveLength(1);
+    });
+});
diff --git a/tests/js/pages/AdminUsers.test.ts b/tests/js/pages/AdminUsers.test.ts
index 08aa18f..7aa7c17 100644
--- a/tests/js/pages/AdminUsers.test.ts
+++ b/tests/js/pages/AdminUsers.test.ts
@@ -1,5 +1,5 @@
 import { describe, expect, it } from "vitest";
-import { render, screen } from "@testing-library/svelte";
+import { render, screen, within } from "@testing-library/svelte";
 import Users from "@/pages/Admin/Users.svelte";
 import type { InvitationRow, MemberRow } from "@/types/admin";
 
@@ -23,11 +23,23 @@ const membersFixture: MemberRow[] = [
         isSelf: false,
     },
     {
+        // F-14 (モバイル横スクロール) の bug-hunt 実測の最悪幅構成を再現する行:
+        // 2FA バッジ + 未割当バッジ + 2FA 解除ボタン + 未割当 select + 削除ボタンが同一行に揃う
+        // (閲覧者は id=1 の owner なので canResetTwoFactor は unassigned でも真)
         id: 3,
         name: "未割当 次郎",
         email: "unassigned@example.com",
         roleState: "unassigned",
         roleLabel: "未割当",
+        twoFactorStatus: "enabled",
+        isSelf: false,
+    },
+    {
+        id: 4,
+        name: "撮影 四郎",
+        email: "shooter@example.com",
+        roleState: "shooter",
+        roleLabel: "撮影者",
         twoFactorStatus: "disabled",
         isSelf: false,
     },
@@ -108,9 +120,10 @@ describe("Admin/Users", () => {
     it("2FA 設定済み・非同格メンバーには 2FA 解除ボタンを出す (owner 閲覧)", () => {
         render(Users, { props: baseProps });
 
-        // editor (enabled) → 出る / unassigned (disabled) → 出ない / self → 出ない
+        // editor/unassigned (enabled) → 出る / shooter (disabled) → 出ない / self → 出ない
         expect(screen.getByTestId("reset-two-factor-2")).toBeInTheDocument();
-        expect(screen.queryByTestId("reset-two-factor-3")).toBeNull();
+        expect(screen.getByTestId("reset-two-factor-3")).toBeInTheDocument();
+        expect(screen.queryByTestId("reset-two-factor-4")).toBeNull();
         expect(screen.queryByTestId("reset-two-factor-1")).toBeNull();
     });
 
@@ -170,6 +183,45 @@ describe("Admin/Users", () => {
         expect(screen.queryByTestId("admin-nav-categories")).toBeNull();
     });
 
+    it("メンバー行はモバイル縦積みクラスを持ち、操作ブロックは flex-wrap を持つ (F-14)", () => {
+        // jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして固定する。
+        // 対象要素は data-testid 起点で特定し DOM 順序に依存しない。
+        render(Users, { props: baseProps });
+
+        const roleSelect = screen.getByTestId("member-role-3");
+        const row = roleSelect.closest("li");
+        expect(row).not.toBeNull();
+        expect(row).toHaveClass("flex-col", "sm:flex-row");
+
+        const actions = roleSelect.parentElement;
+        expect(actions).not.toBeNull();
+        expect(actions).toHaveClass("flex-wrap");
+
+        // bug-hunt 実測の最悪幅構成 (2FA バッジ + 未割当バッジ + 2FA 解除 + 未割当 select + 削除)
+        // が同一行に揃っていることを固定する
+        const rowScope = within(row as HTMLElement);
+        expect(rowScope.getByText("2FA")).toBeInTheDocument();
+        expect(rowScope.getByTestId("unassigned-3")).toBeInTheDocument();
+        expect(rowScope.getByTestId("reset-two-factor-3")).toBeInTheDocument();
+        expect(rowScope.getByTestId("remove-member-3")).toBeInTheDocument();
+        expect(
+            rowScope.getByRole("option", { name: "未割当（選択してください）" }),
+        ).toBeInTheDocument();
+    });
+
+    it("招待行もモバイル縦積みクラスを持ち、右側ブロックは flex-wrap を持つ (F-14)", () => {
+        render(Users, { props: baseProps });
+
+        const revokeButton = screen.getByTestId("revoke-invitation-10");
+        const row = revokeButton.closest("li");
+        expect(row).not.toBeNull();
+        expect(row).toHaveClass("flex-col", "sm:flex-row");
+
+        const actions = revokeButton.parentElement;
+        expect(actions).not.toBeNull();
+        expect(actions).toHaveClass("flex-wrap");
+    });
+
     it("削除 ConfirmDialog はメンバー名入りの警告文言を持つ", async () => {
         const { component: _ } = render(Users, { props: baseProps });
 
diff --git a/tests/js/pages/Dashboard.test.ts b/tests/js/pages/Dashboard.test.ts
index f1328c9..25542e7 100644
--- a/tests/js/pages/Dashboard.test.ts
+++ b/tests/js/pages/Dashboard.test.ts
@@ -264,4 +264,16 @@ describe("Dashboard", () => {
 
         expect(container.querySelectorAll("[disabled]")).toHaveLength(0);
     });
+
+    it("page-local の設定/ログアウトを持たない (AppLayout 常設ナビに一本化)", () => {
+        // テスト環境は page store 未設定 = auth なしのため AppLayout の常設ナビは描画されない。
+        // 旧実装の page-local headerActions snippet が残っていれば auth なしでも描画されるため、
+        // どちらも null であることが旧実装残存を確実に検出する (F-08 の回帰固定)。
+        // logout POST は AppLayout の単一ハンドラの責務であり、Dashboard 内のイベントから
+        // router.post("/logout") を直接呼ばない。
+        render(Dashboard, { props: { dashboard: fullData() } });
+
+        expect(screen.queryByRole("link", { name: "設定" })).toBeNull();
+        expect(screen.queryByRole("button", { name: "ログアウト" })).toBeNull();
+    });
 });
```
