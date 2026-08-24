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
 * DESIGN.md の全色トークンは `COLOR_TOKEN_ROLES` に**必ず 1 つ以上の役割で登録される**
 * (deny-by-default)。未分類のトークンがあれば contrast-invariant が fail する = 新トークンが
 * 黙って gate をすり抜けられない。
 *
 * ここは **DESIGN.md の色キー空間**である (`text-primary` = 本文色)。
 * tokens.css の `--color-<suffix>` 空間とは別で、境界は `COLOR_TOKEN_MAP` の 1 本だけである。
 */

/**
 * 色 token の役割。**1 つの token が複数の役割を持ちうる** (思考原則 4: 別物の用途を統合しない)。
 *
 * 役割の全数性は本表のキーと DESIGN.md の色キーの集合一致だけで見る
 * (個別宣言ペアに現れた token を「分類済み」と数えると、任意の新 token を 1 組登録するだけで
 * 既定拒否を通せてしまう)。
 */
export type ColorRole =
    /** 面 = 容器の背景。**半透明の合成の下地でもある** (正典 i16) */
    | "surface"
    /** 面の上に載るテキスト色 */
    | "text-on-surface"
    /** 塗り面 (solid fill) */
    | "fill"
    /** 塗り面の上に載るラベル色 */
    | "fill-label"
    /** 直積で表現できない、テキストを載せる塗り (個別宣言ペアの背景側にだけ現れる) */
    | "declared-text-background"
    /** 1px 境界・focus ring 等。WCAG 1.4.11 の別の閾値体系なので本 gate の対象外 (正典 i17。理由必須) */
    | "non-text-boundary";

/**
 * **役割分類の唯一の宣言**。下の 4 つの配列は**ここから導出する** (正典 i4: 母集団を固定配列に書かない)。
 */
export const COLOR_TOKEN_ROLES = {
    "primary": ["text-on-surface", "fill"],
    "primary-hover": ["fill"],
    "tertiary": ["text-on-surface", "fill"],
    "tertiary-hover": ["fill"],
    "neutral": ["surface", "fill-label"],
    "surface": ["surface", "fill-label"],
    // 2 役割を持つ: 1px 枠 (対象外) と、Button の neutral variant の hover 塗り (検査する)
    "border": ["non-text-boundary", "declared-text-background"],
    "border-strong": ["non-text-boundary"],
    "text-primary": ["text-on-surface"],
    "text-secondary": ["text-on-surface"],
    "success": ["text-on-surface", "fill"],
    "warning": ["text-on-surface", "fill"],
    "danger": ["text-on-surface", "fill"],
} as const satisfies Readonly<Record<string, readonly ColorRole[]>>;

/** ある役割を持つ token を宣言順で返す。 */
export function tokensWithRole(role: ColorRole): readonly string[] {
    return Object.entries(COLOR_TOKEN_ROLES)
        .filter(([, roles]) => (roles as readonly ColorRole[]).includes(role))
        .map(([token]) => token);
}

/** ある token の役割を返す (逆写像の起点)。 */
export function rolesOf(token: string): readonly ColorRole[] {
    const roles = (COLOR_TOKEN_ROLES as Readonly<Record<string, readonly ColorRole[]>>)[token];
    if (roles === undefined) throw new Error(`COLOR_TOKEN_ROLES に ${token} が無い`);

    return roles;
}

/** 面 (背景) として塗るトークン。DESIGN.md §Colors: neutral=画面全体 / surface=カード・モーダル */
export const SURFACE_ROLE_TOKENS: readonly string[] = tokensWithRole("surface");

/** 面の上に載るテキスト色 (本文・見出し・意味を担う状態テキスト) */
export const TEXT_ON_SURFACE_TOKENS: readonly string[] = tokensWithRole("text-on-surface");

/** 塗り面 (solid fill) として使うトークン。DESIGN.md §Components Button の bg-* */
export const FILL_TOKENS: readonly string[] = tokensWithRole("fill");

