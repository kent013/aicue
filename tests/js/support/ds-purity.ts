/**
 * DS purity 検証の共通定義 (副作用なし helper)。
 *
 * 静的走査 (architecture test) と atom 単体テストで同一の禁止パターンを共有し、
 * 検知ギャップを構造的に排除する。
 *
 * パターンは 2 系統に分離する (テンプレート設計判断 Q15):
 *   - UNIVERSAL_PATTERNS: テーマに依存しない普遍ルール (token 迂回の禁止)。
 *     テーマを差し替えても変更しない。
 *   - THEME_PATTERNS: 既定テーマ (DESIGN.md Slate × Blue) の制約から導出されるルール
 *     (影なし・gradient なし・rounded 3 段・typography ramp 必須)。
 *     テーマを差し替えるアプリは DESIGN.md と同一 PR でここも更新する。
 *
 * 値変更時の運用契約: 本ファイルを変更する PR は DESIGN.md / tokens.css /
 * docs/design-system.md を同一 PR で必ず更新する。
 */

const RAW_PALETTE_COLORS =
    "(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|stone)";

/** テーマ非依存の普遍ルール。テーマ差し替え時も変更しない。 */
export const UNIVERSAL_PATTERNS = [
    [
        new RegExp(
            `\\b(?:bg|text|border|ring|fill|stroke|outline|decoration|divide|accent|caret|placeholder|from|to|via)-${RAW_PALETTE_COLORS}-\\d+`,
        ),
        "raw palette 色は禁止。DS token (bg-primary 等) を使う",
    ],
    [
        /-\[#[0-9a-fA-F]{3,8}\]/,
        "hex 直書き utility は禁止。DS token を使う",
    ],
    [
        /#[0-9A-Fa-f]{6}\b/,
        "hex 直書きは禁止。DS token (tokens.css) を経由する",
    ],
    [/\bz-\[[^\]]+\]/, "arbitrary z-index は禁止。z-0/10/20/30/40/50 の ramp を使う"],
    [
        /style="[^"{]*"/,
        "静的 inline style は禁止 (Tailwind utility を使う。動的補間 {expr} は可)",
    ],
] as const satisfies ReadonlyArray<readonly [RegExp, string]>;

/** 既定テーマ (DESIGN.md Slate × Blue) 由来のルール。テーマ差し替え時は DESIGN.md と同期して見直す。 */
export const THEME_PATTERNS = [
    [
        /\bshadow-(?:2xs|xs|sm|md|lg|xl|2xl|inner)\b/,
        "shadow utility は禁止 (DESIGN.md §Elevation = 明度差とボーダーで階層)",
    ],
    [/\bbg-(?:linear|gradient|radial|conic)-/, "gradient は禁止 (DESIGN.md §Elevation)"],
    [/(?:hover|active|focus):scale-/, "scale 効果は禁止 (DESIGN.md §Elevation)"],
    [
        /\brounded\b(?![-\w])/,
        "素の rounded は禁止 (DESIGN.md §Shapes = rounded-sm/md/lg の 3 段のみ)",
    ],
    [
        /\brounded-(?:xs|xl|2xl|3xl|4xl|full)\b/,
        "rounded-sm/md/lg 以外の段は禁止 (rounded-full は真に円形な UI のみ allowlist で許可)",
    ],
    [
        /\brounded-(?:t|b|l|r|tl|tr|bl|br|s|e|ss|se|ee|es)(?:-|\b)/,
        "方向別 rounded は禁止 (DESIGN.md §Shapes)",
    ],
    [/\brounded-\[[^\]]+\]/, "任意値 rounded は禁止 (DESIGN.md §Shapes)"],
    [
        /\btext-(?:xs|sm|base|lg|xl|(?:[2-9]|[1-9]\d+)xl)\b/,
        "raw text-size は禁止。typography ramp (text-{display,h1,h2,h3,body,caption}) を使う",
    ],
    [/\btext-\[[^\]]+\]/, "任意値 text utility は禁止。ramp を使う"],
    [
        /\bfont-(?!(?:normal|medium|mono)(?![\w-]))[a-z][\w-]*/,
        "font utility は font-normal/font-medium/font-mono 以外禁止 (weight は 400/500、family は ramp が内包)",
    ],
    [/font-size\s*:/, "inline font-size は禁止。ramp utility を使う"],
    [/font-weight\s*:/, "inline font-weight は禁止。ramp utility を使う"],
] as const satisfies ReadonlyArray<readonly [RegExp, string]>;

/**
 * file-scoped allowlist。例外は必ず理由・撤去条件・lifecycle を持つ。
 * lifecycle: permanent = 恒久例外 (brand 色等) / transitional = 撤去予定 (撤去条件必須)。
 */
