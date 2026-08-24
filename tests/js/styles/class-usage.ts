/**
 * resources/js の class 記述から「前景 × 背景の組」と「解決できなかった形」を導出する走査器。
 *
 * 【走査分母】resources/js のディレクトリ単位の再帰走査 (`*.svelte` / `*.ts`)。
 *   ファイルを足したら自動で分母に入る (正典 i15 / s14: 固定のファイル列挙は足し忘れが静かに起きる)。
 *   走査根が存在しなければ **fail-fast**。
 *
 * 【解析の方式】**既存の解析器で構文木にしてから読む**。自前の字句走査は書かない。
 *   準拠実装がリポジトリに在る — `tests/js/support/file-input-scan.ts` は `svelte/compiler` の
 *   `parse()` で `.svelte` を AST にし、解析できない形を診断へ落とす。
 *   - `.svelte`: `parse(source, { modern: true })` の AST を歩き、`class` 属性の `Text` チャンクと、
 *     式・script の中の**文字列リテラルのノード**を単位にする。
 *     parse が失敗したら診断 `svelte-parse-failed` にして gate を落とす
 *   - `.ts`: `ts.createSourceFile()` で AST 化し、`StringLiteral` /
 *     `NoSubstitutionTemplateLiteral` を単位、`TemplateExpression` (置換つき) を
 *     `interpolated` の判定不能にする。**`ts.createScanner()` は使わない** —
 *     scanner は字句解析器であり `` `${cond ? "}" : v}` `` の `}` が補間の終端か
 *     object literal の内側かを判断するには構文文脈が要る。
 *     **parse diagnostics が 1 件でもあれば解析失敗**にする (括弧の不整合など構文エラー全般が
 *     fail-closed になる)
 *   - 置換つき template を判定不能として記録したら、**その subtree へは降りない**
 *     (降りると補間内部の文字列を独立した class 単位として二重に拾う)
 *   - **構文解析の失敗はすべて診断**にする (例外は投げない)。診断が出たファイルの
 *     `occurrences` / `pairs` は**空にする** (部分結果を後続 gate が使う形を作らない)
 *
 * 【走査単位 (これが保証する構文集合)】**文字列リテラル** (と `class` 属性の静的テキスト)。
 *   単位の中だけで状態と組を作る。**それ以外の形については検出力を主張しない**。
 *   代わりに、扱えない**既知の入口**を語彙の deny (`unsupportedEntryPoints()`) で 0 件に固定する。
 *
 * 【class 候補の分解 (4 段)】
 *   1. まず **CSS の空白** (空白 / タブ / 改行 / CR / FF) で class 候補へ分割する
 *   2. **監視対象かどうかを先に判定する** (`isWatchedCandidate()`)。
 *      これが無いと import 指定子 (`"./Button.types"`) や URL のような
 *      「そもそも class ではない文字列」まで文字検証に掛かって `unparsable-token` になる。
 *      判定は 3 段で、**文字検証はしない** —
 *        (a) 先頭から `<何らかの文字列>:` の並びを variant 列として剥がす (最後の `:` まで)
 *        (b) 残りの先頭の `!` を剥がす
 *        (c) 残りが**監視対象接頭辞** (`WATCHED_UTILITY_PREFIXES`) のいずれかで始まるなら監視対象
 *   3. **監視対象と判定した候補だけ**を、候補**全体**の許可文字検証へ回す。
 *      **許可外の文字が 1 つでもあれば候補全体を `unparsable-token`** にする
 *   4. そのうえで variant / important / alpha / utility を分解する
 *   「許可文字以外はすべて区切り」という規則は**採らない** — それだと `bg-primaryあ` が
 *   `bg-primary` へ縮退して**有効な token として通り**、`bg-(--var)` も候補全体を
 *   未解決にする根拠を失う。
 *
 * 【許可する文字集合 (共通規約 (e) の宣言)】
 *   英数字 / `_` / `-` / `:` / `/` / `.` / `%` / `[` / `]` / `!` / `#` / `&`。
 *   `&` 以外は `tests/js/support/ds-purity.ts` の `CLASS_TOKEN_PATTERN` と同じ集合である。
 *   `&` を足しているのは、本リポジトリに**任意変種**の実例 (`[&_svg]:stroke-current`) が
 *   在るためで、ds-purity は許可一覧の照合にしか使わないので必要が無かった。
 *   割れない書き方 (丸括弧・`@`・カンマ・`=`) は候補全体が `unparsable-token` になる。
 *
 * 【不透明度修飾の受理範囲】`/` + 半角数字 1〜3 桁で値が **0..100** の形だけを受理する。
 *   - `/100` は**修飾なし (不透明)** と同じ扱い (`alphaPercent === null`)
 *   - `/0` は**透明**なので背景が親から来る = `keyword-color` と同じ判定不能
 *   - 範囲外 (`/101`) / 負数 / 小数 / 任意値 (`/[0.35]`) は
 *     `unresolved: "unsupported-alpha-syntax"` にして**素通りさせない**
 *
 * 【状態の作り方】素の宣言を基底の状態とし、同じ修飾の連なり (`hover:` / `disabled:` …) を
 *   持つ宣言は基底をその修飾で上書きした状態とする。組は状態の内側だけで作る。
 *   発火条件の形式化 — 各候補は variant 列 `V` を持つ (素の宣言は空列)。
 *   単位内の**非空の `V` の集合**を `S` とする (**基底は継承元なので `S` に入れない**)。
 *   `|S| <= 1` → 解決可能。基底を `S` の唯一の列で channel ごとに上書きした状態を作る。
 *   `|S| >= 2` → **`variant-composition` の判定不能** (channel を跨いで単位全体を落とす)。
 *   variant 条件の包含関係は Tailwind の意味論であり、自前で再実装しない。
 *   これをしないと `"bg-surface text-danger hover:bg-danger hover:text-neutral"` から
 *   `text-danger on bg-danger` (比 1.0) という**実在しない組**が生まれる。
 *
 * 【保証しないもの (誇張しない)】
 *   - **宣言の単位をまたいで成立する組**。実例: atoms/input-state.ts は `text-text` を
 *     INPUT_BASE_CLASSES に、`bg-surface` / `bg-neutral` を inputStateClass() の戻り値に持つ。
 *     ただしこの穴の大部分は役割の直積 (正典 i14) が覆っている — 両方の token に役割が在れば、
 *     その組は宣言が割れていても既に母集団の内側にある。見えないのは
 *     「直積に現れない役割の組み合わせの 2 token が同じ要素に載り、かつ宣言の単位が割れている」
 *     場合だけである
 *   - **親から渡る class** (`extraClass`) と**親要素から継承する背景** (正典 i22 (2))
 *   - **実行時に組み立てられる class** (正典 i22 (1))。とくに
 *     **静的な部分にテーマ名前空間の語を 1 つも持たない補間** (`` `${classes}` `` /
 *     `class={classes}`) は、class 記述なのか他の用途の文字列なのかを静的に区別できないため
 *     **単位を作らない** (判定不能にも数えない)。ここに検出力は主張しない —
 *     この形で class を組み立てると本走査器の全 gate を迂回できる。
 *     迂回を止めているのは走査器ではなく、**そう書かない**という規約と人のレビューである
 *   - **DOM の実際の入れ子**。同じ単位に載っていることは「同じ要素にある」ことの近似である
 *   - **変種の修飾の綴りが正しいこと**。`hoverr:bg-primary` は token としては解決する
 *     (変種の名前空間は Tailwind のもので、本アプリの写像ではない)
 *   - `resources/views/vendor/mail/html/themes/template.css` は走査根の外である
 *     (Laravel 同梱メールテーマの独立パレットで DS token の写像ではない)
 */
