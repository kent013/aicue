import type { Snippet } from "svelte";

/**
 * Button atom の仕様の真実。意味論は DESIGN.md §Components > Button を参照。
 * variant 追加時は DESIGN.md の表も同一 PR で更新すること。
 */

export type ButtonVariant =
    | "primary"
    | "tertiary"
    | "ghost"
    | "neutral"
    | "success"
    | "danger"
    | "danger-outline"
    | "danger-ghost";

export type ButtonSize = "sm" | "md" | "lg";

/** 全 variant が border (透明 or 色) を持ち外形高さを統一する (DESIGN.md) */
export const VARIANT_CLASSES = {
    "primary": "border-transparent bg-primary text-neutral hover:bg-primary-hover",
    "tertiary": "border-transparent bg-tertiary text-neutral hover:bg-tertiary-hover",
    "ghost": "border-border-strong bg-transparent text-text hover:border-primary hover:text-primary",
    "neutral": "border-border bg-neutral text-text hover:bg-border",
    "success": "border-transparent bg-success text-neutral hover:opacity-90",
    "danger": "border-transparent bg-danger text-neutral hover:opacity-90",
    "danger-outline": "border-danger bg-surface text-danger hover:bg-danger hover:text-neutral",
    "danger-ghost": "border-transparent bg-transparent text-danger hover:bg-danger/10",
} as const satisfies Record<ButtonVariant, string>;

export const SIZE_CLASSES = {
    sm: "px-3 py-1 text-caption",
    md: "px-4 py-2 text-body",
    lg: "px-6 py-3 text-body",
} as const satisfies Record<ButtonSize, string>;

export const ICON_ONLY_SIZE_CLASSES = {
    sm: "p-1.5",
    md: "p-2",
    lg: "p-3",
} as const satisfies Record<ButtonSize, string>;

/** iconOnly を許可する variant (塗りつぶし variant の無名アイコンボタンは禁止) */
export const ICON_ONLY_VARIANTS: ReadonlySet<ButtonVariant> = new Set([
    "ghost",
    "neutral",
    "danger-ghost",
]);

interface BaseProps {
    variant?: ButtonVariant;
    size?: ButtonSize;
    fullWidth?: boolean;
    loading?: boolean;
    class?: string;
    testId?: string;
    children?: Snippet;
}

/** iconOnly のとき ariaLabel を型レベルで必須化する (a11y) */
type IconOnlyProps =
    | { iconOnly: true; ariaLabel: string }
    | { iconOnly?: false; ariaLabel?: string };

/**
 * button モードと anchor モードの discriminated union。
 * anchor モードでは button のセマンティクス (type/disabled) を型レベルで禁止する。
 * loading 中の anchor は aria-disabled + tabindex=-1 + click 抑止で操作を止める。
 */
type ModeProps =
    | {
          href?: never;
          inertia?: never;
          target?: never;
          rel?: never;
          type?: "button" | "submit" | "reset";
          disabled?: boolean;
          onclick?: (event: MouseEvent) => void;
      }
    | {
          href: string;
          /** true で Inertia Link (SPA 内部遷移)。既定はネイティブ <a> (外部リンク・OAuth 開始等) */
          inertia?: boolean;
          target?: "_blank" | "_self";
          rel?: string;
          type?: never;
          disabled?: never;
          onclick?: (event: MouseEvent) => void;
      };

export type ButtonProps = BaseProps & IconOnlyProps & ModeProps;
