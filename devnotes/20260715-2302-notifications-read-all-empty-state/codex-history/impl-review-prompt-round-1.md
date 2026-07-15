## アプリの使命 (North Star)
**AI-CUE**: 現場の作業手順書(SOP)を起点に AI が動画シナリオを生成し、PWA でナビ撮影して
標準化マニュアル動画を作る。「思考ゼロ・編集ゼロ」。v1: 字幕のみ / PWA 撮影 / 自前 ffmpeg / 単一 Default Project。

## 禁止事項
1. テストなし実装完了報告。2. PHPStan widen・baseline。3. dev DB 破壊操作。4. `response()->json()` 直書き。
5. Prism 直呼び。6. prompt 直書き。7. 操作系 POST 応答での `redirect()->intended()`。
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)。

【思考原則】仮説を立てろ。データに真摯に。先人の知恵を探せ。機能の名前に立ち返れ。
【ツール使用制限】コマンド実行・ファイル書き込みは行わず、テキスト分析に集中。ファイル読み込みは許可。

---

あなたはコードレビュアーです。Laravel + Svelte アプリの改善実装をレビューしてください。

【レビュー観点】設計との一致性 / 正確性 (ロジック・エッジケース・null 安全) / PHPStan level 10 適合 /
DTO/JsonResource パターン / テスト網羅性 / セキュリティ (認可・入力検証・AGENTS.md 不変条件) /
DESIGN.md 準拠 (token 経由・hex 直書きを増やさないか) / Atomic Design 準拠 (atoms/molecules/organisms/templates
責務、Lucide アイコン、SVG 直書きを増やさないか)。

【出力形式】ファイルごとに判定、指摘は [Critical] [Warning] [Suggestion] 分類 (Critical/Warning に修正案必須)、
全体判定 APPROVED / CHANGES_REQUESTED。日本語。

---

## 詳細設計書 (要約)

bug-hunt F-4-01: /notifications で未読0件時に「すべて既読にする」read-all ボタンを非表示にする。

- 施策1: `NotificationController::index` に `'unreadCount' => $this->notifications->unreadCountFor($user)` を追加
  (既存 Service メソッド、全 org 横断・自分宛のみの count)。shared prop `notifications.unreadCount` は
  ページ固有 prop `notifications` 配列とキー衝突し読めないため専用 scalar prop を渡す。
- 施策2: `Notifications/Index.svelte` の Props に `unreadCount: number` (必須) を追加し read-all ボタンを
  `{#if unreadCount > 0}` で条件描画。可視時の in-flight 連打ガード (markingAll) は維持。JSDoc 更新。
- 施策3: Feature テスト (unreadCount prop を自分宛のみ・既読除外で決定的に検証、全既読→0)。
- 施策4: vitest (baseProps ヘルパで全 render を unreadCount 必須に統一。未読0→非表示 (testId + role 両方 null)、
  未読あり→表示、既存非退行)。

## 実装差分 (git diff)