import fs from "node:fs";
import path from "node:path";
import postcss from "postcss";
import ts from "typescript";
import { parse as parseSvelte } from "svelte/compiler";
import { REPO_ROOT } from "./design-md";
import { cssColorTokens, cssRadiusTokens, cssRampUtilities, parseCssColor } from "./theme-map";
import { NON_TOKEN_WORD_CONTRACT, UNDECIDABLE_REASONS } from "./inventory";
import type { UndecidableReason } from "./inventory";

export type { UndecidableReason };

/**
 * 監視対象にするテーマ名前空間の接頭辞。**1 か所だけ**に宣言し、
 * S3 (参照の閉包) と共有する。
 */
export const WATCHED_UTILITY_PREFIXES = [
    "bg-",
    "text-",
    "border-",
    "ring-",
    "divide-",
    "outline-",
    "rounded-",
    "fill-",
    "stroke-",
    "decoration-",
    "accent-",
    "caret-",
    "placeholder-",
    "from-",
    "to-",
    "via-",
] as const;

/** 色 utility の channel。**前景 / 背景以外も分類する** (正典 i17 の非テキスト境界を混ぜないため)。 */
export type ColorChannel = "background" | "foreground" | "border" | "ring" | "other";

const CHANNEL_BY_PREFIX: Readonly<Record<string, ColorChannel>> = {
    "bg-": "background",
    "text-": "foreground",
    "border-": "border",
    "ring-": "ring",
};

/**
 * 色そのものを指す CSS のキーワード。契約表の語のうちこれらだけが
 * 「その channel の色宣言」として状態に効く (`text-center` は整列であって前景色ではない)。
 */
const COLOR_KEYWORDS: readonly string[] = [
    "transparent",
    "current",
    "inherit",
    "initial",
    "unset",
    "revert",
];

/** 解決できなかった理由。 */
export type UnresolvedReason =
    /** テーマ名前空間の接頭辞を持つが写像にも契約表にも無い */
    | "unknown-token"
    /** 色でない utility に不透明度修飾が付いている */
    | "alpha-on-non-color"
    /** 不透明度修飾の書き方が受理範囲外 */
    | "unsupported-alpha-syntax"
    /** 区切りで割れた形 (`bg-(--var)` / 非 ASCII の混入) */
    | "unparsable-token";

/** utility 名の解決結果 (判別可能 union。未解決を無言で候補から外さない = 共通規約 (b))。 */
export type TokenResolution =
    | { readonly kind: "color"; readonly channel: ColorChannel; readonly suffix: string }
    | { readonly kind: "ramp"; readonly name: string }
    | { readonly kind: "radius"; readonly name: string }
    | { readonly kind: "contract"; readonly word: string }
    | { readonly kind: "unresolved"; readonly reason: UnresolvedReason };

/** class トークン 1 件の共通出力 (S3 / S5 / S7 はここから導出する)。 */
export interface ClassTokenOccurrence {
    /** リポジトリ相対のファイルパス */
    readonly file: string;
    /** 走査単位 (文字列リテラル) の識別子。行番号は持たない (正典 s14) */
    readonly unit: string;
    /** 区切りで分割したままの生のトークン (診断用。期待値には使わない) */
    readonly raw: string;
    /** 変種の修飾を出現順に並べたもの (`["sm", "hover"]`)。素の宣言は空配列 */
    readonly variants: readonly string[];
    /** 重要度の修飾が付いているか */
    readonly important: boolean;
    /** 変種・重要度・不透明度を取り除いた utility 名 (`bg-primary` / `text-center`) */
    readonly utility: string;
    /**
     * 不透明度修飾の**百分率** (0..100 の整数)。`null` は修飾なし。
     * 名前で単位を分ける — 0..1 の実効値を持つのは
     * `ResolvedAlphaBackground.effectiveAlpha` **だけ**である。
     */
    readonly alphaPercent: number | null;
    /** utility 名が何へ解決したか */
    readonly resolution: TokenResolution;
}

