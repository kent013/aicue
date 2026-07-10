import type { Snippet } from "svelte";

/**
 * Modal organism の仕様の真実。意味論は DESIGN.md §Components > Modal を参照。
 */

export type ModalSize = "sm" | "md" | "lg";

/** size → 最大幅クラス (カード・モーダルの角丸は rounded-lg = DESIGN.md §Shapes) */
export const SIZE_CLASSES = {
    sm: "max-w-md",
    md: "max-w-lg",
    lg: "max-w-2xl",
} as const satisfies Record<ModalSize, string>;

export interface ModalProps {
    /** 開閉状態 (bindable)。呼び出し側が $state で保持し bind:open する */
    open: boolean;
    /** ダイアログ見出し (aria-labelledby に配線される) */
    title: string;
    /** 幅: sm=max-w-md / md=max-w-lg (既定) / lg=max-w-2xl */
    size?: ModalSize;
    /** true の間 ESC / overlay クリックでの close を抑止する (二重実行防止) */
    processing?: boolean;
    /** 本文 */
    children?: Snippet;
    /** アクション行 (ボタン群)。未指定なら footer 領域を描画しない */
    footer?: Snippet;
    testId?: string;
}
