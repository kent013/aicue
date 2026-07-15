# 詳細設計: notifications-read-all-empty-state

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを
生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも
標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ /
撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項
1. テストなしの実装完了報告。2. PHPStan エラーの widen・baseline 化。3. dev DB 破壊操作。
4. `response()->json()` 直書き(DTO/JsonResource/Inertia)。5. Prism 直呼び。6. prompt 直書き。
7. 操作系 POST 応答での `redirect()->intended()`。8. 必須条件未充足を理由にボタンを disabled にする UI。

### コーディングルール
- PHPStan level 10 / Pest / RefreshDatabase + --parallel / テストは Factory 生成 /
  DTO+JsonResource / アーリーリターン / Pint / PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TS。

## 概念設計リファレンス
- `devnotes/20260715-2302-notifications-read-all-empty-state/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | index に unreadCount prop 追加 | app/Http/Controllers/NotificationController.php | Low |
| 2 | read-all ボタンの未読0時 非表示 | resources/js/pages/Notifications/Index.svelte | Low |
| 3 | Feature テスト (unreadCount prop) | tests/Feature/Notifications/NotificationCenterTest.php | Low |
| 4 | vitest テスト (条件描画) | tests/js/pages/NotificationsIndex.test.ts | Low |

---

## 施策1: NotificationController::index に unreadCount prop を追加

### 変更箇所
- ファイル: `app/Http/Controllers/NotificationController.php` (`index`, L37-58)

### 波及変更
- TypeScript型定義: 施策2 の Index.svelte Props (`unreadCount: number`)。
- API Resource/DTO: なし (Inertia props への scalar 追加。既存 DTO/shape は不変)。
- テストファイル: 施策3 (Feature), 施策4 (vitest)。

### 現行コード
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

### 変更後コード
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
        // 未読 0 件時に「すべて既読にする」ボタンを非表示にするための専用 prop。
        // shared prop notifications.unreadCount はページ固有 prop `notifications`
        // (配列) とキー衝突し Index からは読めないため、ページ専用 scalar として渡す。
        'unreadCount' => $this->notifications->unreadCountFor($user),
        'meta' => [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ],
    ]);
}
```

### PHPStan適合チェック
- [x] `unreadCountFor(User): int` は既存・型明示済み。追加は int scalar。
- [x] null 安全 (`authedUser` が Assert で User 確定)。
- [x] DTO/配列返却の新規なし (既存 shape 不変)。
- [x] Generics 影響なし。

### テスト計画
- 施策3 参照。

### リスク
- `unreadCountFor` は count クエリ 1 本の追加。index は既に paginate クエリを実行しており、
  負荷影響は軽微。機能後退リスクなし (prop 追加のみ)。

---

## 施策2: Index.svelte で read-all ボタンを未読0時に非表示

### 変更箇所
- ファイル: `resources/js/pages/Notifications/Index.svelte` (Props L19-24, JSDoc L14-18, ボタン L60-63)

### 波及変更
- TypeScript型定義: Props interface に `unreadCount: number` 追加 (このファイル内)。
- API Resource/DTO: なし。
- テストファイル: 施策4。

### 現行コード
```svelte
/**
 * 通知一覧 (全 org 横断 = 自分宛のみ)。行クリックはサーバ解決の open (POST + 303)。
 * 「すべて既読にする」は未読 0 でも disabled にしない (押下時は成功 flash のみ。
 * 連打ノイズは in-flight 送信ガードで抑止する = disabled 属性ではなくハンドラ内 guard)。
 */
interface Props {
    notifications: NotificationItem[];
    meta: PaginationMeta;
}

let { notifications, meta }: Props = $props();
...
<Button variant="ghost" size="sm" onclick={markAllRead} testId="read-all-button">
    すべて既読にする
</Button>
```

### 変更後コード
```svelte
/**
 * 通知一覧 (全 org 横断 = 自分宛のみ)。行クリックはサーバ解決の open (POST + 303)。
 * 「すべて既読にする」は未読 0 件のとき非表示にする (既読化する対象が無い操作は
 * 見せない = 禁止事項 #8 準拠。disabled で無反応にするのではなく hide)。未読が 1 件以上の
 * ときは表示し、連打ノイズは in-flight 送信ガード (markingAll) で抑止する (disabled 属性ではない)。
 * 未読数は専用 prop `unreadCount` を使う。shared prop notifications.unreadCount は
 * ページ固有 prop `notifications` (配列) とキー衝突し参照できないため参照しない。
 */
interface Props {
    notifications: NotificationItem[];
    meta: PaginationMeta;
    unreadCount: number;
}

let { notifications, meta, unreadCount }: Props = $props();
...
{#if unreadCount > 0}
    <Button variant="ghost" size="sm" onclick={markAllRead} testId="read-all-button">
        すべて既読にする
    </Button>
{/if}
```

