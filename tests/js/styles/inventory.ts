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

/*
 * ===== コントラスト検査の役割宣言 (contrast-invariant.test.ts の入力) =====
 *
 * DESIGN.md の全色トークンは下の 5 分類の**いずれかに必ず属する** (deny-by-default)。
 * 未分類のトークンがあれば contrast-invariant が fail する = 新トークンが
 * 黙って gate をすり抜けられない。
 */

/** 面 (背景) として塗るトークン。DESIGN.md §Colors: neutral=画面全体 / surface=カード・モーダル */
export const SURFACE_ROLE_TOKENS = ["neutral", "surface"] as const;

/** 面の上に載るテキスト色 (本文・見出し・意味を担う状態テキスト) */
export const TEXT_ON_SURFACE_TOKENS = [
    "text-primary",
    "text-secondary",
    "primary", // リンク / TextLink
    "tertiary",
    "success",
    "warning",
    "danger", // Alert 見出し / Button danger-ghost のラベル
] as const;

/** 塗り面 (solid fill) として使うトークン。DESIGN.md §Components Button の bg-* */
export const FILL_TOKENS = [
    "primary",
    "primary-hover",
    "tertiary",
    "tertiary-hover",
    "success",
    "warning",
    "danger",
] as const;

/** 塗り面の上に載るラベル色。DESIGN.md §Components: `bg-* + text-neutral` */
export const FILL_LABEL_TOKENS = ["neutral"] as const;

/**
 * コントラスト検査の対象外トークン (理由必須)。
 * 「検査していない」ことを見えるようにするための明示宣言であり、免罪符ではない。
 */
export const CONTRAST_EXEMPT_TOKENS = {
    "border":
        "1px の区切り線・入力欄の枠。テキストではなく WCAG 1.4.11 (非テキスト 3:1) の領域。" +
        "装飾的な境界線は 1.4.11 の適用除外のため、使用箇所ごとの役割分類が要る (v1 スコープ外)",
    "border-strong":
        "区切りの強調・ghost ボタンの枠。ghost ボタンの枠は機能的境界の可能性があり、" +
        "実測 2.56 で 3:1 に届かない。値の是正は『どの border が機能的境界か』の" +
        "役割モデルを DESIGN.md に定めてから別バッチで行う (申し送り 5-3)",
} as const;

/**
 * 未検査であることを明示する pending 集合 (v1 スコープ外)。
 * contrast-invariant はこれらを検査しない — 「gate があるからコントラストは守られている」
 * という誤読を作らないための宣言。
 *
 * **出口**: pending 項目に対応したらその行を削る。全部消えたら
 * 本 export と contrast-invariant.test.ts の
 * 「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」テストを**同時に削除**すること
 * (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
 */
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring",
    "alpha 合成ペア: Badge の bg-<tone>/10 + text-<tone>、bg-primary-soft、ring-primary/35、" +
        "bg-text/70 + text-surface (合成後の実効色が親背景に依存しトークン単体では定まらない)",
] as const;
