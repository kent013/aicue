【アプリの使命 (North Star) — AGENTS.md より】
AI-CUE は現場の作業手順書(SOP)を起点に AI が動画シナリオを生成し、PWA でナビゲーション撮影して標準化マニュアル動画を作る。思考ゼロ・編集ゼロ。

【禁止事項 — AGENTS.md より】
1 テストなし実装完了禁止 / 2 PHPStan widen・baseline 禁止 / 3 dev DB 破壊操作禁止 / 4 response()->json() 直書き禁止 / 5 Prism 直呼び禁止 / 6 prompt 直書き禁止 / 7 操作系 POST 応答で redirect()->intended() 禁止 / 8 必須条件未充足でボタン disabled 禁止(押下時エラー表示)

【ツール使用制限】コマンド実行・書き込み禁止。ファイル読み込みは許可。

---
あなたは Laravel + Svelte 改善実装のコードレビュアーです。以下の観点でレビューしてください。
観点: 設計との一致性 / 正確性(ロジック・エッジ・null 安全) / PHPStan L10 適合 / DTO・JsonResource パターン / テスト網羅性 / セキュリティ / DESIGN.md 準拠(token 経由・hex 直書きを増やさない) / Atomic Design 準拠(atoms/molecules/organisms/features の責務・Lucide アイコン・SVG 直書きを増やさない)。
出力: ファイルごとに判定、指摘は [Critical]/[Warning]/[Suggestion] 分類、Critical/Warning に修正案、全体判定 APPROVED / CHANGES_REQUESTED、日本語。

---

## 詳細設計書(要点)
未読通知の各行に個別「既読」ボタンを追加。open(行クリック=既読+遷移)/ read-all(一括)は維持。read ボタンは POST /notifications/{id}/read(back() 完結)で遷移せず 1 件既読化。DOM は「外側 div + open(content)ボタン + 右上絶対配置の read ボタン(button ネスト回避)」。source of truth はサーバ props、楽観 state は単調(未読→既読)で onError 復帰、unread=read_at===null && !optimisticallyRead で prop 優先。二重送信は router.post 前に reading/opening を同期設定するガードで防止。read/open 相互排他。成功時 tick 後に open ボタンへ focus 移動。失敗時 addToast('error',...)。純フロント(backend/route/DTO/型変更なし)。禁止事項#8: disabled 不使用・表示/非表示制御。

