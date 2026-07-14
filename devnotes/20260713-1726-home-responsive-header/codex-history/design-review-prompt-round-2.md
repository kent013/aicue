# 詳細設計レビュー Round 2（Critical 2 / Warning 3 の対応確認）

Round 1 のご指摘（施策3 REQUEST_CHANGES、Critical 2・Warning 3）すべてに対応しました。
確認のうえ、残指摘が無ければ全体 APPROVED を明記してください。

## 対応サマリ

### [Critical] 施策2: Escape の入力要素ガード
`handleKeydown` を修正:
```svelte
function handleKeydown(event: KeyboardEvent): void {
    if (event.key !== "Escape" || !menuOpen) return;
    const target = event.target;
    if (target instanceof HTMLElement && target.closest("input, textarea, [contenteditable='true']")) {
        return;
    }
    closeMenu();
    toggleEl?.focus();
}
```

### [Critical] 施策3: フォーカス復帰テスト
Escape ケースに `toHaveFocus()` を追加:
```ts
it("Escape でモバイルパネルが閉じ、トグルにフォーカスが戻る", async () => {
    render(Welcome, { props: baseProps });
    const toggle = screen.getByTestId("guest-nav-toggle");
    await fireEvent.click(toggle);
    await fireEvent.keyDown(window, { key: "Escape" });
    expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
    expect(toggle).toHaveFocus();
});
```

### [Warning] 施策2: target の Element narrowing
```svelte
function handlePanelClick(event: MouseEvent): void {
    const target = event.target;
    if (target instanceof Element && target.closest("a")) closeMenu();
}
```

### [Warning] 施策3: nav なし専用テスト（新規ファイル）
`tests/js/components/templates/GuestLayout.test.ts` を新設。`createRawSnippet` で children を
生成し、nav 未指定でトグル・パネルが不在であることを固定（施策一覧・変更箇所にも追記）:
```ts
import { createRawSnippet } from "svelte";
const children = createRawSnippet(() => ({ render: () => `<p>content</p>` }));
it("nav を渡さないとハンバーガー・パネルを描画しない (Contact 相当)", () => {
    render(GuestLayout, { props: { appName: "AI-CUE", children } });
    expect(screen.queryByTestId("guest-nav-toggle")).not.toBeInTheDocument();
    expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
});
```

### [Warning] 施策1: href モードでの disclosure props 誤用の DEV 警告
既存 iconOnly DEV 警告に倣い Button.svelte に追加:
```svelte
$effect(() => {
    if (import.meta.env.DEV && href !== undefined && (ariaExpanded !== undefined || ariaControls !== undefined)) {
        console.warn("[Button] ariaExpanded / ariaControls は button モード (href なし) 専用です");
    }
});
```

## 確認事項
上記 5 点で Round 1 の Critical/Warning はすべて解消したと考えます。追加の懸念があればご指摘ください。
