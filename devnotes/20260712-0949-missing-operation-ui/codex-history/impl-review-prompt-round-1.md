# 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# 役割・タスク

あなたはシニア Laravel + Svelte 5 のコードレビュアー。以下は TODO T014「欠落UIの追加(F-10 リカバリコード再生成 / F-12 オーナー移譲)」の実装差分(worktree `/workspace/.claude/worktrees/tasks/T014`、ブランチ `todo/T014`、main との diff + 新規ファイル)の**最終マージ前レビュー**である。

前回レビューで以下 2 件の Warning が指摘され、本差分で対応済み:
1. F-10: `POST /user/two-factor-recovery-codes` (と GET) が recent-auth (step-up) 未配線 → `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` で booted callback による後付け配線 + `RecentAuthRouteTest` allowlist 追加 + Feature テスト (stale 409/302、fresh 通過、fail-first 検証済み) + フロント `guardWithRecentAuth` precheck + `RecentAuthModal`。
2. F-12: オーナー移譲の中核挙動 (候補選択→確認ダイアログ→確定→`transferForm.post` の URL/verb・recent-auth precheck) のテスト欠落 → `OrganizationsSettings.test.ts` に fresh/stale の 2 ケース追加 (`vi.spyOn(router, "post")` で実 useForm の委譲先を固定)。

観点:
1. **セキュリティ不変条件**: recent-auth 配線の実効性 (route:cache 下・middleware 順序)、既存 2FA enrollment フロー (2FA 必須組織のオンボーディング) を壊していないか
2. **正確性**: Fortify booted callback 配線のタイミング・名前解決、フロント guard の stale→再開フロー、旧コード即クリアの UX
3. **規約準拠**: 上記禁止事項 (特に 8: disabled 禁止)、Svelte 5 runes、DS token、atomic import 階層
4. **テスト妥当性**: 追加テストが不変条件を実際に固定しているか (差し替え検出力)

判定は必ず以下の形式で出力:
- `## Critical` — マージをブロックすべき欠陥(なければ「なし」と明記)
- `## Warning` — 修正推奨だがブロックしない
- `## Suggestion` — 任意改善

各指摘には該当ファイル・行の根拠と、具体的な失敗シナリオを付けること。検証コマンド (composer test 1515 passed / phpstan 0 errors / pint / eslint / tsc / vitest / build) は green 済み (vitest 全体再走行中)。

---

# 実装差分 (git diff main)

