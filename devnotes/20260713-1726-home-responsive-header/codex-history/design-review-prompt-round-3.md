# 詳細設計レビュー Round 3（Critical 1 / Warning 1 の対応確認）

Round 2 のご指摘 2 点に対応しました。残指摘が無ければ全体 APPROVED を明記してください。

## 対応サマリ

### [Critical] 施策1: anchor 分岐に never 補完
`Button.types.ts` の anchor モード union member に `never` を追加:
```ts
type ModeProps =
    | {
          href?: never; inertia?: never; target?: never; rel?: never;
          type?: "button" | "submit" | "reset";
          disabled?: boolean;
          onclick?: (event: MouseEvent) => void;
          ariaExpanded?: boolean;
          ariaControls?: string;
          element?: HTMLButtonElement;
      }
    | { href: string; inertia?: boolean; target?: "_blank" | "_self"; rel?: string;
        type?: never; disabled?: never; onclick?: (event: MouseEvent) => void;
        ariaExpanded?: never; ariaControls?: never; element?: never;
      };
```
これで `$props()` の分割代入が両メンバーで解決でき、anchor モードでの disclosure props 誤用も
型で禁止できます。

### [Warning] 施策2: event.defaultPrevented ガード
`handleKeydown` 冒頭を修正:
```svelte
function handleKeydown(event: KeyboardEvent): void {
    if (event.defaultPrevented || event.key !== "Escape" || !menuOpen) return;
    const target = event.target;
    if (target instanceof HTMLElement && target.closest("input, textarea, [contenteditable='true']")) {
        return;
    }
    closeMenu();
    toggleEl?.focus();
}
```

## 確認事項
Round 2 の Critical/Warning はこの 2 点で解消したと考えます。追加の懸念があればご指摘ください。
