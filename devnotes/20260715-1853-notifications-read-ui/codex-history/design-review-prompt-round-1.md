【アプリの使命 (North Star) — AGENTS.md より】
AI-CUE は現場の作業手順書(SOP)を起点に AI が動画シナリオを生成し、PWA でナビゲーション撮影して標準化マニュアル動画を作る。思考ゼロ・編集ゼロ。

【禁止事項 — AGENTS.md より】
1 テストなし実装完了報告禁止 / 2 PHPStan widen・baseline 禁止 / 3 dev DB 破壊操作禁止 / 4 response()->json() 直書き禁止(DTO/JsonResource/Inertia) / 5 Prism 直呼び禁止 / 6 prompt 直書き禁止 / 7 操作系 POST 応答で redirect()->intended() 禁止(back()->with で完結) / 8 必須条件未充足でボタン disabled 禁止(押下時エラー表示)

【ツール使用制限】コマンド実行・書き込み禁止。ファイル読み込みは許可。

---
あなたは経験豊富な Web アプリアーキテクトです。Laravel + Svelte 改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript / PHPStan L10 / Pest / DTO+JsonResource / Laratrust RBAC

【レビュー観点】
1 コード正確性(ロジック/エッジ/null 安全) 2 既存整合(命名/パターン) 3 PHPStan L10 4 テスト網羅 5 DTO/JsonResource 6 Inertia Props vs API 7 副作用/後退 8 波及変更網羅 9 セキュリティ 10 DESIGN.md 準拠(token/Lucide) 11 Atomic Design 準拠(atoms/molecules/organisms/features の責務・単方向 import)

【出力形式】各施策 APPROVE/REQUEST_CHANGES、指摘は [Critical][Warning][Suggestion]、Critical/Warning に修正案、全体判定 APPROVED/CHANGES_REQUESTED、日本語。

---

## 詳細設計書
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
let optimisticallyRead = $state(false); // 楽観既読 (単調・onError で false 復帰)
let contentButton = $state<HTMLButtonElement | null>(null); // フォーカス移動先

const unread = $derived(notification.read_at === null && !optimisticallyRead);
```
`unread` は `read_at`(prop, source of truth)を最優先し、楽観 state は「未読→既読」方向のみ。

**markRead ハンドラ**:
```ts
function markRead(): void {
    if (reading || !unread) return; // 連打ガード + 既読には無反応
    router.post(
        `/notifications/${notification.id}/read`,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                reading = true;
            },
            onSuccess: () => {
                optimisticallyRead = true; // 楽観既読 (サーバ back() 再読込が prop を確定)
            },
            onError: () => {
                addToast("error", "既読にできませんでした。再試行してください。");
            },
            onFinish: () => {
                reading = false;
                // 成功でボタンが DOM から消える場合、行の open ボタンへフォーカスを移す
                if (optimisticallyRead) {
                    contentButton?.focus();
                }
            },
        },
    );
}
```

**import 追加**: `Check`(`@lucide/svelte`)、`addToast`(`@/lib/stores/toast`)。

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
    {#if unread || reading}
        <button
            type="button"
            onclick={markRead}
            aria-label="既読にする"
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
      オプションに `preserveScroll: true`。open URL(`.../open`)は呼ばれない(遷移しない)。
- [ ] 新規: 既読ボタン押下 → mock で `onStart`+`onSuccess`+`onFinish` を発火させると、
      行が既読表示になる(`data-unread=false`、`unread-dot` 消滅、read ボタン消滅)。
- [ ] 新規: `onError` 発火時 `addToast('error', ...)` が呼ばれる(toast store / addToast を spy)。
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

## 関連する現行コード（抜粋）

### NotificationListItem.svelte（現行 markup 末尾）
現行は行全体が単一 `<button type="button" onclick={open} class="flex w-full items-start gap-3 border-b border-border px-4 py-3 text-left hover:bg-neutral {unread ? 'bg-primary-soft/40' : 'bg-surface'}" data-testid="notification-item" data-unread={unread}>` で、内部にアイコン span・title/body/badge/time・末尾に未読ドット(`data-testid="unread-dot"` mt-2 right)を持つ。open() は `router.post('/notifications/${id}/open', {}, { onStart, onFinish })`。

### NotificationController::read()（backend, 変更しない）
`read(Request, string $notification): RedirectResponse { $user=...; $this->notifications->markRead($this->notifications->findOwnOrFail($user,$notification)); return back(); }`

### toast store（再利用）
`addToast(type: 'success'|'info'|'warning'|'error', message: string): number`。error は自動消去なし。ToastContainer が aria-live 表示。

### 既存 vitest（壊してはいけない）
`tests/js/components/features/NotificationListItem.test.ts` は router.post を hoisted mock し、`getByTestId('notification-item')` のクリックで open URL 発火、`data-unread` 属性、`unread-dot` 有無を検証している。