/** `var(--…)` 参照 (class ではない別チャネル)。 */
export interface CssVarReference {
    readonly file: string;
    readonly name: string;
    readonly resolution: TokenResolution;
}

export type CssVarDiagnosticReason =
    | "unterminated-string"
    | "unterminated-function"
    | "unresolvable-var"
    | "unsupported-at-rule-params"
    | "css-parse-failed";

export interface CssVarReferenceDiagnostic {
    readonly file: string;
    readonly reason: CssVarDiagnosticReason;
    readonly detail: string;
}

export interface CssVarReferenceScan {
    readonly references: readonly CssVarReference[];
    readonly diagnostics: readonly CssVarReferenceDiagnostic[];
    /** 走査したファイル (リポジトリ相対、ソート済み) */
    readonly files: readonly string[];
    /** 走査根ごとのファイル数 (根が丸ごと読めていない状態を捕まえる) */
    readonly perRoot: ReadonlyMap<string, number>;
}

/** 走査で得た 1 つの組。 */
export type ScannedPair =
    | { readonly kind: "opaque"; readonly file: string; readonly fg: string; readonly bg: string }
    | {
          readonly kind: "alpha-background";
          readonly file: string;
          readonly fg: string;
          readonly bg: string;
          /** class 修飾の百分率 (0..100)。`null` は修飾なし (token の値が持つ alpha だけ) */
          readonly modifierPercent: number | null;
      }
    | { readonly kind: "undecidable"; readonly file: string; readonly reason: UndecidableReason };

/** 不透明のみの不完全な単位 (前景か背景の片方しか無い) の集計。 */
export interface IncompleteOpaqueCounts {
    readonly backgroundOnly: number;
    readonly foregroundOnly: number;
}

export interface ClassScanDiagnostic {
    readonly file: string;
    readonly reason: "svelte-parse-failed" | "ts-diagnostic";
    /** 解析器が返したメッセージ (診断出力用。期待値には使わない) */
    readonly detail: string;
}

/** **1 本のソース**の解析結果 (純粋入口が返す形)。 */
export interface SourceClassUsageScan {
    readonly occurrences: readonly ClassTokenOccurrence[];
    readonly pairs: readonly ScannedPair[];
    readonly incompleteOpaque: IncompleteOpaqueCounts;
    readonly diagnostics: readonly ClassScanDiagnostic[];
}

/** **実リポジトリ**の集約結果 (薄いラッパーが返す形)。 */
export interface ClassUsageScan extends SourceClassUsageScan {
    /** 走査したファイル (リポジトリ相対、ソート済み)。空なら呼び出し側が落とす */
    readonly files: readonly string[];
    /** `resources/js` の直下の子ごとの抽出件数 (どれかが丸ごと読めていない状態を捕まえる) */
    readonly perDirectory: ReadonlyMap<string, number>;
}

/** 走査器が扱えない**既知の入口**の出現 (0 件であることを gate が固定する)。 */
export interface UnsupportedEntryPoint {
    readonly file: string;
    readonly kind: "class-directive" | "class-helper-library" | "interpolated-prefix";
}

/* ===== 走査単位の抽出 ===== */

/** 1 つの走査単位 (文字列リテラル / class 属性の静的テキスト)。 */
interface Unit {
    readonly text: string;
    /** 補間つき template から作られた単位か (判定不能 `interpolated` になる) */
    readonly interpolated: boolean;
}

interface SourceUnits {
    readonly units: readonly Unit[];
    /** `.svelte` の `<style>` の中身 (CSS として別経路で読む) */
    readonly styles: readonly string[];
    readonly diagnostics: readonly ClassScanDiagnostic[];
    readonly entryPoints: readonly UnsupportedEntryPoint[];
}

const CLASS_ATTRIBUTE = "class";

interface AstNode {
    readonly type: string;
    readonly [key: string]: unknown;
}

const isAstNode = (value: unknown): value is AstNode =>
    typeof value === "object" &&
    value !== null &&
    typeof (value as { type?: unknown }).type === "string";

/**
 * template literal の quasis から「テーマ名前空間の接頭辞の**内側**に補間が入る形」を探す。
 *
 * 判定は「補間の直前にある空白区切りの断片が**監視対象の候補**である」こと。
 * `bg-${tone}` / `text-body${x}` は当たり、`take-thumbnail-${id}` や
 * `${border} bg-neutral` は当たらない。
 */
function quasiPrefixEntryPoint(texts: readonly string[]): boolean {
    // 最後の quasi の後ろには補間が無いので見ない。
    for (const text of texts.slice(0, -1)) {
        const tail = text.split(CSS_WHITESPACE).pop() ?? "";
        if (tail !== "" && isWatchedCandidate(tail)) return true;
    }

    return false;
}

/** 単位のテキストに監視対象の候補が含まれるか (判定不能へ落とすかの判断に使う)。 */
function containsWatched(text: string): boolean {
    return splitCandidates(text).some((candidate) => isWatchedCandidate(candidate));
}