/** 塗り面の上に載るラベル色。DESIGN.md §Components: `bg-* + text-neutral` / `text-surface` */
export const FILL_LABEL_TOKENS: readonly string[] = tokensWithRole("fill-label");

/**
 * `non-text-boundary` の役割を持つ token の理由 (理由必須。正典 i17)。
 *
 * キー集合が `tokensWithRole("non-text-boundary")` と一致することを機械で見る
 * (理由だけ残る / 役割だけ足す のどちらも落とす)。
 * **「この token は一切検査しない」という意味ではない** — `border` は
 * `declared-text-background` の役割も持つので、その用途は個別宣言ペアで検査される。
 */
export const NON_TEXT_BOUNDARY_REASONS = {
    "border":
        "1px の区切り線・入力欄の枠としての用途。WCAG 1.4.11 (非テキスト 3:1) の別の閾値体系で、" +
        "装飾的な境界線は 1.4.11 の適用除外にあたるため、使用箇所ごとの役割分類が要る " +
        "(家系の未決論点 q2 の担当)。**テキストを載せる塗りとしての用途は別の役割で検査する**",
    "border-strong":
        "3 つの用途がいずれも本 gate の対象外である — (1) 1px の区切り線・入力欄の枠 " +
        "(WCAG 1.4.11 の非テキスト 3:1 で別の閾値体系。役割モデルが未定のため家系の未決論点 q2 の担当)、" +
        "(2) Toggle のトラック (テキストを載せない塗り)、" +
        "(3) 無効化したタブのラベル (SC 1.4.3 は無効化された UI 部品を適用除外にしている)。" +
        "実測 2.56 で 3:1 に届かないので、値の是正は 1.4.11 の役割モデルを DESIGN.md に" +
        "定めてから別バッチで行う",
} as const;

/** DESIGN.md の色キー (役割分類と個別宣言ペアが使う空間。綴り誤りが型で落ちる)。 */
export type DesignColorKey = keyof typeof COLOR_TOKEN_MAP;

/** 役割の直積で表現できない正当な 1 対 1 の組 (理由必須。正典 i14)。 */
export interface DeclaredPair {
    readonly fg: DesignColorKey;
    readonly bg: DesignColorKey;
    readonly reason: string;
}

/**
 * 直積で表現できない正当な 1 対 1 の組。**直積と同じ閾値 (4.5:1) を課す**。
 *
 * キーは **DESIGN.md の色キー空間**である。走査器が返す CSS suffix 空間とは別なので、
 * 突き合わせは `COLOR_TOKEN_MAP` の逆写像で行う。
 * **役割分類の既定拒否をここで迂回できない** — 本表に現れた token を「分類済み」と数えるのはやめ、
 * 分類の全数性は `COLOR_TOKEN_ROLES` だけで見る。本表には別の 5 条を課す。
 */
export const DECLARED_CONTRAST_PAIRS = [
    {
        fg: "text-primary",
        bg: "border",
        reason:
            "Button の neutral variant の hover (hover:bg-border + text-text)。" +
            "border を塗り面の役割へ入れると直積に neutral on border (1.15) と " +
            "surface on border (1.27) が生まれるが、この 2 組は実装に 1 件も無い。" +
            "border の 1px 枠としての用途は WCAG 1.4.11 (別の閾値体系) で本 gate の対象外である",
    },
] as const satisfies readonly DeclaredPair[];

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

/*
 * ===== 実装からの逆向き被覆 (i15) / 参照の閉包 (i9) の入力 =====
 */

/**
 * 静的に組を決められない理由の**正本 (実行時の配列)**。
 *
 * 型は本配列の要素型から導出する — union 型は実行時に列挙できないので、
 * 「各 reason を発火させる検体が 1 つ以上ある」という網羅の検査そのものが書けない。
 * fixture の網羅・表示ラベル・`PENDING_CONTRAST_PAIRS` の説明は**すべてこの配列から導出する**。
 *
 * `double-alpha` は**値域に無い**。alpha を値に持つ token への修飾は実効 alpha が
 * `token の alpha × 修飾の alpha` に確定する (tokens.test.ts の H が生成形を固定する) ので、
 * **静的に決められる形**であり例外へ逃がすのは正典 i16 に反する。合成対象として計算する。
 */