## 実装差分（git diff）
```diff
diff --git a/resources/js/components/features/notifications/NotificationListItem.svelte b/resources/js/components/features/notifications/NotificationListItem.svelte
index 5889039..440add5 100644
--- a/resources/js/components/features/notifications/NotificationListItem.svelte
+++ b/resources/js/components/features/notifications/NotificationListItem.svelte
@@ -1,8 +1,9 @@
 <script lang="ts">
-    import type { Component } from "svelte";
+    import { tick, type Component } from "svelte";
     import { router } from "@inertiajs/svelte";
-    import { Bell, FileSearch, Film, Mail, TicketMinus } from "@lucide/svelte";
+    import { Bell, Check, FileSearch, Film, Mail, TicketMinus } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
+    import { addToast } from "@/lib/stores/toast";
     import type {
         InvitationReceivedPayload,
         ManualJobPayload,
@@ -14,6 +15,8 @@
      * 通知一覧の 1 行。type ごとにアイコン・文言を組み立てる。
      * 行クリック = POST /notifications/{id}/open (サーバが既読化 + 遷移先を解決する 303。
      * GET にしない = prefetch による意図しない既読化防止)。
+     * 右上の個別「既読」ボタン = POST /notifications/{id}/read (遷移せず 1 件だけ既読化。
+     * back() 完結)。未読行のみ表示 (禁止事項#8: disabled にせず表示/非表示で制御)。
      * 未知 type (enum⇔TS の一時的ドリフト) は汎用アイコン + rawType 表示の fallback。
      */
     interface Props {
@@ -22,9 +25,17 @@
 
     let { notification }: Props = $props();
 
-    let opening = $state(false);
+    let opening = $state(false); // open (行クリック) の in-flight
+    let reading = $state(false); // read (個別既読) の in-flight
+    let optimisticallyRead = $state(false); // 楽観既読 (単調・未読→既読方向のみ。onError で復帰)
+    let contentButton = $state<HTMLButtonElement | null>(null); // 既読成功時のフォーカス移動先
 
-    const unread = $derived(notification.read_at === null);
+    // read_at (prop = source of truth) を最優先。楽観 state は「未読→既読」方向のみ足す
+    // (read-all 等が prop.read_at を確定すれば楽観 state に関わらず既読表示となり乖離しない)。
+    const unread = $derived(notification.read_at === null && !optimisticallyRead);
+    // 既読ボタンの表示条件を明示 derived で分離。未読の間、または in-flight 中
+    // (楽観既読で unread=false になっても aria-busy を見せる) は DOM に残す。
+    const showReadButton = $derived(unread || reading);
 
     // payload の判別は type discriminant + null 検査 (サーバ側で検証復元済み)
     const manualPayload = $derived(
@@ -114,14 +125,12 @@
     }
 
     function open(): void {
-        if (opening) return; // 連打ガード (disabled 属性ではなく送信ガード)
+        if (opening || reading) return; // read/open in-flight ガード (disabled ではなく送信ガード)
+        opening = true; // router.post 前に同期設定 (onStart 待ちの競合窓を閉じる)
         router.post(
             `/notifications/${notification.id}/open`,
             {},
             {
-                onStart: () => {
-                    opening = true;
-                },
                 onFinish: () => {
                     opening = false;
                 },
@@ -129,43 +138,97 @@
         );
     }
 
+    /**
+     * 個別既読化。遷移せず 1 件だけ既読にする (read route は back() 完結)。
+     * ガード通過直後・router.post 前に reading=true を同期設定して二重送信窓を閉じる。
+     */
+    async function markRead(event: MouseEvent): Promise<void> {
+        event.stopPropagation(); // 兄弟要素だが将来 wrapper に click を置く変更への防御
+        if (reading || opening || !unread) return; // read/open in-flight ガード + 既読には無反応
+        reading = true;
+        router.post(
+            `/notifications/${notification.id}/read`,
+            {},
+            {
+                preserveScroll: true,
+                onSuccess: () => {
+                    optimisticallyRead = true; // 楽観既読 (サーバ back() 再読込が prop を確定)
+                },
+                onError: () => {
+                    optimisticallyRead = false; // defensive reset (単調前提が崩れても未読へ戻す)
+                    addToast("error", "既読にできませんでした。再試行してください。");
+                },
+                onFinish: async () => {
+                    reading = false;
+                    // 成功でボタンが DOM から消える場合、DOM 確定 (tick) を待って
+                    // 行の open ボタンへフォーカスを移す (フォーカスロスト防止)
+                    if (optimisticallyRead) {
+                        await tick();
+                        contentButton?.focus();
+                    }
+                },
+            },
+        );
+    }
+
     const Icon = $derived(icon);
 </script>
 
-<button
-    type="button"
-    onclick={open}
-    class="flex w-full items-start gap-3 border-b border-border px-4 py-3 text-left
-        hover:bg-neutral {unread ? 'bg-primary-soft/40' : 'bg-surface'}"
-    data-testid="notification-item"
-    data-unread={unread}
+<div
+    class="relative flex items-stretch border-b border-border
+        {unread ? 'bg-primary-soft/40' : 'bg-surface'}"
+    data-testid="notification-item-row"
 >
-    <span
-        class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md
-            {unread ? 'bg-primary-soft text-primary' : 'bg-neutral text-text-secondary'}"
-        aria-hidden="true"
+    <!-- 主操作: open (行の hit area を保持)。右端は既読ボタン用に pr-12 を常時確保 -->
+    <button
+        type="button"
+        onclick={open}
+        bind:this={contentButton}
+        class="flex min-w-0 flex-1 items-start gap-3 px-4 py-3 pr-12 text-left hover:bg-neutral"
+        data-testid="notification-item"
+        data-unread={unread}
     >
-        <Icon class="size-4" />
-    </span>
-    <span class="min-w-0 flex-1">
-        <span class="block text-body {unread ? 'font-medium' : ''} text-text">{title}</span>
-        {#if body !== null}
-            <span class="mt-0.5 block truncate text-caption text-text-secondary">{body}</span>
-        {/if}
-        <span class="mt-1 flex items-center gap-2">
-            {#if organizationName !== null}
-                <Badge tone="neutral" size="sm">{organizationName}</Badge>
+        <span
+            class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md
+                {unread ? 'bg-primary-soft text-primary' : 'bg-neutral text-text-secondary'}"
+            aria-hidden="true"
+        >
+            <Icon class="size-4" />
+        </span>
+        <span class="min-w-0 flex-1">
+            <span class="block text-body {unread ? 'font-medium' : ''} text-text">{title}</span>
+            {#if body !== null}
+                <span class="mt-0.5 block truncate text-caption text-text-secondary">{body}</span>
             {/if}
-            <span class="text-caption text-text-secondary">
-                {relativeTime(notification.created_at)}
+            <span class="mt-1 flex items-center gap-2">
+                {#if organizationName !== null}
+                    <Badge tone="neutral" size="sm">{organizationName}</Badge>
+                {/if}
+                <span class="text-caption text-text-secondary">
+                    {relativeTime(notification.created_at)}
+                </span>
+                {#if unread}
+                    <span
+                        class="inline-block size-2 shrink-0 rounded-sm bg-primary"
+                        aria-label="未読"
+                        data-testid="unread-dot"
+                    ></span>
+                {/if}
             </span>
         </span>
-    </span>
-    {#if unread}
-        <span
-            class="mt-2 inline-block size-2 shrink-0 rounded-sm bg-primary"
-            aria-label="未読"
-            data-testid="unread-dot"
-        ></span>
+    </button>
+    <!-- 副操作: 個別既読 (遷移しない)。未読 or in-flight のとき右上に絶対配置 -->
+    {#if showReadButton}
+        <button
+            type="button"
+            onclick={(e) => markRead(e)}
+            aria-label={reading ? "既読処理中" : "既読にする"}
+            aria-busy={reading}
+            class="absolute top-2 right-2 inline-flex size-8 items-center justify-center
+                rounded-md text-text-secondary hover:bg-neutral hover:text-text"
+            data-testid="notification-read-button"
+        >
+            <Check class="size-4" />
+        </button>
     {/if}
-</button>
+</div>
diff --git a/tests/js/components/features/NotificationListItem.test.ts b/tests/js/components/features/NotificationListItem.test.ts
index 610e7f5..9d08194 100644
--- a/tests/js/components/features/NotificationListItem.test.ts
+++ b/tests/js/components/features/NotificationListItem.test.ts
@@ -1,13 +1,26 @@
 import { beforeEach, describe, expect, it, vi } from "vitest";
-import { fireEvent, render, screen } from "@testing-library/svelte";
+import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
 import NotificationListItem from "@/components/features/notifications/NotificationListItem.svelte";
 import type { NotificationItem } from "@/types/notification";
 
-// 行クリックの POST (open) は router をモックして検証する
+/** router.post に渡す visit options のうち本テストで発火させるコールバック部分 */
+interface ReadVisitOptions {
+    preserveScroll?: boolean;
+    onSuccess?: () => void;
+    onError?: () => void;
+    onFinish?: () => void | Promise<void>;
+}
+
+// 行クリックの POST (open) / 個別既読 (read) は router をモックして検証する
 const { routerPostMock } = vi.hoisted(() => ({
     routerPostMock: vi.fn(),
 }));
 
+// 既読失敗時の toast は addToast をモックして検証する
+const { addToastMock } = vi.hoisted(() => ({
+    addToastMock: vi.fn(),
+}));
+
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
     router: {
@@ -15,6 +28,17 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     },
 }));
 
+vi.mock("@/lib/stores/toast", () => ({
+    addToast: addToastMock,
+}));
+
+/** router.post mock に渡された最後の read visit options を取り出す */
+function lastReadOptions(): ReadVisitOptions {
+    const call = routerPostMock.mock.calls.find((c) => String(c[0]).endsWith("/read"));
+    if (!call) throw new Error("read POST が発火していません");
+    return call[2] as ReadVisitOptions;
+}
+
 function manualAnalyzedItem(overrides: Partial<NotificationItem> = {}): NotificationItem {
     return {
         id: "11111111-1111-1111-1111-111111111111",
@@ -36,6 +60,7 @@ function manualAnalyzedItem(overrides: Partial<NotificationItem> = {}): Notifica
 
 beforeEach(() => {
     routerPostMock.mockReset();
+    addToastMock.mockReset();
 });
 
 describe("NotificationListItem", () => {
@@ -138,4 +163,97 @@ describe("NotificationListItem", () => {
         expect(screen.getByText("招待元組織 に招待されています")).toBeInTheDocument();
         expect(screen.getByText("メールの受諾リンクから参加してください")).toBeInTheDocument();
     });
+
+    it("未読行には個別既読ボタンを表示する", () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+        expect(screen.getByTestId("notification-read-button")).toBeInTheDocument();
+    });
+
+    it("既読行 (read_at 非 null) には個別既読ボタンを表示しない", () => {
+        render(NotificationListItem, {
+            props: { notification: manualAnalyzedItem({ read_at: new Date().toISOString() }) },
+        });
+        expect(screen.queryByTestId("notification-read-button")).toBeNull();
+    });
+
+    it("既読ボタン押下で POST /notifications/{id}/read が preserveScroll + 各コールバック付きで 1 回発火し、open は呼ばれない", async () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+
+        await fireEvent.click(screen.getByTestId("notification-read-button"));
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+        const [url, payload, options] = routerPostMock.mock.calls[0];
+        expect(url).toBe("/notifications/11111111-1111-1111-1111-111111111111/read");
+        expect(payload).toEqual({});
+        expect(options).toMatchObject({
+            preserveScroll: true,
+            onSuccess: expect.any(Function),
+            onError: expect.any(Function),
+            onFinish: expect.any(Function),
+        });
+        // 遷移しない = open URL は呼ばれない
+        expect(routerPostMock.mock.calls.some((c) => String(c[0]).endsWith("/open"))).toBe(false);
+    });
+
+    it("既読成功 (onSuccess+onFinish) で該当行が既読表示になり、read ボタンが消え、フォーカスが open ボタンへ移る", async () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+
+        await fireEvent.click(screen.getByTestId("notification-read-button"));
+        const options = lastReadOptions();
+        options.onSuccess?.();
+        await options.onFinish?.();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("notification-item")).toHaveAttribute("data-unread", "false");
+        });
+        expect(screen.queryByTestId("unread-dot")).toBeNull();
+        expect(screen.queryByTestId("notification-read-button")).toBeNull();
+        expect(document.activeElement).toBe(screen.getByTestId("notification-item"));
+    });
+
+    it("既読失敗 (onError) で addToast('error', ...) が呼ばれ、行は未読のまま", async () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+
+        await fireEvent.click(screen.getByTestId("notification-read-button"));
+        const options = lastReadOptions();
+        options.onError?.();
+        await options.onFinish?.();
+
+        expect(addToastMock).toHaveBeenCalledWith("error", expect.stringContaining("既読にできませんでした"));
+        await waitFor(() => {
+            expect(screen.getByTestId("notification-item")).toHaveAttribute("data-unread", "true");
+        });
+        // 再試行できるようボタンは残る
+        expect(screen.getByTestId("notification-read-button")).toBeInTheDocument();
+    });
+
+    it("二重送信防止: コールバック未発火のまま既読ボタンを 2 回押しても read POST は 1 回のみ", async () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+
+        const button = screen.getByTestId("notification-read-button");
+        await fireEvent.click(button);
+        await fireEvent.click(button);
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+
+    it("open/read 相互排他: open が in-flight (コールバック未発火) の間に既読を押しても追加 POST は発生しない", async () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+
+        await fireEvent.click(screen.getByTestId("notification-item")); // open in-flight
+        await fireEvent.click(screen.getByTestId("notification-read-button"));
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+        expect(routerPostMock.mock.calls[0][0]).toBe(
+            "/notifications/11111111-1111-1111-1111-111111111111/open",
+        );
+    });
+
+    it("排他 (逆方向): open (行) クリックで read URL は呼ばれない", async () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+
+        await fireEvent.click(screen.getByTestId("notification-item"));
+
+        expect(routerPostMock.mock.calls.some((c) => String(c[0]).endsWith("/read"))).toBe(false);
+    });
 });
```

## テスト結果
- vitest NotificationListItem: 15 passed
- pnpm test 全体: 81 files / 776 passed
- pnpm typecheck / lint / build: OK
- composer test: 1786 passed / 2 skipped / 0 failed
- composer phpstan: No errors / pint: passed

## design system 参照
- DESIGN.md token 経由のユーティリティ(bg-primary-soft, text-text-secondary, rounded-md, border-border 等)のみ使用。hex 直書きなし。
- アイコンは @lucide/svelte の Check を使用(SVG 直書きなし)。
- 変更は components/features/notifications 配下に閉じる(Atomic 階層逸脱なし)。addToast は既存 @/lib/stores/toast を再利用。
