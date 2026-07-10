import type { Snippet } from "svelte";

/**
 * Badge atom の仕様の真実。意味論は DESIGN.md §色の意味的割り当てルールを参照。
 * action button (操作) と status badge (結果表示) は意味色を独立に判断する。
 */

export type BadgeTone =
    | "primary"
    | "tertiary"
    | "success"
    | "warning"
    | "danger"
    | "neutral";

export type BadgeSize = "sm" | "md";

/**
 * 既定は soft (tone 色 12% 背景 + tone 色文字)。
 * primary は専用 token bg-primary-soft、他 tone は opacity 修飾 (/10) で soft 背景を表現する。
 * neutral は中立ラベル用 (bg-neutral + text-text-secondary)。
 */
export const TONE_CLASSES = {
    primary: "bg-primary-soft text-primary",
    tertiary: "bg-tertiary/10 text-tertiary",
    success: "bg-success/10 text-success",
    warning: "bg-warning/10 text-warning",
    danger: "bg-danger/10 text-danger",
    neutral: "bg-neutral text-text-secondary",
} as const satisfies Record<BadgeTone, string>;

/**
 * bordered=true 時の tone 色 border (atom 内で tone→border 色を決定し、caller から border を足さない)。
 * neutral は tone 色を持たないため border-border で境界のみ確保する。
 */
export const BORDERED_CLASSES = {
    primary: "border border-primary",
    tertiary: "border border-tertiary",
    success: "border border-success",
    warning: "border border-warning",
    danger: "border border-danger",
    neutral: "border border-border",
} as const satisfies Record<BadgeTone, string>;

/** size: sm = compact (既定、status/label) / md = 強調表示用 */
export const SIZE_CLASSES = {
    sm: "px-2 py-0.5 text-caption",
    md: "px-3 py-1 text-body",
} as const satisfies Record<BadgeSize, string>;

export interface BadgeProps {
    tone?: BadgeTone;
    size?: BadgeSize;
    /** tone 色の border を atom 内で付与する */
    bordered?: boolean;
    /** 左側アイコン 1 つ (size/色は Badge 内 wrapper で制御) */
    icon?: Snippet;
    class?: string;
    testId?: string;
    children?: Snippet;
}