export const UNDECIDABLE_REASONS = [
    { id: "foreground-alpha", label: "前景の alpha" },
    { id: "keyword-color", label: "色キーワードと /0 (透明)" },
    { id: "alpha-background-no-text", label: "前景を持たない alpha 背景" },
    { id: "opaque-and-alpha-background", label: "塗り面と alpha 背景の同居" },
    { id: "multiple-background", label: "背景の多重宣言" },
    { id: "multiple-foreground", label: "前景の多重宣言" },
    { id: "element-opacity", label: "要素全体の不透明度" },
    { id: "interpolated", label: "補間" },
    { id: "variant-composition", label: "variant 列の合成" },
] as const;

/** 判定不能の理由 (値域の正本は `UNDECIDABLE_REASONS`)。 */
export type UndecidableReason = (typeof UNDECIDABLE_REASONS)[number]["id"];

/**
 * **token を指さない語**の契約表 (正典 i9)。
 *
 * これは許可一覧ではなく**検査対象の定義**である。テーマの名前空間の接頭辞を持つ語のうち、
 * 写像の宣言集合へ解決しないものは**全数がここに登録されていなければ不合格**になる。
 * Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は**登録しない** —
 * 写像の外の token 空間を参照する形なので落とすのが正しい。
 *
 * **チャネルを型で分ける**。class の語と `var()` 参照を同じ無型の表へ入れると、
 * **別のチャネルでの出現によって登録が生きているように見える**。
 * 出現の突き合わせと冗長判定は**チャネル別**に行う。
 *
 * 登録するのは**正規化後の有効な完全 token** である。`text-center/50` のような
 * 「色でない utility に不透明度修飾が付いた形」は走査器が
 * `unresolved: "alpha-on-non-color"` にするので、**契約表に登録しても救われない**。
 */
export type NonTokenWord =
    | { readonly kind: "class-word"; readonly word: string; readonly reason: string }
    | { readonly kind: "css-variable"; readonly name: string; readonly reason: string };

export const NON_TOKEN_WORD_CONTRACT = [
    {
        kind: "class-word",
        word: "bg-transparent",
        reason: "CSS の全域キーワード。色 token を指さない",
    },
    {
        kind: "class-word",
        word: "border-transparent",
        reason: "同上。全 variant で外形高さを揃えるための透明枠 (DESIGN.md §Components)",
    },
    { kind: "class-word", word: "border-2", reason: "境界の太さ。色ではない" },
    { kind: "class-word", word: "border-b", reason: "境界の辺の指定。色ではない" },
    { kind: "class-word", word: "border-b-0", reason: "同上 (打ち消し)" },
    { kind: "class-word", word: "border-b-2", reason: "同上 (太さつき)" },
    { kind: "class-word", word: "border-l-2", reason: "同上" },
    { kind: "class-word", word: "border-r", reason: "同上" },
    { kind: "class-word", word: "border-t", reason: "同上" },
    { kind: "class-word", word: "border-dashed", reason: "境界の線種。色ではない" },
    {
        kind: "class-word",
        word: "divide-y",
        reason: "区切り線の軸。色ではない (色は divide-border が持つ)",
    },
    { kind: "class-word", word: "outline-none", reason: "outline の打ち消し。色ではない" },
    { kind: "class-word", word: "ring-2", reason: "focus ring の太さ。色ではない" },
    { kind: "class-word", word: "ring-3", reason: "同上" },
    {
        kind: "class-word",
        word: "rounded-full",
        reason:
            "角丸 ramp の外の真円 UI。radius token を指さず ds-purity の file-scoped allowlist が管轄する",
    },
    {
        kind: "class-word",
        word: "stroke-current",
        reason: "CSS の currentColor キーワード。前景色を引き継ぐ指定で色 token を指さない",
    },
    { kind: "class-word", word: "text-center", reason: "テキストの整列。色でも ramp でもない" },
    { kind: "class-word", word: "text-left", reason: "同上" },
    { kind: "class-word", word: "text-right", reason: "同上" },
    {
        kind: "css-variable",
        name: "--app-sidebar-w",
        reason:
            "同一要素の style 属性で宣言する局所変数。@theme の token ではない " +
            "(他ファイルのローカル宣言を解決の根拠に数えない)",
    },
] as const satisfies readonly NonTokenWord[];

