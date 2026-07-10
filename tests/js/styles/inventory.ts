/**
 * DS token inventory — canonical-source-parity テストの single source of truth。
 *
 * DESIGN.md frontmatter のキーと tokens.css の CSS 変数名の対応を定義する。
 * トークンを追加・削除する PR は DESIGN.md / tokens.css / 本ファイルを同一 PR で更新する。
 */

/** DESIGN.md colors キー → tokens.css `--color-<suffix>` の対応 */
export const COLOR_TOKEN_MAP = {
    "primary": "primary",
    "primary-hover": "primary-hover",
    "tertiary": "tertiary",
    "tertiary-hover": "tertiary-hover",
    "neutral": "neutral",
    "surface": "surface",
    "border": "border",
    "border-strong": "border-strong",
    "text-primary": "text",
    "text-secondary": "text-secondary",
    "success": "success",
    "warning": "warning",
    "danger": "danger",
} as const;

/**
 * DESIGN.md frontmatter に現れない派生トークン (rgba 等)。
 * tokens.css にのみ存在してよい。追加時は理由をコメントで残すこと。
 */
export const DERIVED_COLOR_TOKENS = [
    "primary-soft", // primary 12% — badge / focus ring 用 (DESIGN.md §Colors 本文で言及)
] as const;

export const RADIUS_TOKENS = ["sm", "md", "lg"] as const;

export const TYPOGRAPHY_RAMPS = ["display", "h1", "h2", "h3", "body", "caption"] as const;
