Round 1 の指摘に全対応しました。再レビューし全体判定を返してください。

## 施策1 対応
- [Critical] 表示条件を明示 derived `const showReadButton = $derived(unread || reading)` に分離。focus 移動は onFinish で `await tick()` 後に `contentButton?.focus()`(DOM 確定を待つ)。※`unread && !opening` 案は in-flight 中にボタンが消えて aria-busy 不達になるため不採用。「未読 or in-flight は残す」が要件で、名前付き derived で意図を明確化。
- [Warning] onError に `optimisticallyRead = false`(defensive reset)を明示。
- [Warning] aria-label を `reading ? "既読処理中" : "既読にする"` に切替。
- [Suggestion] `markRead(event)` 冒頭で `event.stopPropagation()`、markup は `onclick={(e) => markRead(e)}`。

markRead 最終形:
```ts
async function markRead(event: MouseEvent): Promise<void> {
    event.stopPropagation();
    if (reading || !unread) return;
    router.post(`/notifications/${notification.id}/read`, {}, {
        preserveScroll: true,
        onStart: () => { reading = true; },
        onSuccess: () => { optimisticallyRead = true; },
        onError: () => { optimisticallyRead = false; addToast("error", "既読にできませんでした。再試行してください。"); },
        onFinish: async () => { reading = false; if (optimisticallyRead) { await tick(); contentButton?.focus(); } },
    });
}
```
表示: `{#if showReadButton}<button onclick={(e)=>markRead(e)} aria-label={reading ? "既読処理中" : "既読にする"} aria-busy={reading} data-testid="notification-read-button" ...>`

## 施策2 対応(テスト追加)
- [Critical] 二重送信防止: onStart のみ発火状態で read ボタン 2 回クリック → router.post 1 回のみ。
- [Warning] フォーカス移動: success+finish 後 `document.activeElement === getByTestId('notification-item')`。
- [Warning] オプション shape: preserveScroll:true + onStart/onSuccess/onError/onFinish を expect.any(Function) で固定。
- [Suggestion] 排他: open クリックで read URL が呼ばれない。
- 既存: 未読で read ボタン表示 / 既読(read_at 非 null)で非表示 / read 押下で read URL 発火・open 未呼出 / success+finish で行が既読表示(data-unread=false, unread-dot 消滅, read ボタン消滅) / onError で addToast('error',...)。