```diff
diff --git a/app/Http/Controllers/NotificationController.php b/app/Http/Controllers/NotificationController.php
index d95f800..6f4dd99 100644
--- a/app/Http/Controllers/NotificationController.php
+++ b/app/Http/Controllers/NotificationController.php
@@ -47,6 +47,10 @@ public function index(Request $request): Response
 
         return Inertia::render('Notifications/Index', [
             'notifications' => $items,
+            // 未読 0 件時に「すべて既読にする」ボタンを非表示にするための専用 prop。
+            // shared prop notifications.unreadCount はページ固有 prop `notifications` (配列) と
+            // キー衝突し Index からは読めないため、ページ専用 scalar として明示的に渡す。
+            'unreadCount' => $this->notifications->unreadCountFor($user),
             // 既存 ManualListItem のページャ shape (ProjectController::manualRows) と同形
             'meta' => [
                 'current_page' => $paginator->currentPage(),
diff --git a/resources/js/pages/Notifications/Index.svelte b/resources/js/pages/Notifications/Index.svelte
index 197e99e..dd43957 100644
--- a/resources/js/pages/Notifications/Index.svelte
+++ b/resources/js/pages/Notifications/Index.svelte
@@ -13,15 +13,19 @@
 
     /**
      * 通知一覧 (全 org 横断 = 自分宛のみ)。行クリックはサーバ解決の open (POST + 303)。
-     * 「すべて既読にする」は未読 0 でも disabled にしない (押下時は成功 flash のみ。
-     * 連打ノイズは in-flight 送信ガードで抑止する = disabled 属性ではなくハンドラ内 guard)。
+     * 「すべて既読にする」は未読 0 件のとき非表示にする (既読化する対象が無い操作は見せない
+     * = 禁止事項 #8 準拠。disabled で無反応にするのではなく hide)。未読が 1 件以上のときは表示し、
+     * 連打ノイズは in-flight 送信ガード (markingAll) で抑止する (disabled 属性ではない)。
+     * 未読数は専用 prop `unreadCount` を使う。shared prop notifications.unreadCount は
+     * ページ固有 prop `notifications` (配列) とキー衝突し参照できないため参照しない。
      */
     interface Props {
         notifications: NotificationItem[];
         meta: PaginationMeta;
+        unreadCount: number;
     }
 
-    let { notifications, meta }: Props = $props();
+    let { notifications, meta, unreadCount }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -57,9 +61,11 @@
                 すべての組織の通知が表示されます。
             </p>
         </div>
-        <Button variant="ghost" size="sm" onclick={markAllRead} testId="read-all-button">
-            すべて既読にする
-        </Button>
+        {#if unreadCount > 0}
+            <Button variant="ghost" size="sm" onclick={markAllRead} testId="read-all-button">
+                すべて既読にする
+            </Button>
+        {/if}
     </div>
 
     {#if notifications.length === 0}
diff --git a/tests/Feature/Notifications/NotificationCenterTest.php b/tests/Feature/Notifications/NotificationCenterTest.php
index c2a8455..2c29ecf 100644
--- a/tests/Feature/Notifications/NotificationCenterTest.php
+++ b/tests/Feature/Notifications/NotificationCenterTest.php
@@ -99,6 +99,43 @@ function notifyManualAnalyzed(Organization $organization, User $user, Project $p
             ->where('meta.current_page', 2));
 });
 
+test('index: 未読数を unreadCount prop で渡す (自分宛のみ・既読を除外)', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+
+    // 自分宛の未読を 3 件発火
+    $ids = [];
+    for ($i = 0; $i < 3; $i++) {
+        $ids[] = notifyManualAnalyzed($organization, $owner, $project, $manual);
+    }
+
+    // 別ユーザー宛の通知は自分の未読数に含めない (自分宛のみカウントを検証)
+    $other = attachOrganizationMember($organization);
+    notifyManualAnalyzed($organization, $other, $project, $manual);
+
+    // 1 件を既読化 → 未読は 2 件 (既読を除外することを検証)
+    $owner->notifications()->whereKey($ids[0])->firstOrFail()->markAsRead();
+
+    $this->actingAs($owner)->get('/notifications')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Notifications/Index')
+            ->where('unreadCount', 2));
+});
+
+test('index: 全既読なら unreadCount=0', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    notifyManualAnalyzed($organization, $owner, $project, $manual);
+    notifyManualAnalyzed($organization, $owner, $project, $manual);
+
+    app(NotificationCenterService::class)->markAllRead($owner);
+
+    $this->actingAs($owner)->get('/notifications')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Notifications/Index')
+            ->where('unreadCount', 0));
+});
+
 test('read: 自分の通知は既読化され back で戻る', function (): void {
     [$organization, $owner, $project, $manual] = notificationCenterContext();
     $id = notifyManualAnalyzed($organization, $owner, $project, $manual);
diff --git a/tests/js/pages/NotificationsIndex.test.ts b/tests/js/pages/NotificationsIndex.test.ts
index 861863f..56a9e6b 100644
--- a/tests/js/pages/NotificationsIndex.test.ts
+++ b/tests/js/pages/NotificationsIndex.test.ts
@@ -2,6 +2,14 @@ import { afterEach, describe, expect, it, vi } from "vitest";
 import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
 import NotificationsIndex from "@/pages/Notifications/Index.svelte";
 import type { NotificationItem } from "@/types/notification";
+import type { PaginationMeta } from "@/types/manual";
+
+/** Index.svelte の Props (unreadCount 必須)。全 render はこの型で統一する */
+interface IndexProps {
+    notifications: NotificationItem[];
+    meta: PaginationMeta;
+    unreadCount: number;
+}
 
 // router をモックし page state は実物を使う (props 未設定の空オブジェクト)
 const { routerMock } = vi.hoisted(() => ({
@@ -13,7 +21,12 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     router: routerMock,
 }));
 
-const meta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };
+const meta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };
+
+/** 全 render の共通 props。unreadCount 必須化に伴う追従漏れを防ぐ (デフォルト 0) */
+function baseProps(overrides: Partial<IndexProps> = {}): IndexProps {
+    return { notifications: [], meta, unreadCount: 0, ...overrides };
+}
 
 function item(id: string): NotificationItem {
     return {
@@ -41,14 +54,14 @@ afterEach(() => {
 
 describe("Notifications/Index", () => {
     it("0 件時は EmptyState を表示する", () => {
-        render(NotificationsIndex, { props: { notifications: [], meta } });
+        render(NotificationsIndex, { props: baseProps() });
 
         expect(screen.getByTestId("notifications-empty")).toBeInTheDocument();
         expect(screen.queryByTestId("notification-list")).toBeNull();
     });
 
-    it("read-all ボタンは disabled でなく、押下で POST /notifications/read-all", async () => {
-        render(NotificationsIndex, { props: { notifications: [], meta } });
+    it("未読あり時、read-all ボタンは disabled でなく、押下で POST /notifications/read-all", async () => {
+        render(NotificationsIndex, { props: baseProps({ unreadCount: 1 }) });
 
         const button = screen.getByTestId("read-all-button");
         expect(button).not.toHaveAttribute("disabled");
@@ -58,12 +71,26 @@ describe("Notifications/Index", () => {
         expect(routerMock.post.mock.calls[0][0]).toBe("/notifications/read-all");
     });
 
+    it("未読 0 件なら read-all ボタンを描画しない", () => {
+        render(NotificationsIndex, { props: baseProps({ unreadCount: 0 }) });
+
+        expect(screen.queryByTestId("read-all-button")).toBeNull();
+        expect(screen.queryByRole("button", { name: "すべて既読にする" })).toBeNull();
+    });
+
+    it("未読ありなら read-all ボタンを描画する", () => {
+        render(NotificationsIndex, { props: baseProps({ unreadCount: 3 }) });
+
+        expect(screen.getByTestId("read-all-button")).toBeInTheDocument();
+    });
+
     it("通知がある場合は一覧を描画する", () => {
         render(NotificationsIndex, {
-            props: {
+            props: baseProps({
                 notifications: [item("a"), item("b")],
                 meta: { ...meta, total: 2 },
-            },
+                unreadCount: 2,
+            }),
         });
 
         expect(screen.getByTestId("notification-list")).toBeInTheDocument();

```

## テスト結果
- Feature: NotificationCenterTest 17 passed (100 assertions)。
- vitest: NotificationsIndex.test.ts 5 passed。全 vitest 780 passed (81 files)。
- composer phpstan: No errors。vendor/bin/pint --test: passed。pnpm typecheck / lint / build: green。
- composer test (全 Feature/Unit): 実行中 (結果は別途確認。既存非退行を確認する)。

上記実装差分をレビューしてください。