/**
 * `resources/js` 直下の子の全数分類 (新しい直下の子が現れたら不合格)。
 *
 * `requiresOccurrences: true` の子だけが 0 でないことを gate が固定する。
 * **要求しない子に 0 件を強いない** — 0 件が正常なので、要求すると正常な状態を赤にする。
 */
export const JS_SCAN_CHILD_CLASSIFICATION = {
    "components": { requiresOccurrences: true },
    "pages": { requiresOccurrences: true },
    "lib": {
        requiresOccurrences: false,
        reason:
            "DOM を直に組み立てる bfcache 秘匿オーバーレイが ramp を使うだけで、" +
            "色の組を持たない (0 件が正常な状態なので非空を要求しない)",
    },
    "types": { requiresOccurrences: false, reason: "型定義のみで class 文字列を持たない" },
    "(直下のファイル)": {
        requiresOccurrences: false,
        reason: "実測 0 件。起動と型宣言だけを持つ",
    },
} as const;

/*
 * ===== 半透明背景 × 不透明文字の合成検査の入力 (正典 i16) =====
 *
 * ここは**2 つのキー空間**のうち **tokens.css の `--color-<suffix>` 空間**である。
 * 役割分類 (COLOR_TOKEN_ROLES など) は **DESIGN.md の色キー空間**で、
 * `text-primary` = 本文色という別の意味を持つ。境界は COLOR_TOKEN_MAP の 1 本だけである。
 * 派生トークン `primary-soft` は DESIGN.md に無いので、半透明の台帳は suffix 空間で
 * 書かなければ表現できない (これが空間を分ける実質的な理由である)。
 */

type CanonicalColorSuffix = (typeof COLOR_TOKEN_MAP)[keyof typeof COLOR_TOKEN_MAP];
type DerivedColorSuffix = (typeof DERIVED_COLOR_TOKENS)[number];

/** tokens.css の `--color-<suffix>` の suffix (literal union。取り違えが型で落ちる)。 */
export type CssColorSuffix = CanonicalColorSuffix | DerivedColorSuffix;

/**
 * 半透明の背景 × 不透明な文字の 1 組。
 *
 * **台帳は実効値を持たない** — 持つのは **class 修飾の百分率だけ**で、
 * token 固有 alpha と合成して実効値を作るのは `resolveAlphaBackground()` **1 か所だけ**である。
 */
export interface AlphaPair {
    readonly fg: CssColorSuffix;
    readonly bg: CssColorSuffix;
    /** class 修飾の百分率 (0..100)。`bg-primary-soft` のような修飾なしは `null` */
    readonly modifierPercent: number | null;
}

/** 使用箇所の全数台帳の 1 行 (正典 i16 の「全件が台帳に載ることを件数まで」)。 */
export interface AlphaPairUsage extends AlphaPair {
    /** リポジトリ相対パス。**行番号は持たない** (正典 s14) */
    readonly file: string;
    /** そのファイルでの出現数 (完全一致で固定する) */
    readonly count: number;
}

/** 判定不能の単位の台帳の 1 行。 */
export interface UndecidableEntry {
    readonly file: string;
    readonly reason: UndecidableReason;
    readonly count: number;
    readonly note: string;
}

/**
 * 半透明の背景 × 不透明な文字の組の**使用箇所の全数台帳** (正典 i16)。
 *
 * **走査で見つかった半透明の組は全件がここに載る**ことを contrast-invariant が
 * (ファイル, 組, 修飾, 件数) の完全一致で固定する (件数だけの pin にしない =
 * 新しい使用を件数更新で通せない)。
 * **下地は宣言しない** — 実在する不透明な下地 = 役割分類の「面」(`SURFACE_ROLE_TOKENS`) の
 * **すべて**の上で 4.5:1 を要求するので、部品がどちらに置かれても成立する。
 * **行番号は持たない** (正典 s14)。ファイル単位までである。
 */
