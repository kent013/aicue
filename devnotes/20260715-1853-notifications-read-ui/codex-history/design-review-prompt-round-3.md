Round 2 の 2 Critical + Warning に全対応しました。再レビューし全体判定を返してください。

## 施策1
- [Critical] 二重送信ガードを onStart 非依存に。ガード通過直後・router.post 前に `reading = true` を同期設定。onStart 削除。
- [Warning] open/read 相互排他: `open()` は `if (opening || reading) return; opening = true;`(同期)。`markRead()` は `if (reading || opening || !unread) return; reading = true;`。disabled 不使用(押下時ガード)。

最終 markRead:
```ts
async function markRead(event: MouseEvent): Promise<void> {
    event.stopPropagation();
    if (reading || opening || !unread) return;
    reading = true;
    router.post(`/notifications/${notification.id}/read`, {}, {
        preserveScroll: true,
        onSuccess: () => { optimisticallyRead = true; },
        onError: () => { optimisticallyRead = false; addToast("error", "既読にできませんでした。再試行してください。"); },
        onFinish: async () => { reading = false; if (optimisticallyRead) { await tick(); contentButton?.focus(); } },
    });
}
```
最終 open:
```ts
function open(): void {
    if (opening || reading) return;
    opening = true;
    router.post(`/notifications/${notification.id}/open`, {}, { onFinish: () => { opening = false; } });
}
```

## 施策2
- [Critical] 二重送信テスト: router.post mock がコールバックを呼ばない状態で同一ターン 2 回クリック → 1 回のみ。
- [Warning] open/read 競合テスト: 片方 in-flight(mock コールバック未発火)中にもう片方を押しても追加 router.post なし。

これで残件は解消と考えます。APPROVED 可否を判定してください。
