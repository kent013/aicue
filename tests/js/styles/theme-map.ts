/**
 * 実装写像 (resources/css/tokens.css) の読み出し — 検査テスト共有。
 *
 * ★正典 i21: 正本と写像の読み出しは**それぞれ 1 実装へ集約する**。
 *   同じ関心の解析が 2 本あると弱い方が緑を作る (「片方だけが読める写像」が成立する)。
 *   正本 (DESIGN.md) 側は design-md.ts が担当する。本ファイルは写像側だけを担当する。
 *
 * 【走査対象】呼び出し側が渡した CSS ソース文字列。実ファイルを読むのは薄いラッパーだけである。
 * 【解析の方式】**postcss で構文木にしてから読む**。自前の字句走査は書かない。
 *   `postcss` は既に devDependency で、`tokens.test.ts` が生成 CSS の解析に使っている
 *   (同じ解析器を写像側にも使う = 思考原則 1「フレームワークのレンジ内でやる」)。
 *   手書きの字句走査で解こうとしていた次の 4 つは、すべて解析器の側で解決する —
 *     (a) 文字列リテラルの中の `/*` `{` `}` の誤認 (`--font-sans` は引用符つきの値を 8 個持つ)、
 *     (b) at-keyword の境界 (`@theme-extra` は別の `name` になる)、
 *     (c) 宣言値の中の `@theme` (`Decl` の値であって `AtRule` にならない)、
 *     (d) 未終端のコメント・文字列・閉じないブロック (`CssSyntaxError` が飛ぶ = fail-closed)。
 *   受理する形は**実測して一意に決めた** (postcss 8.5 で確認)。
 *   読み方は 6 条 (外れたものはすべて**例外** = 正典 i20):
 *     1. `@theme` は `AtRule` かつ `name === "theme"` の**完全一致**で、
 *        **`params === ""`** かつ **`nodes !== undefined`** (ブロックを持つ) であること
 *     2. `topLevel` は `parent` が `Root` であること
 *     3. 宣言は**トップレベル `@theme` の直接の子 `Decl`** だけを採る。
 *        許容する直接子は **`Decl` と `Comment` の 2 種**で、**`Comment` は無視する**
 *        (tokens.css は `@theme` の中に節見出しコメントを持つので拒否すると実装できない)。
 *        `Rule` / 別の `AtRule` / その他のノードがあれば**例外**
 *     4. 同名宣言が 2 件以上あれば**例外** (postcss は後勝ちにせず `Decl` を 2 件返す)
 *     5. `@utility` は**ルート直下**・`params` が `^text-[a-z0-9-]+$`・`nodes !== undefined`・
 *        直接の子が `Decl` と `Comment` だけ (Comment は無視)・同じ `params` の重複が無いこと
 *     6. 構文エラー (未終端コメント / 未終端文字列 / 閉じないブロック) は postcss の例外を伝播させる
 * 【保証しないもの】
 *   - Tailwind の解釈 (宣言が生成 CSS に出るか) は見ない。それは tokens.test.ts の担当
 *   - postcss の AST 形状に依存する。postcss の major 更新で形が変われば
 *     固定検体 (theme-map.test.ts) が最初に落ちる (無言で緑にはならない)
 *   - 値の意味 (色空間・単位) は見ない。色だけは parseCssColor が明示的に扱う
 *   - `resourceCssFiles()` が見るのは `resources/` 配下だけである。
 *     その外に置いた CSS は見ない (アプリの CSS はすべて `resources/css` にあり、
 *     `vite.config.ts` の入口も `resources/css/app.css` である)
 */
import fs from "node:fs";
import path from "node:path";
import postcss from "postcss";
import { REPO_ROOT } from "./design-md";

/**
 * `@theme` ブロック 1 つ分。
 *
 * ★位置 (offset) は**持たない** — どこからも使わない出力を作らない
 *   (共通規約 (d)「集めた走査結果を判定に使わない形を作らない」)。
 */
export interface ThemeBlock {
    /** ルート直下の `@theme` か (条件つき at-rule の内側なら false) */
    readonly topLevel: boolean;
}

/** 1 本のソースを解析した結果。 */
export interface ThemeMap {
    /** 見つかった `@theme` ブロック全件 (0 件・2 件以上も呼び出し側が判定できるよう返す) */
    readonly blocks: readonly ThemeBlock[];
    /** ルート直下の `@theme` 直下の CSS 変数宣言 `{ 変数名 → 値 }` */
    readonly declarations: ReadonlyMap<string, string>;
    /** `@utility text-<name>` の宣言 `{ name → { プロパティ → 値 } }` */
    readonly rampUtilities: ReadonlyMap<string, ReadonlyMap<string, string>>;
}

const THEME_AT_RULE = "theme";
const UTILITY_AT_RULE = "utility";
const RAMP_UTILITY_PARAMS = /^text-[a-z0-9-]+$/;