export const ALPHA_PAIR_USAGE_LEDGER = [
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "danger", bg: "danger", modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "primary", bg: "primary-soft", modifierPercent: null, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "success", bg: "success", modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "tertiary", bg: "tertiary", modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "warning", bg: "warning", modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Button.types.ts", fg: "danger", bg: "danger", modifierPercent: 10, count: 1 },
    { file: "resources/js/components/features/capture/CameraRecorder.svelte", fg: "surface", bg: "text", modifierPercent: 70, count: 1 },
    { file: "resources/js/components/features/capture/ScenarioPreviewDialog.svelte", fg: "text", bg: "surface", modifierPercent: 80, count: 1 },
    { file: "resources/js/components/features/capture/ScenarioPreviewDialog.svelte", fg: "text-secondary", bg: "surface", modifierPercent: 80, count: 1 },
    { file: "resources/js/components/features/capture/ShootingGuideOverlay.svelte", fg: "surface", bg: "text", modifierPercent: 70, count: 1 },
    { file: "resources/js/components/features/capture/TakePreviewDialog.svelte", fg: "text", bg: "surface", modifierPercent: 80, count: 1 },
    { file: "resources/js/components/features/capture/TakePreviewDialog.svelte", fg: "text-secondary", bg: "surface", modifierPercent: 80, count: 1 },
    { file: "resources/js/components/features/invitations/PendingInvitationList.svelte", fg: "primary", bg: "primary-soft", modifierPercent: null, count: 1 },
    { file: "resources/js/components/features/notifications/NotificationListItem.svelte", fg: "primary", bg: "primary-soft", modifierPercent: null, count: 1 },
    { file: "resources/js/components/molecules/PendingInvitationsNotice.svelte", fg: "text", bg: "primary-soft", modifierPercent: 40, count: 1 },
    { file: "resources/js/components/molecules/PendingInvitationsNotice.svelte", fg: "text", bg: "primary-soft", modifierPercent: null, count: 1 },
    { file: "resources/js/components/molecules/PricingPlanCard.svelte", fg: "text", bg: "warning", modifierPercent: 10, count: 1 },
    { file: "resources/js/components/molecules/SubtitleOverlay.svelte", fg: "surface", bg: "text", modifierPercent: 70, count: 2 },
    { file: "resources/js/components/templates/_helpers/SidebarUserMenu.svelte", fg: "danger", bg: "danger", modifierPercent: 10, count: 1 },
    { file: "resources/js/pages/Guest/Pricing.svelte", fg: "text", bg: "primary-soft", modifierPercent: null, count: 1 },
    { file: "resources/js/pages/Onboarding/Checkout.svelte", fg: "primary", bg: "primary", modifierPercent: 10, count: 1 },
    { file: "resources/js/pages/Organizations/Sso/Index.svelte", fg: "text", bg: "danger", modifierPercent: 10, count: 1 },
    { file: "resources/js/pages/Welcome.svelte", fg: "primary", bg: "primary-soft", modifierPercent: null, count: 6 },
    { file: "resources/js/pages/Welcome.svelte", fg: "success", bg: "success", modifierPercent: 10, count: 1 },
    { file: "resources/js/pages/Welcome.svelte", fg: "text", bg: "primary-soft", modifierPercent: null, count: 1 },
] as const satisfies readonly AlphaPairUsage[];

/**
 * 使用箇所台帳を `(fg, bg, modifierPercent)` へ射影した一意な意味ペア。
 *
 * AA の `it.each` はこちらを回す (同じ意味ペアを何度検査しても情報は増えない)。
 * 「射影が一致する」という it は置かない — 導出しているので恒真に近く、
 * 共通規約 (d) の形骸化に当たる。代わりに**導出関数 `distinctPairs()` の仕様**を
 * 固定検体で固定する。
 */