export interface FileScopedAllowlistEntry {
    /** resources/js からの相対パス */
    file: string;
    /**
     * 許可する class トークン (区切り文字で分割したトークンとの**完全一致**)。
     * 変種の修飾や重要度の修飾が付いた形 (`sm:rounded-full` / `!rounded-full`) は
     * **別のトークン**なので、必要ならそれ自体を 1 行足して登録する (自動では免罪しない)。
     */
    patterns: readonly string[];
    reason: string;
    owner_phase: string;
    remove_condition: string;
    reason_classes: ReadonlyArray<
        | "domain_semantic_color"
        | "brand_guideline"
        | "legacy_layout_dependency"
        | "animation_keyframe"
        | "a11y_requirement"
        | "truly_circular_ui"
    >;
    lifecycle: "permanent" | "transitional";
}

export const FILE_SCOPED_ALLOWLIST: readonly FileScopedAllowlistEntry[] = [
    {
        file: "components/atoms/Avatar.svelte",
        patterns: ["rounded-full"],
        reason: "アバターは真に円形な UI (DESIGN.md §Shapes の ramp 外例外)",
        owner_phase: "template",
        remove_condition: "なし (アバターが真円である限り恒久)",
        reason_classes: ["truly_circular_ui"],
        lifecycle: "permanent",
    },
    {
        file: "components/atoms/Toggle.svelte",
        patterns: ["rounded-full"],
        reason: "トグルスイッチのトラックとつまみは真に円形な UI (DESIGN.md §Shapes の ramp 外例外)",
        owner_phase: "template",
        remove_condition: "なし (真に円形な UI である限り恒久)",
        reason_classes: ["truly_circular_ui"],
        lifecycle: "permanent",
    },
];

/** 指定ファイルの allowlist patterns を返す */
export function allowlistPatternsFor(relPath: string): readonly string[] {
    return FILE_SCOPED_ALLOWLIST.find((e) => e.file === relPath)?.patterns ?? [];
}

/**
 * class トークンを構成する文字。これ以外の文字はすべて区切りとして扱う。
 *
 * 含める文字と理由:
 *   英数字 / `_` / `-`  … utility 名の本体 (`rounded-full`)
 *   `:`                 … 変種の修飾 (`sm:` `hover:`)
 *   `/`                 … 不透明度の指定 (`bg-primary/50`)
 *   `.` `%`             … 任意値の中の数値 (`w-[62.5%]`)
 *   `[` `]`             … 任意値 (`text-[13px]`)
 *   `!`                 … 重要度の修飾 (`!rounded-full` / `rounded-full!`)
 *   `#`                 … 色の直値 (`#1DA1F2`。将来ブランド色を登録するときに 1 トークンで扱えるようにする)
 *
 * **保証しないもの (誇張しない)**: 丸括弧・`@`・カンマを含む書き方
 * (`bg-(--var)` / `@md:flex`) はここでトークンが割れるため、その形は
 * 許可一覧に**登録できない**。登録が要るようになったらこの文字集合を広げる
 * (広げたら「許可一覧の全エントリが単一の class トークンとして成立している」検査が
 * 巻き添えで赤くなるので、黙って広がることはない)。
 */
const CLASS_TOKEN_PATTERN = /[A-Za-z0-9_:./[\]!%#-]+/g;

/** 許可一覧の 1 エントリが class トークンとして成立しているか (登録した瞬間に死んでいる例外を防ぐ) */
export function isSingleClassToken(value: string): boolean {
    const matched = value.match(CLASS_TOKEN_PATTERN);

    return matched !== null && matched.length === 1 && matched[0] === value;
}

/**
 * content から allowlist で許可された class トークンを除去する (除去後に禁止パターンを適用する)。
 *
 * 除去は**区切り文字で分割した class トークンの完全一致**でのみ行う。
 * 素の部分文字列で除去すると、許可語を部分に含む別の語 (`!rounded-full` /
 * `sm:rounded-full` / `rounded-full/50`) まで一緒に消えて**検出漏れ**になる。
 * 許可したのは「真に円形な UI であること」だけであり、変種の修飾や重要度の修飾が
 * 付いた別の書き方まで許した覚えはない。
 *
 * トークンの前後は必ず区切り文字なので、除去によって隣り合うトークンが連結することはない。
 */
export function stripAllowlisted(relPath: string, content: string): string {
    const allowed = allowlistPatternsFor(relPath);
    if (allowed.length === 0) {
        return content;
    }
    const allowedTokens = new Set(allowed);

    return content.replace(CLASS_TOKEN_PATTERN, (token) =>
        allowedTokens.has(token) ? "" : token,
    );
}