```diff
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index eb0244f..49a5047 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -14,7 +14,9 @@
 use App\Http\Responses\Fortify\RegisterResponse;
 use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
 use Illuminate\Cache\RateLimiting\Limit;
+use Illuminate\Contracts\Foundation\Application;
 use Illuminate\Http\Request;
+use Illuminate\Routing\Router;
 use Illuminate\Support\Facades\RateLimiter;
 use Illuminate\Support\ServiceProvider;
 use Illuminate\Support\Str;
@@ -31,6 +33,20 @@
 
 class FortifyServiceProvider extends ServiceProvider
 {
+    /**
+     * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
+     * リカバリコードは TOTP を伴わないログイン成立手段 = 第二要素の bypass 経路そのものなので、
+     * 表示 (GET) / 再生成 (POST) の双方を機微操作として扱う
+     * (姉妹操作: organizations.members.two-factor.reset / settings.account.destroy 等と同基準)。
+     * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
+     *
+     * @var list<string>
+     */
+    private const RECENT_AUTH_ROUTE_NAMES = [
+        'two-factor.recovery-codes',
+        'two-factor.regenerate-recovery-codes',
+    ];
+
     public function register(): void
     {
         // Fortify Response contract の差し替え (redirect + flash の Inertia 整合化)。
@@ -55,6 +71,31 @@ public function boot(): void
 
         $this->configureRateLimiters();
         $this->configureViews();
+        $this->attachRecentAuthToSensitiveRoutes();
+    }
+
+    /**
+     * Fortify が登録する機微な 2FA 管理ルートへ recent-auth middleware を後付けする。
+     *
+     * Fortify 標準の password.confirm は generic recent-auth へ置換済み
+     * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
+     * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
+     * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
+     * booted callback で名前解決して append する。route:cache 下でも
+     * CompiledRouteCollection::getByName() が nameCache に memoize した同一 instance を
+     * match() が返すため、この変更は dispatch にも有効。
+     */
+    private function attachRecentAuthToSensitiveRoutes(): void
+    {
+        $this->app->booted(static function (Application $app): void {
+            $routes = $app->make(Router::class)->getRoutes();
+            // fluent な ->name() 付与はコレクションの name index に遅延反映のため明示 refresh
+            $routes->refreshNameLookups();
+
+            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
+                $routes->getByName($name)?->middleware('recent-auth');
+            }
+        });
     }
 
     private function configureRateLimiters(): void
diff --git a/config/fortify.php b/config/fortify.php
index ca9dfbb..1185072 100644
--- a/config/fortify.php
+++ b/config/fortify.php
@@ -154,9 +154,11 @@
             // Fortify 標準の password.confirm (3h・パスワード限定) は無効化し、step-up を
             // generic recent-auth (15 分窓・パスワード or 再SSO) へ統一する。SSO-only ユーザーを
             // password 固定の確認画面で詰ませないため。
-            // TODO(template): この撤去により 2FA 管理エンドポイント (enable/confirm/disable/
-            // recovery-codes/qr-code/secret-key) は step-up なしで到達可能になる。アプリでは
-            // Fortify 登録ルートへ recent-auth を後付け配線して固めること
+            // recovery-codes (GET/POST) は FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()
+            // で recent-auth を後付け配線済み (RecentAuthRouteTest が CI 固定)。
+            // TODO(template): 残る 2FA 管理エンドポイント (enable/confirm/disable/qr-code/secret-key)
+            // は step-up なしで到達可能。enable/confirm は enrollment 動線 (2FA 強制組織の
+            // オンボーディング) と衝突しない設計を決めてから同方式で固めること
             // (参照: aigenba RequireRecentAuthOnFortifyRoutes / spirux attachFortifyRouteMiddleware)。
             'confirmPassword' => false,
         ]),
diff --git a/resources/js/pages/Organizations/Settings.svelte b/resources/js/pages/Organizations/Settings.svelte
index 1a9e0c8..7ebe2a3 100644
--- a/resources/js/pages/Organizations/Settings.svelte
+++ b/resources/js/pages/Organizations/Settings.svelte
@@ -94,9 +94,29 @@
             "",
     );
 
+    /** 候補 0 人時の共通文言 (案内文と押下時エラーで揺れないよう単一定義。テストも本文言を検証) */
+    const NO_TRANSFER_CANDIDATES = "移譲先にできるメンバーがいません。";
+
+    /**
+     * 移譲確認ダイアログを開く。成立し得ない操作は ConfirmDialog まで進めず、
+     * 押下時にエラー表示する (disabled 禁止 = AGENTS.md 8)。
+     * 選択値の実在検証は DOM 改変・stale 値の早期排除で、最終ゲートはサーバ
+     * (Policy + exists:users,id + Service のメンバーシップ検証)。
+     * select の value は string のため、Member.id (number) は String() に揃えて比較する。
+     */
     function openTransferDialog(event: SubmitEvent): void {
         event.preventDefault();
-        if (transferForm.user_id === "") {
+        if (transferCandidates.length === 0) {
+            transferForm.setError(
+                "user_id",
+                `${NO_TRANSFER_CANDIDATES}先にメンバーを招待してください。`,
+            );
+            return;
+        }
+        const isValidTarget = transferCandidates.some(
+            (member) => String(member.id) === transferForm.user_id,
+        );
+        if (!isValidTarget) {
             transferForm.setError("user_id", "移譲先のメンバーを選択してください。");
             return;
         }
@@ -227,11 +247,25 @@
             </Card>
         {/if}
 
-        {#if isOwner && transferCandidates.length > 0}
+        {#if isOwner}
             <DangerZone
                 title="オーナー移譲"
                 description="組織のオーナー権限を別のメンバーへ移譲します。移譲後、あなたは管理者になります。この操作にはパスワードの再確認が必要です。"
             >
+                {#if transferCandidates.length === 0}
+                    <p
+                        class="text-caption text-text-secondary"
+                        data-testid="transfer-no-candidates"
+                    >
+                        {NO_TRANSFER_CANDIDATES}先に
+                        {#if usersUrl !== null}
+                            <TextLink href={usersUrl}>管理メニュー &gt; ユーザー管理</TextLink>
+                            からメンバーを招待してください。
+                        {:else}
+                            メンバーを招待できる管理者に依頼してください。
+                        {/if}
+                    </p>
+                {/if}
                 <form onsubmit={openTransferDialog} class="flex flex-col gap-4">
                     <FormField
                         label="移譲先のメンバー"
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index f5b335f..f87b6ef 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -1,4 +1,5 @@
 <script lang="ts">
+    import { tick } from "svelte";
     import { page, router } from "@inertiajs/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
@@ -7,8 +8,10 @@
     import TextLink from "@/components/atoms/TextLink.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import { useForm } from "@inertiajs/svelte";
+    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
     import { providerLabel } from "@/lib/social";
     import { addToast } from "@/lib/stores/toast";
@@ -29,16 +32,42 @@
      * 未有効 → 有効化開始 (POST) → QR + コード確認 (confirming)
      * → リカバリコード表示 → 有効。無効化は ConfirmDialog 経由。
      * 注: Fortify の password.confirm は撤去済み (generic recent-auth へ統一)。
-     * 2FA エンドポイントへの recent-auth 配線はアプリ側の課題
-     * (config/fortify.php の TODO(template) 参照)。
+     * リカバリコード表示/再生成の endpoint は recent-auth 配線済み
+     * (FortifyServiceProvider::attachRecentAuthToSensitiveRoutes())。フロントは
+     * guardWithRecentAuth で precheck し、stale なら再認証モーダルを挟んで再開する。
+     * 残る 2FA endpoint の配線は config/fortify.php の TODO(template) 参照。
      * ---------------------------------------------------------------- */
 
+    /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
+    let recentAuthOpen = $state(false);
+    let recentAuthStatus = $state<RecentAuthStatus | null>(null);
+    let pendingAction: (() => void) | null = null;
+
+    function guardWithRecentAuth(action: () => void): void {
+        void withRecentAuth({
+            onFresh: action,
+            onStale: (status) => {
+                recentAuthStatus = status;
+                pendingAction = action;
+                recentAuthOpen = true;
+            },
+        });
+    }
+
+    function resumePendingAction(): void {
+        const action = pendingAction;
+        pendingAction = null;
+        action?.();
+    }
+
     /** QR 確認待ち (有効化開始済みだが未確認) */
     let confirming = $state(false);
     let enabling = $state(false);
     let qrSvg = $state<string | null>(null);
     let recoveryCodes = $state<string[]>([]);
     let loadingRecoveryCodes = $state(false);
+    /** 新コード一覧へのフォーカス移動用 (再生成成功時に再保管を促す) */
+    let recoveryCodesPanel = $state<HTMLDivElement | null>(null);
 
     const confirmForm = useForm({
         code: "",
@@ -63,17 +92,90 @@
         }
     }
 
-    async function loadRecoveryCodes(): Promise<void> {
+    /**
+     * リカバリコードを取得する。成否を返し、失敗時の文言は呼び出し側が文脈に応じて出す
+     * (通常表示: 単純な取得失敗 / 再生成直後: 旧コード失効済みの注意)。
+     */
+    async function loadRecoveryCodes(): Promise<boolean> {
         loadingRecoveryCodes = true;
         try {
             recoveryCodes = await fetchJson<string[]>("/user/two-factor-recovery-codes");
+            return true;
         } catch {
-            addToast("error", "リカバリコードの取得に失敗しました。");
+            return false;
         } finally {
             loadingRecoveryCodes = false;
         }
     }
 
+    /**
+     * 「リカバリコードを表示」押下時 (失敗は取得失敗トースト)。
+     * GET も recent-auth 配線済みのため precheck を通す (stale なら再認証モーダル→再開)。
+     */
+    function showRecoveryCodes(): void {
+        guardWithRecentAuth(() => {
+            void (async () => {
+                if (!(await loadRecoveryCodes())) {
+                    addToast("error", "リカバリコードの取得に失敗しました。");
+                }
+            })();
+        });
+    }
+
+    /* ---- リカバリコード再生成 (F-10) ----
+       POST 成功 = 旧コードは既に失効。表示中の旧コードを即クリアしてから GET で
+       新コードを取得し、成功時のみ成功トースト + 一覧へフォーカス (再保管を促す)。
+       GET 失敗時は「旧コードは無効」を明示し、既存の「リカバリコードを表示」ボタンが
+       再試行導線になる (recoveryCodes が空に戻るため自然に表示される)。 */
+    let regenerateDialogOpen = $state(false);
+    let regenerating = $state(false);
+
+    /** POST 成功後の後処理 (旧コードは既に失効している前提)。 */
+    async function handleRegenerateSuccess(): Promise<void> {
+        regenerateDialogOpen = false;
+        // 旧コードは失効済み。誤保管を防ぐため画面から即クリアする
+        recoveryCodes = [];
+        if (await loadRecoveryCodes()) {
+            addToast(
+                "success",
+                "リカバリコードを再生成しました。新しいコードを保管してください。",
+            );
+            await tick();
+            recoveryCodesPanel?.focus();
+            return;
+        }
+        addToast(
+            "error",
+            "新しいコードの取得に失敗しました。以前のコードは既に無効です。「リカバリコードを表示」から再取得してください。",
+        );
+    }
+
+    /** 再生成は recent-auth 必須 (サーバが最終ゲート)。stale なら再認証モーダル→再開 */
+    function regenerateRecoveryCodes(): void {
+        guardWithRecentAuth(() => {
+            router.post(
+                "/user/two-factor-recovery-codes",
+                {},
+                {
+                    preserveScroll: true,
+                    onStart: () => {
+                        regenerating = true;
+                    },
+                    onSuccess: () => {
+                        void handleRegenerateSuccess();
+                    },
+                    onError: () => {
+                        regenerateDialogOpen = false;
+                        addToast("error", "リカバリコードの再生成に失敗しました。");
+                    },
+                    onFinish: () => {
+                        regenerating = false;
+                    },
+                },
+            );
+        });
+    }
+
     function enableTwoFactor(): void {
         router.post(
             "/user/two-factor-authentication",
@@ -102,7 +204,7 @@
                 confirming = false;
                 qrSvg = null;
                 confirmForm.reset();
-                void loadRecoveryCodes();
+                showRecoveryCodes();
             },
         });
     }
@@ -154,7 +256,13 @@
             {#if twoFactorEnabled}
                 <div class="mt-4 flex flex-col gap-4">
                     {#if recoveryCodes.length > 0}
-                        <div class="rounded-md border border-border bg-neutral p-4">
+                        <!-- tabindex="-1" は再生成成功時の programmatic focus 用 -->
+                        <div
+                            class="rounded-md border border-border bg-neutral p-4"
+                            tabindex="-1"
+                            bind:this={recoveryCodesPanel}
+                            data-testid="recovery-codes-panel"
+                        >
                             <p class="text-caption text-text-secondary">
                                 リカバリコードは安全な場所に保管してください。各コードは一度だけ使えます。
                             </p>
@@ -171,14 +279,24 @@
                         <div>
                             <Button
                                 variant="ghost"
-                                onclick={() => void loadRecoveryCodes()}
+                                onclick={showRecoveryCodes}
                                 loading={loadingRecoveryCodes}
+                                testId="show-recovery-codes-button"
                             >
                                 リカバリコードを表示
                             </Button>
                         </div>
                     {/if}
-                    <div>
+                    <div class="flex flex-wrap gap-3">
+                        <Button
+                            variant="ghost"
+                            onclick={() => {
+                                regenerateDialogOpen = true;
+                            }}
+                            testId="regenerate-recovery-codes-button"
+                        >
+                            リカバリコードを再生成
+                        </Button>
                         <Button
                             variant="danger-outline"
                             onclick={() => {
@@ -279,4 +397,23 @@
         onConfirm={disableTwoFactor}
         testId="disable-two-factor-dialog"
     />
+
+    <ConfirmDialog
+        bind:open={regenerateDialogOpen}
+        title="リカバリコードの再生成"
+        message="リカバリコードを再生成しますか？ 既存のリカバリコードは直ちにすべて失効します。新しいコードを必ず保管し直してください。"
+        confirmLabel="再生成する"
+        confirmVariant="danger"
+        processing={regenerating}
+        onConfirm={regenerateRecoveryCodes}
+        testId="regenerate-recovery-codes-dialog"
+    />
+
+    <RecentAuthModal
+        bind:open={recentAuthOpen}
+        passwordSet={recentAuthStatus?.passwordSet ?? false}
+        availableProviders={recentAuthStatus?.availableProviders ?? []}
+        canSatisfy={recentAuthStatus?.canSatisfy ?? true}
+        onConfirmed={resumePendingAction}
+    />
 </AppLayout>
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index db4447f..283041e 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -30,6 +30,10 @@ function recentAuthRequiredRouteNames(): array
         'organizations.two-factor-requirement.update',
         // メンバー 2FA リセット (アカウント全体の第二要素を外す機微操作)
         'organizations.members.two-factor.reset',
+        // リカバリコード表示 / 再生成 (第二要素の bypass 経路。Fortify 登録ルートへ
+        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が後付け配線)
+        'two-factor.recovery-codes',
+        'two-factor.regenerate-recovery-codes',
     ];
 }
 
diff --git a/tests/js/pages/OrganizationsSettings.test.ts b/tests/js/pages/OrganizationsSettings.test.ts
index 10be632..de043a4 100644
--- a/tests/js/pages/OrganizationsSettings.test.ts
+++ b/tests/js/pages/OrganizationsSettings.test.ts
@@ -1,5 +1,6 @@
-import { describe, expect, it } from "vitest";
-import { render, screen } from "@testing-library/svelte";
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import { router } from "@inertiajs/svelte";
 import Settings from "@/pages/Organizations/Settings.svelte";
 
 const baseProps = {
@@ -86,3 +87,134 @@ describe("Organizations/Settings", () => {
         expect(link.getAttribute("href")).toMatch(/\/organizations\/test-org\/api-keys$/);
     });
 });
+
+describe("Organizations/Settings オーナー移譲の常時表示 (F-12)", () => {
+    // 自分 (id:1) しかいない組織 = 移譲候補 0 人。
+    // 実運用では members に自分が必ず含まれる (controller は全メンバーを返す) が、
+    // 本テストは page 未モックで myId=null のため members: [] で候補 0 人を表現する
+    // (どちらでも transferCandidates.length === 0 の同一分岐)。
+    const soloProps = { ...baseProps, members: [] };
+
+    it("候補 0 人でもオーナーにはセクションと案内文が表示される", () => {
+        render(Settings, { props: soloProps });
+
+        expect(screen.getByRole("heading", { name: "オーナー移譲" })).toBeInTheDocument();
+        expect(screen.getByTestId("transfer-no-candidates")).toBeInTheDocument();
+        expect(screen.getByTestId("transfer-no-candidates")).toHaveTextContent("ユーザー管理");
+        const button = screen.getByTestId("transfer-ownership-button");
+        expect(button).toBeInTheDocument();
+        expect(button).not.toBeDisabled();
+    });
+
+    it("候補 0 人で押下すると確認ダイアログを開かずエラーを表示する", async () => {
+        render(Settings, { props: soloProps });
+
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+
+        expect(
+            screen.getByText(
+                "移譲先にできるメンバーがいません。先にメンバーを招待してください。",
+            ),
+        ).toBeInTheDocument();
+        // ConfirmDialog (Modal) は開いていない
+        expect(screen.queryByRole("button", { name: "移譲する" })).toBeNull();
+    });
+
+    it("未選択のまま押下すると確認ダイアログを開かず選択エラーを表示する", async () => {
+        render(Settings, { props: baseProps });
+
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+
+        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
+        expect(screen.queryByRole("button", { name: "移譲する" })).toBeNull();
+    });
+
+    it("非オーナーにはオーナー移譲セクションを表示しない", () => {
+        render(Settings, {
+            props: { ...baseProps, currentUserRole: "organization_admin" },
+        });
+
+        expect(screen.queryByTestId("transfer-ownership-button")).toBeNull();
+        expect(screen.queryByRole("heading", { name: "オーナー移譲" })).toBeNull();
+    });
+});
+
+describe("Organizations/Settings オーナー移譲の確定フロー (F-12)", () => {
+    /**
+     * useForm (実物) の post は内部で router.post に委譲するため、router.post を spy して
+     * URL / verb / payload を固定する。recent-auth precheck (fetch /recent-auth/status) は
+     * URL 分岐の fetch stub で fresh/stale を切り替える。
+     */
+    function stubRecentAuthStatus(recent: boolean): ReturnType<typeof vi.fn> {
+        const fetchMock = vi.fn().mockImplementation((input: RequestInfo | URL) => {
+            if (String(input).includes("/recent-auth/status")) {
+                return Promise.resolve({
+                    ok: true,
+                    status: 200,
+                    json: () =>
+                        Promise.resolve({
+                            recent,
+                            passwordSet: true,
+                            availableProviders: [],
+                            canSatisfy: true,
+                            confirmedAt: recent ? 1 : null,
+                        }),
+                });
+            }
+            return Promise.reject(new Error(`unexpected fetch: ${String(input)}`));
+        });
+        vi.stubGlobal("fetch", fetchMock);
+        return fetchMock;
+    }
+
+    afterEach(() => {
+        vi.unstubAllGlobals();
+        vi.restoreAllMocks();
+    });
+
+    it("有効候補を選択→確認ダイアログ→確定で transferForm.post が正しい URL に発火する (precheck 込み)", async () => {
+        const routerPostSpy = vi.spyOn(router, "post").mockImplementation(() => {});
+        const fetchMock = stubRecentAuthStatus(true);
+        render(Settings, { props: baseProps });
+
+        // page 未モック (myId=null) のため候補は全メンバー。花子 (id:2) を選択する
+        await fireEvent.change(screen.getByLabelText("移譲先のメンバー"), {
+            target: { value: "2" },
+        });
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+
+        // 確認ダイアログが開く (確定までは POST しない)
+        const confirmButton = screen.getByRole("button", { name: "移譲する" });
+        expect(confirmButton).toBeInTheDocument();
+        expect(routerPostSpy).not.toHaveBeenCalled();
+
+        await fireEvent.click(confirmButton);
+
+        await waitFor(() => {
+            expect(routerPostSpy).toHaveBeenCalledWith(
+                "/organizations/test-org/transfer-ownership",
+                expect.objectContaining({ user_id: "2" }),
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        // recent-auth precheck (/recent-auth/status) を経由している
+        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
+    });
+
+    it("recent-auth が stale なら再認証モーダルを開き、POST しない", async () => {
+        const routerPostSpy = vi.spyOn(router, "post").mockImplementation(() => {});
+        stubRecentAuthStatus(false);
+        render(Settings, { props: baseProps });
+
+        await fireEvent.change(screen.getByLabelText("移譲先のメンバー"), {
+            target: { value: "2" },
+        });
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+        await fireEvent.click(screen.getByRole("button", { name: "移譲する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(routerPostSpy).not.toHaveBeenCalled();
+    });
+});
diff --git a/tests/js/pages/SettingsSecurity.test.ts b/tests/js/pages/SettingsSecurity.test.ts
new file mode 100644
index 0000000..0193bbc
--- /dev/null
+++ b/tests/js/pages/SettingsSecurity.test.ts
@@ -0,0 +1,294 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import Security from "@/pages/Settings/Security.svelte";
+
+/*
+ * セキュリティ設定画面 (F-10: リカバリコード再生成導線)。
+ * - 2FA 有効時のみ再生成ボタンが出る (非権限者非表示)
+ * - ConfirmDialog 経由でのみ POST される
+ * - 再生成 / 表示は recent-auth precheck 込み (stale なら再認証モーダル、POST しない)
+ * - POST 成功 → GET 成功: 新コード表示 + success トースト
+ * - POST 成功 → GET 失敗: 旧コード非表示のまま error トースト + 再試行導線
+ * - disabled 不使用 (AGENTS.md 禁止事項 8)
+ */
+
+// router.post をモックし、page は 2FA 状態を書き換えられる可変オブジェクトにする
+const { routerPostMock, pageState, addToastMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/settings/security",
+    },
+    addToastMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock, delete: vi.fn() },
+    page: pageState,
+}));
+
+// addToast のみ差し替え、toasts store 等 (ToastContainer が使う) は実物を残す
+vi.mock("@/lib/stores/toast", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
+    addToast: addToastMock,
+}));
+
+const fetchMock = vi.fn();
+
+function setTwoFactor(enabled: boolean): void {
+    pageState.props = {
+        appName: "AI-CUE",
+        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: enabled } },
+    };
+}
+
+/** JSON レスポンス風オブジェクト (fetch mock 用) */
+function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
+    return { ok, status, json: () => Promise.resolve(body) };
+}
+
+/**
+ * URL で分岐する fetch mock を張る。
+ * - /recent-auth/status: recent-auth precheck (fresh/stale)
+ * - /user/two-factor-recovery-codes: GET 新コード取得 (成功/失敗)
+ */
+function stubFetchRoutes({
+    recent = true,
+    codes = ["new-code-1", "new-code-2"],
+    codesOk = true,
+}: {
+    recent?: boolean;
+    codes?: string[];
+    codesOk?: boolean;
+} = {}): void {
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/recent-auth/status")) {
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent,
+                    passwordSet: true,
+                    availableProviders: [],
+                    canSatisfy: true,
+                    confirmedAt: recent ? 1 : null,
+                }),
+            );
+        }
+        if (url.includes("/recent-auth/password")) {
+            return Promise.resolve(jsonResponse(true, 204, null));
+        }
+        return Promise.resolve(
+            codesOk ? jsonResponse(true, 200, codes) : jsonResponse(false, 500, {}),
+        );
+    });
+}
+
+beforeEach(() => {
+    setTwoFactor(true);
+    vi.stubGlobal("fetch", fetchMock);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    routerPostMock.mockReset();
+    addToastMock.mockReset();
+    fetchMock.mockReset();
+});
+
+/** router.post の第3引数 (visit options) の検証対象部分。自己参照キャストを避けて明示定義する */
+interface InertiaVisitOptions {
+    onStart?: () => void;
+    onSuccess?: () => void;
+    onError?: () => void;
+    onFinish?: () => void;
+}
+
+/** Inertia visit options (第3引数) を取り出す */
+function lastVisitOptions(): InertiaVisitOptions {
+    const call = routerPostMock.mock.calls.at(-1);
+    if (!call) throw new Error("router.post が呼ばれていない");
+    return call[2] as InertiaVisitOptions;
+}
+
+/** 再生成ダイアログを開いて確定する (recent-auth precheck が挟まるため POST は async) */
+async function confirmRegenerate(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
+    await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
+}
+
+describe("Settings/Security リカバリコード再生成 (F-10)", () => {
+    it("2FA 有効時に再生成ボタンが表示され、disabled ではない", () => {
+        render(Security, { props: {} });
+
+        const button = screen.getByTestId("regenerate-recovery-codes-button");
+        expect(button).toBeInTheDocument();
+        expect(button).not.toBeDisabled();
+    });
+
+    it("2FA 無効時は再生成ボタンを表示しない (有効化ボタンのみ)", () => {
+        setTwoFactor(false);
+        render(Security, { props: {} });
+
+        expect(screen.queryByTestId("regenerate-recovery-codes-button")).toBeNull();
+        expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
+    });
+
+    it("再生成ボタン押下で確認ダイアログが開き、確認までは POST しない", async () => {
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
+
+        expect(
+            screen.getByText(/既存のリカバリコードは直ちにすべて失効します/),
+        ).toBeInTheDocument();
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("ダイアログ確認で recent-auth precheck 後に POST /user/two-factor-recovery-codes が発火する", async () => {
+        stubFetchRoutes({ recent: true });
+        render(Security, { props: {} });
+
+        await confirmRegenerate();
+
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalledWith(
+                "/user/two-factor-recovery-codes",
+                {},
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        // precheck (/recent-auth/status) を経由している
+        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
+    });
+
+    it("recent-auth が stale なら再認証モーダルを開き、POST しない", async () => {
+        stubFetchRoutes({ recent: false });
+        render(Security, { props: {} });
+
+        await confirmRegenerate();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("stale → モーダルで再認証成功 (204) すると保留していた POST を再開する", async () => {
+        stubFetchRoutes({ recent: false });
+        render(Security, { props: {} });
+
+        await confirmRegenerate();
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
+            expect(routerPostMock).toHaveBeenCalledWith(
+                "/user/two-factor-recovery-codes",
+                {},
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+    });
+
+    it("POST 成功 → GET 成功で新コードを表示し success トーストを出す", async () => {
+        stubFetchRoutes({ recent: true, codes: ["new-code-1", "new-code-2"], codesOk: true });
+        render(Security, { props: {} });
+
+        await confirmRegenerate();
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalled();
+        });
+        lastVisitOptions().onSuccess?.();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recovery-codes")).toHaveTextContent("new-code-1");
+        });
+        expect(addToastMock).toHaveBeenCalledWith(
+            "success",
+            expect.stringContaining("再生成しました"),
+        );
+    });
+
+    it("POST 成功 → GET 失敗では旧コードを残さず error トースト + 再試行導線に戻る", async () => {
+        stubFetchRoutes({ recent: true, codesOk: false });
+        render(Security, { props: {} });
+
+        await confirmRegenerate();
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalled();
+        });
+        lastVisitOptions().onSuccess?.();
+
+        await waitFor(() => {
+            expect(addToastMock).toHaveBeenCalledWith(
+                "error",
+                expect.stringContaining("以前のコードは既に無効です"),
+            );
+        });
+        expect(screen.queryByTestId("recovery-codes")).toBeNull();
+        expect(screen.getByTestId("show-recovery-codes-button")).toBeInTheDocument();
+    });
+
+    it("POST 実行中 (onStart〜onFinish) は確認ボタンが processing (aria-busy) になる", async () => {
+        stubFetchRoutes({ recent: true });
+        render(Security, { props: {} });
+
+        await confirmRegenerate();
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalled();
+        });
+
+        const options = lastVisitOptions();
+        options.onStart?.();
+        await waitFor(() => {
+            // Button atom は loading 中 aria-busy を立てる (二重送信抑止の回帰固定)
+            expect(screen.getByRole("button", { name: "再生成する" })).toHaveAttribute(
+                "aria-busy",
+                "true",
+            );
+        });
+
+        options.onFinish?.();
+        await waitFor(() => {
+            expect(screen.getByRole("button", { name: "再生成する" })).not.toHaveAttribute(
+                "aria-busy",
+            );
+        });
+    });
+});
+
+describe("Settings/Security リカバリコード表示 (recent-auth precheck)", () => {
+    it("fresh なら「リカバリコードを表示」でコード一覧を取得して表示する", async () => {
+        stubFetchRoutes({ recent: true, codes: ["code-a", "code-b"] });
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("show-recovery-codes-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recovery-codes")).toHaveTextContent("code-a");
+        });
+    });
+
+    it("stale なら再認証モーダルを開き、コードを取得しない", async () => {
+        stubFetchRoutes({ recent: false });
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("show-recovery-codes-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("recovery-codes")).toBeNull();
+        // /user/two-factor-recovery-codes への GET は発火していない (status のみ)
+        const requestedUrls = fetchMock.mock.calls.map((call) => String(call[0]));
+        expect(requestedUrls).not.toContain("/user/two-factor-recovery-codes");
+    });
+});
```