function svelteUnits(source: string, file: string): SourceUnits {
    const units: Unit[] = [];
    const entryPoints: UnsupportedEntryPoint[] = [];

    let ast: unknown;
    try {
        ast = parseSvelte(source, { modern: true });
    } catch (error) {
        return {
            units: [],
            styles: [],
            diagnostics: [
                {
                    file,
                    reason: "svelte-parse-failed",
                    detail: error instanceof Error ? error.message : String(error),
                },
            ],
            entryPoints: [],
        };
    }

    const pushTemplate = (node: AstNode): boolean => {
        const quasis = node["quasis"];
        const expressions = node["expressions"];
        if (!Array.isArray(quasis) || !Array.isArray(expressions)) return false;
        const texts = quasis.map((q) => {
            const value = isAstNode(q) ? (q["value"] as { raw?: unknown } | undefined) : undefined;
            return typeof value?.raw === "string" ? value.raw : "";
        });
        if (expressions.length === 0) {
            units.push({ text: texts.join(""), interpolated: false });

            return true;
        }
        if (quasiPrefixEntryPoint(texts)) {
            entryPoints.push({ file, kind: "interpolated-prefix" });
        }
        if (texts.some((text) => containsWatched(text))) {
            units.push({ text: texts.join(" "), interpolated: true });
        }

        return true;
    };

    const walk = (value: unknown): void => {
        if (Array.isArray(value)) {
            for (const item of value) walk(item);

            return;
        }
        if (typeof value !== "object" || value === null) return;

        if (isAstNode(value)) {
            const node = value;
            if (node.type === "Comment") return;
            if (node.type === "ClassDirective") {
                entryPoints.push({ file, kind: "class-directive" });
            }
            if (node.type === "Attribute" && String(node["name"]).toLowerCase() === CLASS_ATTRIBUTE) {
                const attrValue = node["value"];
                const parts = Array.isArray(attrValue) ? attrValue : [attrValue];
                for (const part of parts) {
                    if (!isAstNode(part) || part.type !== "Text") continue;
                    units.push({ text: String(part["data"] ?? ""), interpolated: false });
                }
                // 式の中のリテラルは下の一般規則が拾う (Text は上で拾ったので二重に採らない)
                for (const part of parts) {
                    if (!isAstNode(part) || part.type === "Text") continue;
                    walk(part);
                }

                return;
            }
            if (node.type === "Literal" && typeof node["value"] === "string") {
                units.push({ text: node["value"], interpolated: false });

                return;
            }
            if (node.type === "TemplateLiteral" && pushTemplate(node)) return;
        }

        for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
            if (key === "type" || key === "parent" || key === "loc" || key === "name_loc") continue;
            walk(child);
        }
    };

    walk((ast as { fragment?: unknown }).fragment);
    walk((ast as { instance?: unknown }).instance);
    walk((ast as { module?: unknown }).module);

    const styleSource = (ast as { css?: { content?: { styles?: unknown } } }).css?.content?.styles;
    const styles = typeof styleSource === "string" ? [styleSource] : [];

    return { units, styles, diagnostics: [], entryPoints };
}

function typescriptUnits(source: string, file: string): SourceUnits {
    const sourceFile = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, false, ts.ScriptKind.TS);
    const parseDiagnostics = (
        sourceFile as unknown as { parseDiagnostics?: readonly ts.Diagnostic[] }
    ).parseDiagnostics;
    if (parseDiagnostics === undefined) {
        throw new Error(`${file}: TypeScript の parseDiagnostics を取得できない`);
    }
    if (parseDiagnostics.length > 0) {
        return {
            units: [],
            styles: [],
            diagnostics: [
                {
                    file,
                    reason: "ts-diagnostic",
                    detail: ts.flattenDiagnosticMessageText(parseDiagnostics[0].messageText, " "),
                },
            ],
            entryPoints: [],
        };
    }

    const units: Unit[] = [];
    const entryPoints: UnsupportedEntryPoint[] = [];

    const visit = (node: ts.Node): void => {
        if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
            units.push({ text: node.text, interpolated: false });

            return;
        }
        if (ts.isTemplateExpression(node)) {
            const texts = [
                node.head.text,
                ...node.templateSpans.map((span) => span.literal.text),
            ];
            if (quasiPrefixEntryPoint(texts)) {
                entryPoints.push({ file, kind: "interpolated-prefix" });
            }
            if (texts.some((text) => containsWatched(text))) {
                units.push({ text: texts.join(" "), interpolated: true });
            }

            // 補間内部の文字列を独立した単位として二重に拾わないため subtree へ降りない
            return;
        }
        ts.forEachChild(node, visit);
    };
    ts.forEachChild(sourceFile, visit);

    return { units, styles: [], diagnostics: [], entryPoints };
}

/* ===== class 候補の分解 ===== */