export function distinctPairs(ledger: readonly AlphaPair[]): readonly AlphaPair[] {
    const byKey = new Map<string, AlphaPair>();
    for (const row of ledger) {
        byKey.set(`${row.fg}|${row.bg}|${row.modifierPercent ?? "-"}`, {
            fg: row.fg,
            bg: row.bg,
            modifierPercent: row.modifierPercent,
        });
    }

    return [...byKey.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)).map(([, v]) => v);
}

export const ALPHA_CONTRAST_PAIRS: readonly AlphaPair[] = distinctPairs(ALPHA_PAIR_USAGE_LEDGER);

/**
 * 静的に組を決められなかった単位の台帳 (正典 i16「例外にして静かに素通りさせない」)。
 *
 * 識別子は **(ファイル, 理由, 件数) の完全一致**である ((ファイル, 理由) だけだと、
 * 同じファイルに同じ理由の未解析箇所が**増えても集合が変わらず**追加を検出できない)。
 * **行番号は持たない** (正典 s14: 無関係な 1 行の追加でずれ、期待値の機械的な更新が
 * 常態化して統制が形骸化する)。
 *
 * 不透明のみの不完全な単位 (前景か背景の片方しか無い) は**ここに載せない** —
 * 実体集合で pin すると期待値の機械的な更新が常態化する。そちらは「分類の全数性」を
 * 固定検体で受け、組そのものは正典 i14 の役割直積が覆う。
 */
export const UNDECIDABLE_PAIR_LEDGER = [
    { file: "resources/js/components/atoms/Alert.svelte", reason: "variant-composition", count: 1, note: "閉じるボタンの hover と focus-visible が別の変種列で同居する" },
    { file: "resources/js/components/atoms/Button.types.ts", reason: "element-opacity", count: 2, note: "success / danger の hover:opacity-90 (要素全体の不透明度)" },
    { file: "resources/js/components/atoms/Button.types.ts", reason: "keyword-color", count: 2, note: "ghost / danger-ghost の bg-transparent。背景は親から来る" },
    { file: "resources/js/components/atoms/input-state.ts", reason: "foreground-alpha", count: 1, note: "placeholder:text-text-secondary/70 (前景に不透明度修飾)" },
    { file: "resources/js/components/atoms/input-state.ts", reason: "interpolated", count: 2, note: "完成した class 文字列を補間で差し込む (readonly / 通常の 2 分岐)" },
    { file: "resources/js/components/features/capture/CameraRecorder.svelte", reason: "variant-composition", count: 5, note: "撮影コントロールの hover と focus-visible が別の変種列で同居する" },
    { file: "resources/js/components/features/capture/CutSwipeBar.svelte", reason: "alpha-background-no-text", count: 1, note: "スワイプ帯の半透明背景 (前景は別のリテラル)" },
    { file: "resources/js/components/features/capture/GridOverlay.svelte", reason: "alpha-background-no-text", count: 4, note: "構図ガイドの罫線 (bg-surface/40。文字を載せない)" },
    { file: "resources/js/components/features/capture/ScenarioPreviewDialog.svelte", reason: "alpha-background-no-text", count: 1, note: "プレビュー枠の下地 (bg-text/5。文字を載せない)" },
    { file: "resources/js/components/features/capture/TakePreviewDialog.svelte", reason: "alpha-background-no-text", count: 1, note: "プレビュー枠の下地 (bg-text/5。文字を載せない)" },
    { file: "resources/js/components/features/manual/TakePickerList.svelte", reason: "alpha-background-no-text", count: 1, note: "サムネイル枠の下地 (文字を載せない)" },
    { file: "resources/js/components/features/manual/TakePreviewPanel.svelte", reason: "alpha-background-no-text", count: 1, note: "プレビュー枠の下地 (bg-text/5。文字を載せない)" },
    { file: "resources/js/components/features/notifications/NotificationListItem.svelte", reason: "alpha-background-no-text", count: 1, note: "未読行の bg-primary-soft/40 だけを持つリテラル (前景は別のリテラル)" },
    { file: "resources/js/components/molecules/DangerZone.svelte", reason: "alpha-background-no-text", count: 1, note: "危険操作枠の下地 (bg-danger/5。文字は子要素が持つ)" },
    { file: "resources/js/components/molecules/Pagination.svelte", reason: "variant-composition", count: 1, note: "ページ送りボタンの hover と focus-visible が別の変種列で同居する" },
    { file: "resources/js/components/molecules/PasswordInput.svelte", reason: "variant-composition", count: 1, note: "表示切替ボタンの hover と focus-visible が別の変種列で同居する" },
    { file: "resources/js/components/molecules/StatCard.svelte", reason: "alpha-background-no-text", count: 1, note: "アイコン帯の半透明背景 (文字を載せない)" },
    { file: "resources/js/components/organisms/Modal.svelte", reason: "alpha-background-no-text", count: 1, note: "オーバーレイの bg-text/50 (文字を載せない)" },
    { file: "resources/js/components/organisms/Modal.svelte", reason: "variant-composition", count: 1, note: "閉じるボタンの hover と focus-visible が別の変種列で同居する" },
    { file: "resources/js/components/organisms/ToastContainer.svelte", reason: "variant-composition", count: 1, note: "閉じるボタンの hover と focus-visible が別の変種列で同居する" },
    { file: "resources/js/components/templates/AppLayout.svelte", reason: "alpha-background-no-text", count: 1, note: "サイドバーの背後を覆うオーバーレイ (bg-text/50。文字を載せない)" },
    { file: "resources/js/pages/Debug/Login.svelte", reason: "variant-composition", count: 1, note: "開発用ログインボタンの hover と focus-visible が別の変種列で同居する" },
    { file: "resources/js/pages/Guest/Pricing.svelte", reason: "alpha-background-no-text", count: 1, note: "強調カードの帯の半透明背景 (文字は子要素が持つ)" },
    { file: "resources/js/pages/Organizations/ApiKeys/Index.svelte", reason: "alpha-background-no-text", count: 1, note: "キー表示欄の下地 (文字は子要素が持つ)" },
] as const satisfies readonly UndecidableEntry[];