# 新規ファイル tests/Feature/Auth/TwoFactorRecoveryCodesStepUpTest.php

```php
<?php

declare(strict_types=1);

use App\Models\User;

/*
 * リカバリコード表示 (GET) / 再生成 (POST) の recent-auth (step-up) 配線。
 *
 * Fortify 登録ルートには FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が
 * booted callback で recent-auth middleware を後付けする。ここではその実効性
 * (stale で遮断 / fresh で通過) を HTTP 経由で検証する。allowlist の付与漏れ検出は
 * RecentAuthRouteTest (Architecture) 側。
 */

test('鮮度なしの GET リカバリコード (XHR) は 409 recent_auth_required でコードを返さない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->getJson('/user/two-factor-recovery-codes')
        ->assertStatus(409)
        ->assertJson([
            'code' => 'recent_auth_required',
            'redirect' => route('recent-auth.confirm'),
        ]);
});

test('鮮度なしの POST 再生成 (XHR) は 409 recent_auth_required で旧コードを失効させない', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();
    $before = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->postJson('/user/two-factor-recovery-codes')
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');

    $user->refresh();
    expect($user->two_factor_recovery_codes)->toBe($before);
});

test('鮮度なしの通常 POST 再生成は recent-auth confirm へ 302 する', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->post('/user/two-factor-recovery-codes')
        ->assertRedirect(route('recent-auth.confirm'));
});

test('fresh なら GET がコード一覧を返し POST が再生成する', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();
    $before = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->getJson('/user/two-factor-recovery-codes')
        ->assertOk()
        ->assertJsonCount(8);

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->postJson('/user/two-factor-recovery-codes')
        ->assertOk();

    $user->refresh();
    expect($user->two_factor_recovery_codes)->not->toBe($before);
});
```