const CSS_WHITESPACE = /[ \t\n\r\f]+/;
const ALLOWED_CANDIDATE_CHARS = /^[A-Za-z0-9_:./[\]!%#&-]+$/;
const ALPHA_MODIFIER = /^\d{1,3}$/;

function splitCandidates(text: string): readonly string[] {
    return text.split(CSS_WHITESPACE).filter((c) => c !== "");
}

/**
 * variant 列を剥がした残りと、剥がした列を返す。
 *
 * 分割は**角括弧の外のコロンだけ**で行う。素朴に `split(":")` すると
 * 任意値の中のコロン (`text-[color:#ffffff]` / `bg-[url(a:b)]`) を variant 境界と誤認し、
 * 残りが監視対象接頭辞で始まらなくなって**候補ごと無言で母集団から外れる** (fail-open)。
 * 角括弧の中は Tailwind の任意値なので、そこにコロンがあっても変種の区切りではない。
 */
function splitVariants(candidate: string): { variants: readonly string[]; rest: string } {
    const variants: string[] = [];
    let depth = 0;
    let start = 0;
    for (let i = 0; i < candidate.length; i += 1) {
        const ch = candidate[i];
        if (ch === "[") depth += 1;
        else if (ch === "]") depth = Math.max(0, depth - 1);
        else if (ch === ":" && depth === 0) {
            variants.push(candidate.slice(start, i));
            start = i + 1;
        }
    }

    return { variants, rest: candidate.slice(start) };
}

/** 監視対象の候補か (文字検証はしない)。 */
export function isWatchedCandidate(candidate: string): boolean {
    const { rest } = splitVariants(candidate);
    const withoutImportant = rest.startsWith("!") ? rest.slice(1) : rest;

    return WATCHED_UTILITY_PREFIXES.some((prefix) => withoutImportant.startsWith(prefix));
}

function longestWatchedPrefix(utility: string): string | null {
    let found: string | null = null;
    for (const prefix of WATCHED_UTILITY_PREFIXES) {
        if (!utility.startsWith(prefix)) continue;
        if (found === null || prefix.length > found.length) found = prefix;
    }

    return found;
}

/** 契約表の語が「色そのものを指すキーワード」か (`bg-transparent` は真、`text-center` は偽)。 */
function isColorKeyword(utility: string): boolean {
    const prefix = longestWatchedPrefix(utility);
    if (prefix === null) return false;

    return COLOR_KEYWORDS.includes(utility.slice(prefix.length));
}

function contractClassWords(): ReadonlySet<string> {
    return new Set(
        NON_TOKEN_WORD_CONTRACT.filter((entry) => entry.kind === "class-word").map(
            (entry) => entry.word,
        ),
    );
}

function resolveUtility(utility: string, hasAlphaModifier: boolean): TokenResolution {
    const prefix = longestWatchedPrefix(utility);
    if (prefix === null) return { kind: "unresolved", reason: "unknown-token" };
    const rest = utility.slice(prefix.length);

    const colors = cssColorTokens();
    if (colors.has(rest)) {
        return { kind: "color", channel: CHANNEL_BY_PREFIX[prefix] ?? "other", suffix: rest };
    }
    if (hasAlphaModifier) return { kind: "unresolved", reason: "alpha-on-non-color" };
    if (prefix === "text-" && cssRampUtilities().has(rest)) return { kind: "ramp", name: rest };
    if (prefix === "rounded-" && cssRadiusTokens().has(rest)) return { kind: "radius", name: rest };
    if (contractClassWords().has(utility)) return { kind: "contract", word: utility };

    return { kind: "unresolved", reason: "unknown-token" };
}

/** 監視対象の候補 1 件を分解する。 */
function decompose(file: string, unit: string, candidate: string): ClassTokenOccurrence {
    const base = {
        file,
        unit,
        raw: candidate,
        variants: [] as readonly string[],
        important: false,
        utility: candidate,
        alphaPercent: null,
    };
    if (!ALLOWED_CANDIDATE_CHARS.test(candidate)) {
        return { ...base, resolution: { kind: "unresolved", reason: "unparsable-token" } };
    }

    const { variants, rest } = splitVariants(candidate);
    const important = rest.startsWith("!");
    const withoutImportant = important ? rest.slice(1) : rest;

    const slash = withoutImportant.lastIndexOf("/");
    let utility = withoutImportant;
    let alphaPercent: number | null = null;
    let hasAlphaModifier = false;
    if (slash >= 0) {
        const modifier = withoutImportant.slice(slash + 1);
        utility = withoutImportant.slice(0, slash);
        if (!ALPHA_MODIFIER.test(modifier) || Number(modifier) > 100) {
            return {
                file,
                unit,
                raw: candidate,
                variants,
                important,
                utility: withoutImportant,
                alphaPercent: null,
                resolution: { kind: "unresolved", reason: "unsupported-alpha-syntax" },
            };
        }
        hasAlphaModifier = true;
        const percent = Number(modifier);
        alphaPercent = percent === 100 ? null : percent;
    }

    return {
        file,
        unit,
        raw: candidate,
        variants,
        important,
        utility,
        alphaPercent,
        resolution: resolveUtility(utility, hasAlphaModifier),
    };
}

/* ===== 状態と組の構築 ===== */

const ELEMENT_OPACITY_PREFIX = "opacity-";

interface OpacityCandidate {
    readonly variantKey: string;
}

/**
 * token の**値そのもの**が持つ alpha (派生 token だけが持つ)。不透明なら `null`。
 *
 * 色表現の読み出しは `parseCssColor()` の 1 実装へ集約する (簡易パーサを別に持つと、
 * 片方だけが受理する書き方 = `rgb(r g b / a)` で判定が食い違う)。
 */
function alphaOfSuffix(suffix: string): number | null {
    const value = cssColorTokens().get(suffix);
    if (value === undefined) return null;
    const parsed = parseCssColor(value);

    return parsed.kind === "alpha" ? parsed.alpha : null;
}

interface UnitScan {
    readonly pairs: readonly ScannedPair[];
    readonly backgroundOnly: number;
    readonly foregroundOnly: number;
}

function scanUnit(file: string, unit: Unit, occurrences: readonly ClassTokenOccurrence[]): UnitScan {
    if (unit.interpolated) {
        return {
            pairs: [{ kind: "undecidable", file, reason: "interpolated" }],
            backgroundOnly: 0,
            foregroundOnly: 0,
        };
    }

    const opacity: OpacityCandidate[] = [];
    for (const candidate of splitCandidates(unit.text)) {
        const { variants, rest } = splitVariants(candidate);
        const withoutImportant = rest.startsWith("!") ? rest.slice(1) : rest;
        if (withoutImportant.startsWith(ELEMENT_OPACITY_PREFIX)) {
            opacity.push({ variantKey: variants.join(":") });
        }
    }

    const variantKeys = new Set<string>();
    for (const occurrence of occurrences) {
        if (occurrence.variants.length > 0) variantKeys.add(occurrence.variants.join(":"));
    }
    for (const item of opacity) {
        if (item.variantKey !== "") variantKeys.add(item.variantKey);
    }

    if (variantKeys.size >= 2) {
        return {
            pairs: [{ kind: "undecidable", file, reason: "variant-composition" }],
            backgroundOnly: 0,
            foregroundOnly: 0,
        };
    }

    const states: readonly string[] = variantKeys.size === 0 ? [""] : ["", [...variantKeys][0]];
    const reasons = new Set<UndecidableReason>();
    const pairs: ScannedPair[] = [];
    let backgroundOnly = 0;
    let foregroundOnly = 0;

    const inChannel = (channel: ColorChannel, variantKey: string): ClassTokenOccurrence[] =>
        occurrences.filter(
            (o) =>
                o.variants.join(":") === variantKey &&
                ((o.resolution.kind === "color" && o.resolution.channel === channel) ||
                    // 契約表の語のうち**色キーワード**だけが channel の色宣言として効く
                    (o.resolution.kind === "contract" &&
                        isColorKeyword(o.utility) &&
                        CHANNEL_BY_PREFIX[longestWatchedPrefix(o.utility) ?? ""] === channel)),
        );

    for (const variantKey of states) {
        const pick = (channel: ColorChannel): ClassTokenOccurrence[] => {
            const own = inChannel(channel, variantKey);
            if (variantKey === "") return own;

            return own.length > 0 ? own : inChannel(channel, "");
        };

        const backgrounds = pick("background");
        const foregrounds = pick("foreground");
        const hasOpacity = opacity.some(
            (item) => item.variantKey === variantKey || item.variantKey === "",
        );

        if (backgrounds.length === 0 && foregrounds.length === 0) continue;

        const isAlphaBackground = (o: ClassTokenOccurrence): boolean =>
            o.resolution.kind === "color" &&
            (o.alphaPercent !== null || alphaOfSuffix(o.resolution.suffix) !== null);

        if (backgrounds.length >= 2) {
            reasons.add(
                backgrounds.some((o) => isAlphaBackground(o)) &&
                    backgrounds.some((o) => !isAlphaBackground(o))
                    ? "opaque-and-alpha-background"
                    : "multiple-background",
            );
            continue;
        }
        if (foregrounds.length >= 2) {
            reasons.add("multiple-foreground");
            continue;
        }

        const bg = backgrounds[0];
        const fg = foregrounds[0];

        if (hasOpacity) {
            reasons.add("element-opacity");
            continue;
        }
        if (fg !== undefined && (fg.alphaPercent !== null || fg.resolution.kind !== "color")) {
            reasons.add("foreground-alpha");
            continue;
        }
        if (bg !== undefined && (bg.resolution.kind !== "color" || bg.alphaPercent === 0)) {
            reasons.add("keyword-color");
            continue;
        }
        if (bg === undefined) {
            if (fg !== undefined) foregroundOnly += 1;
            continue;
        }
        if (bg.resolution.kind !== "color") continue;
        const bgSuffix = bg.resolution.suffix;
        const alpha = isAlphaBackground(bg);
        if (fg === undefined) {
            if (alpha) reasons.add("alpha-background-no-text");
            else backgroundOnly += 1;
            continue;
        }
        if (fg.resolution.kind !== "color") continue;
        if (alpha) {
            pairs.push({
                kind: "alpha-background",
                file,
                fg: fg.resolution.suffix,
                bg: bgSuffix,
                modifierPercent: bg.alphaPercent,
            });
            continue;
        }
        pairs.push({ kind: "opaque", file, fg: fg.resolution.suffix, bg: bgSuffix });
    }

    for (const reason of reasons) pairs.push({ kind: "undecidable", file, reason });

    return { pairs, backgroundOnly, foregroundOnly };
}

/* ===== 純粋入口 ===== */

/** 拡張子の全数分類 (最長接尾辞一致)。未分類の拡張子が現れたら fail-fast。 */
const EXTENSION_CLASSIFICATION = [
    { suffix: ".d.ts", scan: false },
    { suffix: ".svelte", scan: true },
    { suffix: ".ts", scan: true },
    { suffix: ".gitkeep", scan: false },
] as const;

/** 走査対象の拡張子か (最長接尾辞一致。未分類の拡張子は例外)。 */
export function isScannedFileName(name: string): boolean {
    return classifyExtension(name).scan;
}

function classifyExtension(name: string): (typeof EXTENSION_CLASSIFICATION)[number] {
    const matches = EXTENSION_CLASSIFICATION.filter((entry) => name.endsWith(entry.suffix));
    if (matches.length === 0) throw new Error(`未分類の拡張子: ${name}`);

    return matches.reduce((a, b) => (b.suffix.length > a.suffix.length ? b : a));
}

function extractUnits(source: string, file: string): SourceUnits {
    return file.endsWith(".svelte") ? svelteUnits(source, file) : typescriptUnits(source, file);
}

/** **純粋入口**: 1 本のソースから class の出現・組・診断を導出する。 */
export function scanClassUsageSource(source: string, file: string): SourceClassUsageScan {
    const { units, diagnostics } = extractUnits(source, file);
    if (diagnostics.length > 0) {
        return {
            occurrences: [],
            pairs: [],
            incompleteOpaque: { backgroundOnly: 0, foregroundOnly: 0 },
            diagnostics,
        };
    }

    const occurrences: ClassTokenOccurrence[] = [];
    const pairs: ScannedPair[] = [];
    let backgroundOnly = 0;
    let foregroundOnly = 0;

    for (const unit of units) {
        const unitOccurrences = splitCandidates(unit.text)
            .filter((candidate) => isWatchedCandidate(candidate))
            .map((candidate) => decompose(file, unit.text, candidate));
        occurrences.push(...unitOccurrences);

        const scan = scanUnit(file, unit, unitOccurrences);
        pairs.push(...scan.pairs);
        backgroundOnly += scan.backgroundOnly;
        foregroundOnly += scan.foregroundOnly;
    }

    return {
        occurrences,
        pairs,
        incompleteOpaque: { backgroundOnly, foregroundOnly },
        diagnostics: [],
    };
}

const CLASS_HELPER_LIBRARIES = ["clsx", "twMerge", "tailwind-merge", "classnames", "cva"] as const;
const HELPER_TOKEN_SPLIT = /[^A-Za-z0-9_-]+/;

/** **純粋入口**: 走査器が扱えない既知の入口を語彙の deny で探す。 */
export function unsupportedEntryPointsSource(
    source: string,
    file: string,
): readonly UnsupportedEntryPoint[] {
    const found: UnsupportedEntryPoint[] = [...extractUnits(source, file).entryPoints];

    const tokens = new Set(source.split(HELPER_TOKEN_SPLIT));
    for (const library of CLASS_HELPER_LIBRARIES) {
        if (tokens.has(library)) found.push({ file, kind: "class-helper-library" });
    }

    return found;
}

/* ===== var(--…) 参照の走査 ===== */

const VAR_NAME = /^--[A-Za-z0-9_-]+$/;
const IDENTIFIER_CHAR = /[A-Za-z0-9_-]/;
const AT_RULES_WITH_CONDITIONS = ["media", "supports", "container"] as const;

interface VarScanSink {
    push(name: string): void;
    diagnose(reason: CssVarDiagnosticReason, detail: string): void;
}

/**
 * CSS の値 (または at-rule の条件式) から `var()` 参照を取り出す。
 *
 * 受理契約 (括弧カウントだけの実装にしない):
 *   1. コメントは postcss が `Decl.value` から既に除いている (実測) ので `raws.value.raw` は使わない
 *   2. 値を左から 1 文字ずつ走査し、`'` / `"` で始まる区間はエスケープ (`\`) を尊重して読み飛ばす
 *   3. 閉じない引用は診断 `unterminated-string`
 *   4. 引用区間の**外**で **`var` の関数トークン**を見つけたら括弧の対応を数えて引数列を取る。
 *      関数トークンの境界 — `var` の直前の文字が識別子文字でも `\` でもなく、直後が `(`
 *   5. 引数列は**最初のトップレベルのカンマ**で「名前」と「fallback 全体」に分ける
 *   6. 名前は前後の空白を除いた**全体**が `^--[A-Za-z0-9_-]+$` に一致すること。
 *      一致しなければ診断 `unresolvable-var`
 *   7. fallback 全体は同じ規則で**再帰的に**走査する
 *   8. 閉じない括弧は診断 `unterminated-function`
 */
function collectVarReferences(value: string, sink: VarScanSink): void {
    let i = 0;
    while (i < value.length) {
        const ch = value[i];
        if (ch === "'" || ch === '"') {
            let j = i + 1;
            let closed = false;
            while (j < value.length) {
                if (value[j] === "\\") {
                    j += 2;
                    continue;
                }
                if (value[j] === ch) {
                    closed = true;
                    break;
                }
                j += 1;
            }
            if (!closed) {
                sink.diagnose("unterminated-string", value);

                return;
            }
            i = j + 1;
            continue;
        }
        if (
            value.startsWith("var", i) &&
            value[i + 3] === "(" &&
            !(i > 0 && (IDENTIFIER_CHAR.test(value[i - 1]) || value[i - 1] === "\\"))
        ) {
            let depth = 0;
            let j = i + 3;
            let end = -1;
            let quote: string | null = null;
            for (; j < value.length; j += 1) {
                const c = value[j];
                if (quote !== null) {
                    if (c === "\\") {
                        j += 1;
                        continue;
                    }
                    if (c === quote) quote = null;
                    continue;
                }
                if (c === "'" || c === '"') {
                    quote = c;
                    continue;
                }
                if (c === "(") depth += 1;
                else if (c === ")") {
                    depth -= 1;
                    if (depth === 0) {
                        end = j;
                        break;
                    }
                }
            }
            if (end < 0) {
                sink.diagnose("unterminated-function", value);

                return;
            }
            const args = value.slice(i + 4, end);
            // 最初のトップレベルのカンマで名前と fallback に分ける
            let comma = -1;
            let level = 0;
            let q: string | null = null;
            for (let k = 0; k < args.length; k += 1) {
                const c = args[k];
                if (q !== null) {
                    if (c === "\\") {
                        k += 1;
                        continue;
                    }
                    if (c === q) q = null;
                    continue;
                }
                if (c === "'" || c === '"') {
                    q = c;
                    continue;
                }
                if (c === "(") level += 1;
                else if (c === ")") level -= 1;
                else if (c === "," && level === 0) {
                    comma = k;
                    break;
                }
            }
            const name = (comma < 0 ? args : args.slice(0, comma)).trim();
            if (!VAR_NAME.test(name)) sink.diagnose("unresolvable-var", args);
            else sink.push(name);
            if (comma >= 0) collectVarReferences(args.slice(comma + 1), sink);
            i = end + 1;
            continue;
        }
        i += 1;
    }
}

function resolveVarName(name: string): TokenResolution {
    if (tokensDeclarationNames().has(name)) {
        return { kind: "color", channel: "other", suffix: name };
    }
    const contract = NON_TOKEN_WORD_CONTRACT.find(
        (entry) => entry.kind === "css-variable" && entry.name === name,
    );
    if (contract !== undefined) return { kind: "contract", word: name };

    return { kind: "unresolved", reason: "unknown-token" };
}

function tokensDeclarationNames(): ReadonlySet<string> {
    const names = new Set<string>();
    for (const suffix of cssColorTokens().keys()) names.add(`--color-${suffix}`);
    for (const suffix of cssRadiusTokens().keys()) names.add(`--radius-${suffix}`);
    names.add("--font-sans");

    return names;
}

/** **純粋入口**: 1 本のソースから `var(--…)` 参照を導出する。 */
export function scanCssVarReferencesSource(
    source: string,
    file: string,
): Pick<CssVarReferenceScan, "references" | "diagnostics"> {
    const references: CssVarReference[] = [];
    const diagnostics: CssVarReferenceDiagnostic[] = [];
    const sink: VarScanSink = {
        push: (name) => references.push({ file, name, resolution: resolveVarName(name) }),
        diagnose: (reason, detail) => diagnostics.push({ file, reason, detail }),
    };

    /** CSS ソースを postcss で読んで `var()` 参照を集める (`.css` と `<style>` が共有する)。 */
    const collectFromCss = (css: string): boolean => {
        let root;
        try {
            root = postcss.parse(css, { from: file });
        } catch (error) {
            sink.diagnose(
                "css-parse-failed",
                error instanceof Error ? error.message : String(error),
            );

            return false;
        }
        root.walkDecls((decl) => collectVarReferences(decl.value, sink));
        root.walkAtRules((rule) => {
            if (AT_RULES_WITH_CONDITIONS.some((name) => name === rule.name.toLowerCase())) {
                collectVarReferences(rule.params, sink);

                return;
            }
            if (rule.params.includes("var(")) {
                sink.diagnose("unsupported-at-rule-params", `@${rule.name} ${rule.params}`);
            }
        });

        return true;
    };

    if (file.endsWith(".css")) {
        if (!collectFromCss(source)) return { references: [], diagnostics };

        return { references, diagnostics };
    }

    const { units, styles, diagnostics: unitDiagnostics } = extractUnits(source, file);
    if (unitDiagnostics.length > 0) {
        // class 走査側の診断は class-usage.test.ts が消費するので、ここでは参照 0 件で返す。
        return { references: [], diagnostics: [] };
    }
    for (const unit of units) collectVarReferences(unit.text, sink);
    // `.svelte` の <style> は CSS なので**CSS と同じ経路**で読む
    // (歩かないと「resources/js の var 参照を閉包する」という主張と食い違う)。
    for (const style of styles) collectFromCss(style);

    return { references, diagnostics };
}

/* ===== 実リポジトリ用の薄いラッパー ===== */

const JS_SCAN_ROOT = "resources/js";
const CSS_VAR_SCAN_ROOTS = ["resources/js", "resources/css"] as const;

function listFiles(relativeRoot: string): readonly string[] {
    const root = path.join(REPO_ROOT, relativeRoot);
    if (!fs.existsSync(root)) throw new Error(`走査根 ${relativeRoot} が存在しない`);
    const found: string[] = [];
    const walk = (dir: string): void => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                walk(full);
                continue;
            }
            if (!entry.isFile()) continue;
            found.push(path.relative(REPO_ROOT, full).split(path.sep).join("/"));
        }
    };
    walk(root);

    return found.sort();
}