/**
 * ★**唯一の解析実装**。実ファイル用の関数はすべてこの薄いラッパーである
 *   (固定検体を解析する入口が公開 API に無いと theme-map.test.ts が任意入力を検査できず、
 *   正典 i18 の裏取りにならない)。
 * `file` は例外メッセージに載せる識別子であって、ファイルを読むためのものではない。
 */
export function parseThemeMap(source: string, file: string): ThemeMap {
    const root = postcss.parse(source, { from: file });
    const blocks: ThemeBlock[] = [];
    const declarations = new Map<string, string>();
    const rampUtilities = new Map<string, ReadonlyMap<string, string>>();

    root.walkAtRules((rule) => {
        if (rule.name === THEME_AT_RULE) {
            if (rule.params !== "") {
                throw new Error(`${file}: @theme に params がある (${JSON.stringify(rule.params)})`);
            }
            if (rule.nodes === undefined) {
                throw new Error(`${file}: @theme がブロックを持たない`);
            }
            const topLevel = rule.parent?.type === "root";
            blocks.push({ topLevel });
            if (!topLevel) return;

            for (const child of rule.nodes) {
                if (child.type === "comment") continue;
                if (child.type !== "decl") {
                    throw new Error(`${file}: @theme の直接の子に ${child.type} がある`);
                }
                if (declarations.has(child.prop)) {
                    throw new Error(`${file}: @theme の宣言 ${child.prop} が重複している`);
                }
                declarations.set(child.prop, child.value.trim());
            }

            return;
        }

        if (rule.name !== UTILITY_AT_RULE) return;

        if (rule.parent?.type !== "root") {
            throw new Error(`${file}: @utility がルート直下にない`);
        }
        if (!RAMP_UTILITY_PARAMS.test(rule.params)) {
            throw new Error(`${file}: @utility の params が規則外 (${JSON.stringify(rule.params)})`);
        }
        if (rule.nodes === undefined) {
            throw new Error(`${file}: @utility ${rule.params} がブロックを持たない`);
        }
        const name = rule.params.slice("text-".length);
        if (rampUtilities.has(name)) {
            throw new Error(`${file}: @utility ${rule.params} が重複している`);
        }
        const props = new Map<string, string>();
        for (const child of rule.nodes) {
            if (child.type === "comment") continue;
            if (child.type !== "decl") {
                throw new Error(`${file}: @utility ${rule.params} の直接の子に ${child.type} がある`);
            }
            if (props.has(child.prop)) {
                throw new Error(`${file}: @utility ${rule.params} の宣言 ${child.prop} が重複している`);
            }
            props.set(child.prop, child.value.trim());
        }
        rampUtilities.set(name, props);
    });

    return { blocks, declarations, rampUtilities };
}

const TOKENS_CSS_RELATIVE = "resources/css/tokens.css";

/** `resources/css/tokens.css` を読んで `parseThemeMap` に渡す薄いラッパー。 */
export function tokensCssThemeMap(): ThemeMap {
    return parseThemeMap(readResourceCss(TOKENS_CSS_RELATIVE), TOKENS_CSS_RELATIVE);
}

/** `resources/` 配下の CSS をリポジトリ相対パスで読む (走査根の外は読まない)。 */
export function readResourceCss(relative: string): string {
    return fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8");
}

/**
 * `resources/` 配下の `*.css` をリポジトリ相対パスで全件返す (ソート済み)。
 *
 * `git ls-files` を使わないのは、テスト実行で子プロセスを起こさないためである。
 * 走査根 `resources/` が存在しなければ **fail-fast** で落とす。
 */
export function resourceCssFiles(): readonly string[] {
    const root = path.join(REPO_ROOT, "resources");
    if (!fs.existsSync(root)) throw new Error("走査根 resources/ が存在しない");

    const found: string[] = [];
    const walk = (dir: string): void => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                walk(full);
                continue;
            }
            if (entry.isFile() && entry.name.endsWith(".css")) {
                found.push(path.relative(REPO_ROOT, full).split(path.sep).join("/"));
            }
        }
    };
    walk(root);

    return found.sort();
}

/** `--color-<suffix>` だけを suffix で引ける形にしたもの (小文字化)。 */
export function cssColorTokens(): ReadonlyMap<string, string> {
    const map = new Map<string, string>();
    for (const [name, value] of tokensCssThemeMap().declarations) {
        if (!name.startsWith("--color-")) continue;
        map.set(name.slice("--color-".length), value.toLowerCase());
    }

    return map;
}

/** `--radius-<suffix>` だけを suffix で引ける形にしたもの。 */
export function cssRadiusTokens(): ReadonlyMap<string, string> {
    const map = new Map<string, string>();
    for (const [name, value] of tokensCssThemeMap().declarations) {
        if (!name.startsWith("--radius-")) continue;
        map.set(name.slice("--radius-".length), value);
    }

    return map;
}

