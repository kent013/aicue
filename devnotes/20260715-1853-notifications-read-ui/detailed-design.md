# 詳細設計: notifications-read-ui

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを
生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも
標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。

### 禁止事項（抜粋・関連するもの）
- #2 PHPStan エラーの widen・baseline 化
- #1 テストなしの実装完了
- #8 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
- フロントは Svelte 5 runes + DS token のみ、アイコンは Lucide のみ

### コーディングルール
- PHPStan level 10 / Pest / RefreshDatabase + --parallel / Factory 生成
- 本件は純フロント(PHP 変更なし)。vitest でテスト。
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス
`devnotes/20260715-1853-notifications-read-ui/conceptual-design.md`(APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 個別既読ボタンの追加 | `resources/js/components/features/notifications/NotificationListItem.svelte` | Medium |
| 2 | vitest テスト追加 | `tests/js/components/features/NotificationListItem.test.ts` | Medium |

## 施策1: 個別既読ボタンの追加

### 変更箇所
- `resources/js/components/features/notifications/NotificationListItem.svelte`(全面リファクタ)

### 波及変更
- TypeScript 型定義: なし(`NotificationItem` の既存型で完結)
- API Resource/DTO: なし(backend 変更なし)
- テストファイル: `tests/js/components/features/NotificationListItem.test.ts`(施策2)

### 設計詳細

**DOM 構造**: 現状「行全体が 1 つの `<button onclick={open}>`」→
外側 `<div class="relative ...">` ラッパ + 兄弟 2 要素:
1. content ボタン(open。`data-testid="notification-item"`、`data-unread`、`flex-1`、
   右に既読ボタン用パディング `pr-12` を常時確保、`bind:this={contentButton}`)
2. 既読ボタン(`{#if unread || reading}` のときのみ描画。`absolute right-2 top-2`、
   `data-testid="notification-read-button"`、`aria-label="既読にする"`、`aria-busy={reading}`)

**状態**:
```ts
let opening = $state(false);   // 既存 (open の in-flight)
let reading = $state(false);   // read の in-flight
let optimisticallyRead = $state(false); // 楽観既読 (単調・onError で defensive reset)
let contentButton = $state<HTMLButtonElement | null>(null); // フォーカス移動先

const unread = $derived(notification.read_at === null && !optimisticallyRead);
// 既読ボタンの表示条件を明示 derived で責務分離 (Codex 指摘: 表示条件の暗黙依存を避ける)。
// 未読の間、または in-flight 中 (楽観既読で unread=false になっても aria-busy を見せる) は残す。
const showReadButton = $derived(unread || reading);
```
`unread` は `read_at`(prop, source of truth)を最優先し、楽観 state は「未読→既読」方向のみ。

**markRead ハンドラ** (ガード通過直後・`router.post` 前に `reading=true` を**同期設定**して
二重送信窓を閉じる。`onStart` には依存しない。open との相互排他もガードする):
```ts
async function markRead(event: MouseEvent): Promise<void> {
    event.stopPropagation(); // 兄弟要素だが将来 wrapper に click を置く変更への防御
    if (reading || opening || !unread) return; // read/open in-flight ガード + 既読には無反応
    reading = true; // router.post 前に同期設定 (onStart 待ちの競合窓を閉じる)
    router.post(
        `/notifications/${notification.id}/read`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                optimisticallyRead = true; // 楽観既読 (サーバ back() 再読込が prop を確定)
            },
            onError: () => {
                optimisticallyRead = false; // defensive reset (単調前提が崩れても未読へ戻す)
                addToast("error", "既読にできませんでした。再試行してください。");
            },
            onFinish: async () => {
                reading = false;
                // 成功でボタンが DOM から消える場合、DOM 確定 (tick) を待って
                // 行の open ボタンへフォーカスを移す (フォーカスロスト防止)
                if (optimisticallyRead) {
                    await tick();
                    contentButton?.focus();
                }
            },
        },
    );
}
```

**既存 open() も同期ガードに揃える** (read との相互排他):
```ts
function open(): void {
    if (opening || reading) return; // read/open in-flight ガード
    opening = true; // router.post 前に同期設定
    router.post(
        `/notifications/${notification.id}/open`,
        {},
        { onFinish: () => { opening = false; } },
    );
}
```

**import 追加**: `Check`(`@lucide/svelte`)、`addToast`(`@/lib/stores/toast`)、
`tick`(`svelte`)。

**既読ボタンの aria-label** は状態で切替: `reading ? "既読処理中" : "既読にする"`。

### 変更後コード（NotificationListItem.svelte の主要差分）

script 冒頭 import:
```svelte
import { Bell, Check, FileSearch, Film, Mail, TicketMinus } from "@lucide/svelte";
import { addToast } from "@/lib/stores/toast";
```

状態と derived(既存 `opening` / `unread` を置換):
```ts
let opening = $state(false);
let reading = $state(false);
let optimisticallyRead = $state(false);
let contentButton = $state<HTMLButtonElement | null>(null);

const unread = $derived(notification.read_at === null && !optimisticallyRead);
```

markRead は上記。既存 `open()` は不変。

markup(既存の単一 `<button>` を置換):
```svelte
<div
    class="relative flex items-stretch border-b border-border
        {unread ? 'bg-primary-soft/40' : 'bg-surface'}"
    data-testid="notification-item-row"
>
    <button
        type="button"
        onclick={open}
        bind:this={contentButton}
        class="flex min-w-0 flex-1 items-start gap-3 px-4 py-3 pr-12 text-left hover:bg-neutral"
        data-testid="notification-item"
        data-unread={unread}
    >
        <span class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md
            {unread ? 'bg-primary-soft text-primary' : 'bg-neutral text-text-secondary'}"
            aria-hidden="true">
            <Icon class="size-4" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-body {unread ? 'font-medium' : ''} text-text">{title}</span>
            {#if body !== null}
                <span class="mt-0.5 block truncate text-caption text-text-secondary">{body}</span>
            {/if}
            <span class="mt-1 flex items-center gap-2">
                {#if organizationName !== null}
                    <Badge tone="neutral" size="sm">{organizationName}</Badge>
                {/if}
                <span class="text-caption text-text-secondary">
                    {relativeTime(notification.created_at)}
                </span>
                {#if unread}
                    <span class="inline-block size-2 shrink-0 rounded-sm bg-primary"
                        aria-label="未読" data-testid="unread-dot"></span>
                {/if}
            </span>
        </span>
    </button>
    {#if showReadButton}
        <button
            type="button"
            onclick={(e) => markRead(e)}
            aria-label={reading ? "既読処理中" : "既読にする"}
            aria-busy={reading}
            data-testid="notification-read-button"
            class="absolute right-2 top-2 inline-flex size-8 items-center justify-center
                rounded-md text-text-secondary hover:bg-neutral hover:text-text"
        >
            <Check class="size-4" />
        </button>
    {/if}
</div>
```

> 注: 既存の未読ドットは「行右端に絶対配置(mt-2)」だったが、既読ボタンが右上を占めるため
> 未読ドットを title 行下のメタ行(時刻の隣)へ移設する。`data-testid="unread-dot"` は維持。
> row 外側 div に `data-testid="notification-item-row"` を新設(既存テストは参照しないので無害)。

### PHPStan 適合チェック
- PHP 変更なし。該当なし。

### テスト計画（施策2）
- [ ] 既存テストを壊さない: `notification-item` クリックで open POST(URL `.../open`)、
      未読で `data-unread=true` + `unread-dot` あり、既読で `data-unread=false` + ドットなし、
      未知 type fallback + クリック可、各 type 文言。
- [ ] 新規: 未読行に `notification-read-button` が表示される。
- [ ] 新規: 既読行(`read_at` 非 null)には `notification-read-button` が表示されない。
- [ ] 新規: 既読ボタン押下 → `router.post` が `/notifications/{id}/read` へ 1 回発火し、
      オプションに `preserveScroll: true` かつ `onStart/onSuccess/onError/onFinish`
      (`expect.any(Function)`)が渡る。open URL(`.../open`)は呼ばれない(遷移しない)。
- [ ] 新規: 既読ボタン押下 → mock で `onStart`+`onSuccess`+`onFinish` を発火させると、
      行が既読表示になる(`data-unread=false`、`unread-dot` 消滅、read ボタン消滅)。
- [ ] 新規(フォーカス移動): success+finish 後、`document.activeElement` が
      `notification-item`(open ボタン)になる(`tick`/`await` で DOM 確定を待つ)。
- [ ] 新規(二重送信防止): `router.post` mock が **onStart/コールバックを呼ばない**状態で
      既読ボタンを同一ターンに 2 回クリック → `router.post` は 1 回のみ
      (`reading` を同期設定するガードが競合窓を閉じることを検証)。
- [ ] 新規: `onError` 発火時 `addToast('error', ...)` が呼ばれる(toast store / addToast を spy)。
- [ ] 新規(排他): open(`notification-item`)クリックで read URL は呼ばれない。
- [ ] 新規(open/read 競合): 片方が in-flight(mock がコールバック未発火)の間にもう片方を押しても
      追加の `router.post` が発生しない(`opening`/`reading` 相互ガード)。
- [ ] 個別 `DatabaseTransactions` は使わない(JS テストのため無関係)。

### リスク
- DOM 構造変更で既存 vitest の一部セレクタが影響を受ける可能性 → `notification-item` /
  `unread-dot` / `data-unread` の testid・属性は温存し既存アサートを保つ。
- 楽観 state と prop の二重管理 → `unread` 式で prop を最優先化し単調性で担保(概念設計 APPROVED 済)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 単一コンポーネント + そのテストに閉じる純フロント変更。他施策と非干渉。 |
| 競合リスク | なし(他 2 件は backend / 別ページで独立) |