/**
 * 未検査であることを明示する pending 集合。**i16 の完了後も空にならない**。
 *
 * contrast-invariant はこれらを検査しない — 「gate があるからコントラストは守られている」
 * という誤読を作らないための宣言。
 *
 * 列挙は `UNDECIDABLE_REASONS` (実行時の配列) から**生成する** (散文で数を書かない。
 * 分類を足したのに pending の説明が古いまま、という食い違いを作らない)。
 *
 * **出口**: pending 項目に対応したらその行を削る。全部消えたら
 * 本 export と contrast-invariant.test.ts の
 * 「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」テストを**同時に削除**すること
 * (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
 */
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring " +
        "(正典 i17 により本 gate の対象外)",
    `UNDECIDABLE_PAIR_LEDGER に載せた分類: ${UNDECIDABLE_REASONS.map((r) => r.label).join(" / ")}。` +
        "値域の正本は UNDECIDABLE_REASONS で、分類の全数性は contrast-invariant の it が " +
        "never へ収束させ、「各 reason を発火させる検体が 1 つ以上ある」ことは " +
        "class-usage.test.ts の固定検体が担当する",
] as const;

/*
 * ===== DESIGN.md §Components ⇔ 部品ファイルの双方向一致の入力 (正典 i10) =====
 */

export type ComponentDirKind = "documented" | "excluded";

export interface ComponentDirSpec {
    readonly kind: ComponentDirKind;
    /** `excluded` は理由必須 (どこが担当するかを見えるようにする) */
    readonly reason?: string;
}

export type ComponentDirClassification = Readonly<Record<string, ComponentDirSpec>>;

/**
 * §Components の対象にするサブディレクトリの全数分類 (既定拒否)。
 * キーは `resources/js/components` からの相対パスである。
 *
 * `excluded` の配下は再帰を止めるので、そこに入れ子のキーを登録しても
 * **判定に使われない死んだ登録**になる (使われなかった登録は gate が落とす)。
 */