/** `@utility text-<name>` の宣言 (`tokensCssThemeMap().rampUtilities` の別名)。 */
export function cssRampUtilities(): ReadonlyMap<string, ReadonlyMap<string, string>> {
    return tokensCssThemeMap().rampUtilities;
}

/**
 * `Map#get` の `undefined` を文字列補間で `"undefined"` に化けさせないための共有ヘルパ。
 * 不在は**例外**にする (正典 i20: 解析の失敗を pass に変えない)。
 */
export function requiredMapValue<K, V>(map: ReadonlyMap<K, V>, key: K, label: string): V {
    const value = map.get(key);
    if (value === undefined) throw new Error(`${label} が見つからない`);

    return value;
}

/** 色の正規化形 (S5 の合成と S10 の派生検査が共有する)。 */
export type ParsedColor =
    | { readonly kind: "opaque"; readonly rgb: Rgb }
    | { readonly kind: "alpha"; readonly rgb: Rgb; readonly alpha: number };

export interface Rgb {
    readonly r: number;
    readonly g: number;
    readonly b: number;
}

const HEX_COLOR = /^#([0-9A-Fa-f]{6})$/;
const RGB_FUNCTION = /^rgba?\(([^()]*)\)$/;
const INTEGER = /^\d{1,3}$/;
const NUMBER = /^(?:\d+(?:\.\d+)?|\.\d+)$/;

function channelOf(text: string, value: string): number {
    const trimmed = text.trim();
    if (!INTEGER.test(trimmed)) throw new Error(`色の RGB が 0..255 の整数でない: ${value}`);
    const n = Number(trimmed);
    if (n > 255) throw new Error(`色の RGB が 0..255 の整数でない: ${value}`);

    return n;
}

function alphaOf(text: string, value: string): number {
    const trimmed = text.trim();
    if (!NUMBER.test(trimmed)) throw new Error(`色の alpha が数値でない: ${value}`);
    const n = Number(trimmed);
    if (n > 1) throw new Error(`色の alpha が 0..1 でない: ${value}`);

    return n;
}

/**
 * 色の値を厳密に解析する (派生 token の値の検査と、合成の入力に使う)。
 *
 * 【受理する形】`#rrggbb` (大小文字どちらも) / `rgba(r, g, b, a)` / `rgb(r g b / a)`。
 *   `#rrggbb` は必須である — 正本 (`designColors()`) が返すのは hex で、
 *   S10 の「派生 token は正本の primary の RGB を alpha 0.12 にしたもの」の検査が
 *   正本側の hex を本関数へ渡す。
 * 【厳密に拒否する】RGB が 0..255 の整数でない / alpha が 0..1 でない /
 *   余分な末尾文字がある / 数値にならない / 上記以外の関数記法 (`color-mix(…)` 等)。
 *   いずれも**例外**にする (正典 i20: 読めるものだけ拾う形にしない)。
 */
export function parseCssColor(value: string): ParsedColor {
    const trimmed = value.trim();

    const hex = HEX_COLOR.exec(trimmed);
    if (hex !== null) {
        return {
            kind: "opaque",
            rgb: {
                r: parseInt(hex[1].slice(0, 2), 16),
                g: parseInt(hex[1].slice(2, 4), 16),
                b: parseInt(hex[1].slice(4, 6), 16),
            },
        };
    }

    const fn = RGB_FUNCTION.exec(trimmed);
    if (fn === null) throw new Error(`扱えない色表現: ${value}`);
    const args = fn[1];

    if (args.includes("/")) {
        const [head, tail, ...rest] = args.split("/");
        if (rest.length > 0) throw new Error(`扱えない色表現: ${value}`);
        const parts = head.trim().split(/\s+/);
        if (parts.length !== 3) throw new Error(`扱えない色表現: ${value}`);

        return {
            kind: "alpha",
            rgb: {
                r: channelOf(parts[0], value),
                g: channelOf(parts[1], value),
                b: channelOf(parts[2], value),
            },
            alpha: alphaOf(tail, value),
        };
    }

    const parts = args.split(",");
    if (parts.length === 3) {
        return {
            kind: "opaque",
            rgb: {
                r: channelOf(parts[0], value),
                g: channelOf(parts[1], value),
                b: channelOf(parts[2], value),
            },
        };
    }
    if (parts.length === 4) {
        return {
            kind: "alpha",
            rgb: {
                r: channelOf(parts[0], value),
                g: channelOf(parts[1], value),
                b: channelOf(parts[2], value),
            },
            alpha: alphaOf(parts[3], value),
        };
    }

    throw new Error(`扱えない色表現: ${value}`);
}