/** `resources/js` 直下の子の分類キー (直下のファイルは 1 枠にまとめる)。 */
export const JS_SCAN_DIRECT_FILES_KEY = "(直下のファイル)";

function directChildKey(relative: string): string {
    const rest = relative.slice(`${JS_SCAN_ROOT}/`.length);
    const slash = rest.indexOf("/");

    return slash < 0 ? JS_SCAN_DIRECT_FILES_KEY : rest.slice(0, slash);
}

/** 実リポジトリ (`resources/js`) を走査する。 */
export function scanClassUsage(): ClassUsageScan {
    const all = listFiles(JS_SCAN_ROOT);
    const occurrences: ClassTokenOccurrence[] = [];
    const pairs: ScannedPair[] = [];
    const diagnostics: ClassScanDiagnostic[] = [];
    const files: string[] = [];
    const perDirectory = new Map<string, number>();
    let backgroundOnly = 0;
    let foregroundOnly = 0;

    for (const relative of all) {
        const key = directChildKey(relative);
        if (!perDirectory.has(key)) perDirectory.set(key, 0);
        if (!classifyExtension(relative).scan) continue;
        files.push(relative);
        const scan = scanClassUsageSource(fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8"), relative);
        occurrences.push(...scan.occurrences);
        pairs.push(...scan.pairs);
        diagnostics.push(...scan.diagnostics);
        backgroundOnly += scan.incompleteOpaque.backgroundOnly;
        foregroundOnly += scan.incompleteOpaque.foregroundOnly;
        perDirectory.set(key, (perDirectory.get(key) ?? 0) + scan.occurrences.length);
    }

    return {
        occurrences,
        pairs,
        incompleteOpaque: { backgroundOnly, foregroundOnly },
        diagnostics,
        files,
        perDirectory,
    };
}

/** 実リポジトリ (`resources/js` / `resources/css`) の `var(--…)` 参照を走査する。 */
export function scanCssVarReferences(): CssVarReferenceScan {
    const references: CssVarReference[] = [];
    const diagnostics: CssVarReferenceDiagnostic[] = [];
    const files: string[] = [];
    const perRoot = new Map<string, number>();

    for (const root of CSS_VAR_SCAN_ROOTS) {
        const listed = listFiles(root).filter(
            (relative) => !relative.endsWith(".gitkeep") && !relative.endsWith(".d.ts"),
        );
        perRoot.set(root, listed.length);
        for (const relative of listed) {
            files.push(relative);
            const scan = scanCssVarReferencesSource(
                fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8"),
                relative,
            );
            references.push(...scan.references);
            diagnostics.push(...scan.diagnostics);
        }
    }

    return { references, diagnostics, files: files.sort(), perRoot };
}

/** 実リポジトリの「扱えない既知の入口」を走査する。 */
export function unsupportedEntryPoints(): readonly UnsupportedEntryPoint[] {
    const found: UnsupportedEntryPoint[] = [];
    for (const relative of listFiles(JS_SCAN_ROOT)) {
        if (!classifyExtension(relative).scan) continue;
        found.push(
            ...unsupportedEntryPointsSource(
                fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8"),
                relative,
            ),
        );
    }

    return found;
}

/** `UNDECIDABLE_REASONS` の値域 (再輸出。分類の全数性は gate が `never` で収束させる)。 */
export { UNDECIDABLE_REASONS };
