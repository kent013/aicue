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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリ改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest /
DTO + JsonResource / Laratrust RBAC (Organization → Team → Project 階層)。

【レビュー観点】
1. コードの正確性 (ロジック・エッジケース・null 安全)
2. 既存コードとの整合性 (命名・パターン・API)
3. PHPStan level 10 適合 (型安全・generics・Assert)
4. テスト計画の網羅性 (各施策に Pest/vitest、RefreshDatabase グローバル)
5. DTO/JsonResource 遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性 (TS 型・Resource・テストが変更対象に含まれるか)
9. セキュリティ (認可・入力検証・AGENTS.md 不変条件)
10. DESIGN.md 準拠 (token 経由・hex 直書きを増やさないか)
11. Atomic Design 準拠 (atoms/molecules/organisms/templates 責務、Lucide アイコン)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] 分類、Critical/Warning に修正案必須
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（下記は devnotes/20260715-2302-notifications-read-all-empty-state/detailed-design.md の内容）

### 施策1: NotificationController::index に unreadCount prop 追加
Inertia::render の配列に `'unreadCount' => $this->notifications->unreadCountFor($user)` を追加。
既存 `NotificationCenterService::unreadCountFor(User): int` (全 org 横断・自分宛のみ = `$user->unreadNotifications()->count()`) を使用。
notifications / meta の shape は不変。DTO 契約変更なし。

理由: HandleInertiaRequests の shared prop `notifications.unreadCount` は Index ページ固有 prop
`notifications` (NotificationItem[]) とキー衝突し配列で上書きされるため Index からは読めない。
専用 scalar prop `unreadCount` を渡す。グローバル未読数を使う (ページャ現在ページのリストだけで
判定すると 2 ページ目に未読があるのに 1 ページ目全既読でボタンが消えるため)。

### 施策2: Index.svelte で read-all ボタンを未読0時に非表示
Props に `unreadCount: number` 追加 (必須、undefined 逃がしなし)。read-all ボタンを `{#if unreadCount > 0}` で条件描画。
可視時の in-flight 連打ガード markingAll は維持。JSDoc を「未読 0 では非表示 (禁止事項 #8 準拠で hide であって
disable ではない)」+「shared notifications.unreadCount はキー衝突で参照不可のため専用 prop を使う」に更新。
ヘッダは `flex items-start justify-between`。非表示時は右カラムが空になるが左見出しは維持 (崩れなし)。

### 施策3: Feature テスト (NotificationCenterTest.php 追加)
- 新規: `index: 未読数を unreadCount prop で渡す` — Factory で自分宛未読 N + 既読 M → `where('unreadCount', N)`。
- 新規: `index: 全既読なら unreadCount=0`。
- 既存 index テスト非退行。個別 DatabaseTransactions 不使用。

### 施策4: vitest テスト (NotificationsIndex.test.ts)
- 更新: 「read-all は disabled でなく押下で POST」を `unreadCount: 1` で render。
- 新規: `unreadCount: 0` → `queryByTestId('read-all-button')` null。
- 新規: `unreadCount: 3` → ボタン可視。
- 既存の EmptyState / 一覧描画テストは unreadCount prop を付与して非退行。

### 実装モード: standalone (小さな独立変更)。

---

## 関連する現行コード

### app/Http/Controllers/NotificationController.php::index
```php
public function index(Request $request): Response
{
    $user = $this->authedUser($request);
    $paginator = $this->notifications->paginateFor($user);
    $items = [];
    foreach ($paginator->items() as $notification) {
        Assert::isInstanceOf($notification, DatabaseNotification::class);
        $items[] = NotificationListItemData::fromNotification($notification)->toArray();
    }
    return Inertia::render('Notifications/Index', [
        'notifications' => $items,
        'meta' => [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ],
    ]);
}
```

### App\Services\Notification\NotificationCenterService::unreadCountFor
```php
public function unreadCountFor(User $user): int
{
    return $user->unreadNotifications()->count();
}
```

### resources/js/pages/Notifications/Index.svelte (抜粋)
```svelte
interface Props {
    notifications: NotificationItem[];
    meta: PaginationMeta;
}
let { notifications, meta }: Props = $props();
let markingAll = $state(false);
function markAllRead(): void {
    if (markingAll) return;
    router.post("/notifications/read-all", {}, { onStart: () => { markingAll = true; }, onFinish: () => { markingAll = false; } });
}
...
<div class="flex items-start justify-between gap-4">
    <div> <h1 class="text-h2">通知</h1> ... </div>
    <Button variant="ghost" size="sm" onclick={markAllRead} testId="read-all-button">すべて既読にする</Button>
</div>
{#if notifications.length === 0}
    <EmptyState title="通知はありません" ... testId="notifications-empty" />
{:else}
    <Card ...><ul data-testid="notification-list"> ... </ul></Card>
{/if}
```

### tests/js/pages/NotificationsIndex.test.ts (更新対象テスト)
```ts
it("read-all ボタンは disabled でなく、押下で POST /notifications/read-all", async () => {
    render(NotificationsIndex, { props: { notifications: [], meta } });
    const button = screen.getByTestId("read-all-button");
    expect(button).not.toHaveAttribute("disabled");
    await fireEvent.click(button);
    expect(routerMock.post).toHaveBeenCalledTimes(1);
    expect(routerMock.post.mock.calls[0][0]).toBe("/notifications/read-all");
});
```

### tests/Feature/Notifications/NotificationCenterTest.php (既存 index テスト・ヘルパ)
```php
// helper: notifyManualAnalyzed($organization,$owner,$project,$manual) が自分宛 DB 通知を1件作る
test('index: 自分宛のみ表示 ...', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    notifyManualAnalyzed($organization, $owner, $project, $manual);
    ...
    $this->actingAs($owner)->get('/notifications')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')
            ->has('notifications', 2)->where('meta.total', 2));
});
```

上記詳細設計をレビューしてください。