補足: ボタンはヘッダ右側 (`flex items-start justify-between`) に置かれている。非表示時は
右カラムが空になるが、左の見出しは `justify-between` の左寄せで自然に維持される (レイアウト崩れなし)。

### PHPStan適合チェック
- N/A (フロント)。pnpm typecheck / lint で担保。

### テスト計画
- 施策4 参照。

### リスク
- 未読0時にヘッダ右が空になるが視覚上の問題なし。既存の EmptyState 分岐 (notifications.length===0)
  とは独立 (未読有無 ≠ リスト有無。全既読でリストは非空でもボタンは消える = 正しい挙動)。

---

## 施策3: Feature テスト (index が unreadCount prop を渡す)

### 変更箇所
- ファイル: `tests/Feature/Notifications/NotificationCenterTest.php` (index 系テスト群に追加)

### 波及変更
- なし (テスト追加のみ)。

### テスト計画
- [ ] 新規: `index: 未読数を unreadCount prop で渡す (自分宛のみカウント)` — `notificationCenterContext()` で
      対象ユーザーを明示生成し、自分宛の未読 N 件を `notifyManualAnalyzed(...)` で作る。さらに
      **別ユーザー宛の通知を混ぜても自分宛のみ数える**ことを同時検証 (他ユーザーを attach し
      `notifyManualAnalyzed($organization, $other, ...)`)。1 件を `read` して既読化し、
      `where('unreadCount', N)` (= 残り未読数) を assert。ownership 混入による flaky を排除する。
- [ ] 新規 (境界): `index: 全既読なら unreadCount=0` — 通知を作り全て既読化 (`markAllRead` 相当 or 個別 read)
      → `where('unreadCount', 0)`。
- [ ] 既存の index テスト (自分宛のみ表示・ページネーション) は非退行 (prop 追加は既存 assert に無影響)。
- [x] 個別 `DatabaseTransactions` 不使用 (Pest.php グローバル RefreshDatabase)。
- テストデータは既存テストと同じヘルパ (`notificationCenterContext` / `notifyManualAnalyzed`) を踏襲する。
- RefreshDatabase により各テストは独立 DB。対象ユーザーの未読は明示生成分のみ = カウントは決定的。

### リスク
- なし。

---

## 施策4: vitest テスト (read-all ボタン条件描画)

### 変更箇所
- ファイル: `tests/js/pages/NotificationsIndex.test.ts`

### 現行の関連テスト (更新対象)
- `read-all ボタンは disabled でなく、押下で POST /notifications/read-all` は
  `notifications: []` で描画し常時可視前提。→ `unreadCount` prop 追加に伴い更新する。

### テスト計画
- [ ] **[Critical 対応] 共通 props ヘルパ導入**: `unreadCount` を必須 prop 化するため、ファイル冒頭に
      `function baseProps(overrides: Partial<Props> = {}): Props { return { notifications: [], meta, unreadCount: 0, ...overrides }; }`
      を定義し (戻り値 `Props`・引数 `Partial<Props>` を明示してキー誤字/型不正をコンパイル時検出)、
      **全 render 呼び出しを `render(NotificationsIndex, { props: baseProps({...}) })` に統一**する
      (追従漏れによる型/実行時不整合を防ぐ)。Props 型はページの `interface Props` を `import type` で
      共有できない場合、テスト内にローカル型 (`{ notifications: NotificationItem[]; meta: PaginationMeta; unreadCount: number }`)
      を定義してもよい。
- [ ] 更新: 既存「read-all は disabled でなく押下で POST」→ テスト名を
      `未読あり時、read-all ボタンは disabled でなく、押下で POST /notifications/read-all` に変更し
      `baseProps({ unreadCount: 1 })` で render。ボタン可視 + `not.toHaveAttribute('disabled')` +
      クリックで `router.post('/notifications/read-all')`。
- [ ] 新規: `未読 0 件なら read-all ボタンを描画しない` — `baseProps({ unreadCount: 0 })` で render し
      `screen.queryByTestId('read-all-button')` が null **かつ**
      `screen.queryByRole('button', { name: 'すべて既読にする' })` が null (アクセシビリティ観点併用)。
- [ ] 新規: `未読ありなら read-all ボタンを描画する` — `baseProps({ unreadCount: 3 })` で
      `getByTestId('read-all-button')` 可視。
- [ ] 既存の「0 件時は EmptyState」「通知がある場合は一覧描画」テストも `baseProps(...)` 経由に統一して非退行
      (EmptyState は notifications 配列基準、read-all は unreadCount 基準で独立。一覧描画テストは
      `baseProps({ notifications: [item('a'), item('b')], meta: {...}, unreadCount: 2 })` 等)。

### リスク
- なし。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 単一 topic の小さな独立変更。controller + svelte + 2 テストで完結し他施策と干渉しない。 |
| 競合リスク | 低 (NotificationController::index と Notifications/Index.svelte は他 in-flight タスクと非競合の想定)。 |