export const COMPONENT_DIR_CLASSIFICATION = {
    "atoms": { kind: "documented" },
    "molecules": { kind: "documented" },
    "organisms": { kind: "documented" },
    "templates": {
        kind: "excluded",
        reason:
            "レイアウトの骨格。使い分けは DESIGN.md §Layout と page-shell-structure.test.ts が担当する",
    },
    "features": {
        kind: "excluded",
        reason: "ドメイン部品。使い分けは各 feature の設計が決め、DS の再利用部品カタログではない",
    },
    "atoms/icons": {
        kind: "excluded",
        reason:
            "Lucide に無いブランド/SSO ロゴの SVG 内包専用。svg-inline-allowlist.test.ts が担当する",
    },
} as const satisfies ComponentDirClassification;

/**
 * ファイル種別。**この値が判定の正本である** (`kind` から母集団への入れ方が決まる) —
 * `component` = 節を要求する部品 / `types` = 対の部品の存在を確認する型ファイル /
 * `helper` = 母集団に入れない共有 helper。
 *
 * ★「節を要求するか」を別の真偽値で持たない — `kind` と食い違う組合せ
 *   (`helper` なのに節を要求する等) を表現できてしまい、`kind` が判定に使われないまま残る。
 * ★**判別可能 union で理由を必須化する** — 節を要求しない種別 (`types` / `helper`) は
 *   「なぜ部品カタログの母集団に入れないのか」を書かなければ登録できない
 *   (任意項目にすると理由なしの新種別を足せてしまい、既定拒否が形骸化する)。
 *   `component` は母集団に入る側なので理由は要らない。
 */
export type ComponentFileKindSpec =
    | { readonly kind: "component" }
    | { readonly kind: "types" | "helper"; readonly reason: string };

export type ComponentFileKinds = Readonly<Record<string, ComponentFileKindSpec>>;

/**
 * 対象ディレクトリ直下のファイル種別の全数分類 (既定拒否)。
 *
 * 照合は**最長接尾辞一致**である (`.types.ts` は `.ts` の接尾辞でもあり、
 * 照合順が未定義だと `Button.types.ts` が helper へ誤分類されうる)。
 *
 * `.gitkeep` は**登録しない** — 実在するのは `atoms/icons` の 1 件だけで、
 * そこは `excluded` として再帰を止めるため判定に到達せず、登録すると死んだ登録になる。
 * 対象ディレクトリの直下に置かれたら未分類として赤くなり、そのとき分類を書けばよい。
 */
export const COMPONENT_FILE_KINDS = {
    ".svelte": { kind: "component" },
    ".types.ts": {
        kind: "types",
        reason: "型と variant 表。同名の *.svelte が対になっていることを検査する",
    },
    ".ts": {
        kind: "helper",
        reason: "共有 helper。現状 1 件 = atoms/input-state.ts (入力系 atom の共通スタイル定義)",
    },
} as const satisfies ComponentFileKinds;

export interface ComponentSectionMapping {
    readonly section: string;
    readonly files: readonly string[];
    readonly reason: string;
}

export type ComponentSectionMappings = readonly ComponentSectionMapping[];

/** 既定の対応 (節名 = ファイル名) に乗らない対応の申告 (理由必須。正典 i10)。 */
export const COMPONENT_SECTION_MAPPINGS = [
    {
        section: "Input / Textarea / Select(入力系 atom)",
        files: ["atoms/Input.svelte", "atoms/Textarea.svelte", "atoms/Select.svelte"],
        reason: "3 つの入力 atom は同じ枠・同じ状態表現を共有するため 1 節で意味論を定義している",
    },
    {
        section: "Toast",
        files: ["organisms/ToastContainer.svelte"],
        reason: "節名は利用者から見た概念 (Toast)、実装は容器 1 本 (ToastContainer)",
    },
    {
        section: "PageHeader / PageHeaderSection",
        files: ["molecules/PageHeader.svelte", "molecules/PageHeaderSection.svelte"],
        reason: "ページ見出しと節見出しは対で使うため 1 節で使い分けを定義している",
    },
] as const satisfies ComponentSectionMappings;
