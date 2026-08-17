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

/*
 * ===== 生成 CSS 検査の入力 (tokens.test.ts) =====
 */

/**
 * tokens.css が持つ `--color-<suffix>` の全件。
 *
 * COLOR_TOKEN_MAP (DESIGN.md 由来) と DERIVED_COLOR_TOKENS (tokens.css 固有の派生) の和。
 * これが tokens.css の `--color-*` 全件と一致することは canonical-source-parity の
 * 集合一致テストが固定しているので、この配列は「定義上の全件」である。
 */
export const CSS_COLOR_SUFFIXES: readonly string[] = [
    ...Object.values(COLOR_TOKEN_MAP),
    ...DERIVED_COLOR_TOKENS,
];

/**
 * 生成 CSS で**値**の一致を検査しないトークン (理由必須)。
 *
 * 契約: **派生トークンは全件が値免除である** (DESIGN.md に期待値が無いため)。
 * キー集合が DERIVED_COLOR_TOKENS と一致することを canonical-source-parity が固定する
 * = 派生トークンを足したのに「値も見ていない・免除にも入っていない」状態を作れない。
 * 免除しているのは**値だけ**で、生成 CSS への出現は検査する。
 */
export const COMPILED_VALUE_EXEMPT_TOKENS = {
    "primary-soft":
        "DESIGN.md frontmatter に現れない派生トークン (rgba)。期待値を正本から導出できないため" +
        "値の突き合わせは行わず、生成 CSS への出現までを検査する。値の正本は tokens.css で、" +
        "集合としての存在は canonical-source-parity が固定している",
} as const;

/**
 * 経路の層 (実 app.css のコンパイル) で**必ず現れることを求める**トークン。
 *
 * これは**アンカー集合であって全件ではない**。経路の層の生成物はアプリ側の class 使用状況に
 * 依存するため、全件の網羅は密閉の層が担う。ここに並べるのは画面の土台
 * (面・本文・主 CTA) が使う 4 件に限る
 * (実測の使用回数: bg-primary 17 / text-text 106 / bg-surface 47 / bg-neutral 35)。
 *
 * **アンカーが使われなくなったときの直し方**: テストを緩めるのではなく、
 * 土台に相当する別のトークンへ差し替える (集合を縮めて緑にしない)。
 */
export const ROUTE_LAYER_ANCHOR_TOKENS = ["primary", "text", "surface", "neutral"] as const;

/*
 * ===== DESIGN.md frontmatter の節ごとの担当宣言 (既定拒否) =====
 *
 * frontmatter の最上位の節は下の 3 分類の**いずれかに必ず属する**。
 * 未分類の節があれば canonical-source-parity が fail する
 * = 正本に節を足したのに誰も見ていない状態を作れない。
 *
 * **`checked` は「担当がいる」ことを表すのであって、節の中身を全項目網羅しているという
 * 主張ではない**。母集団の網羅は節ごとの集合一致テストが別に固定する。
 */

/** 節を検査している gate の識別子 (ファイル名の語幹に合わせる)。 */
export type DesignGateName = "canonical-source-parity" | "tokens" | "contrast-invariant";

export type FrontmatterSectionOwner =
    /** 担当のいる節。どの gate が見ているかを列挙する */
    | { readonly kind: "checked"; readonly by: readonly DesignGateName[] }
    /** 実装写像を持たないメタ情報 (理由必須) */
    | { readonly kind: "metadata"; readonly reason: string }
    /**
     * 未検査であることの明示宣言 (理由・解消条件・追跡先の 3 つが必須)。
     * 追跡先は `T<3 桁以上>` (TODO の表の ID 列に実在) か
     * `devnotes/<dir>/` (実在するディレクトリ) のどちらか。
     */
    | {
          readonly kind: "pending";
          readonly reason: string;
          readonly exit: string;
          readonly tracking: string;
      };

export const FRONTMATTER_SECTION_OWNERS: Readonly<Record<string, FrontmatterSectionOwner>> = {
    version: { kind: "metadata", reason: "テーマの版。実装写像を持たない" },
    name: { kind: "metadata", reason: "テーマの名前。実装写像を持たない" },
    description: { kind: "metadata", reason: "テーマの説明文。実装写像を持たない" },
    colors: { kind: "checked", by: ["canonical-source-parity", "tokens", "contrast-invariant"] },
    typography: { kind: "checked", by: ["canonical-source-parity", "tokens"] },
    rounded: { kind: "checked", by: ["canonical-source-parity", "tokens"] },
    spacing: {
        kind: "pending",
        reason:
            "tokens.css に --spacing-* の写像が無く、値も写像の有無もどの検査も見ていない。" +
            "Tailwind 既定の spacing で足りているのか写像の作り忘れなのかが未決である",
        exit:
            "tokens.css に写像を作って canonical-source-parity と tokens の担当に移すか、" +
            "frontmatter から spacing を外すかを決めたら、本項目を削る",
        tracking: "devnotes/20260818-0248-design-token-t1-tests/",
    },
};
