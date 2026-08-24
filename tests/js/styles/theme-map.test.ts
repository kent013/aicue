import { describe, expect, it } from "vitest";
import {
    cssColorTokens,
    cssRadiusTokens,
    cssRampUtilities,
    parseCssColor,
    parseThemeMap,
    requiredMapValue,
    resourceCssFiles,
    tokensCssThemeMap,
} from "./theme-map";

/*
 * theme-map の**パーサそのもの**の仕様を固定検体で固定する (正典 i18)。
 *
 * 実ファイルだけを相手にすると「解析が効いているから緑」なのか
 * 「解析が壊れていても緑」なのか区別できない。壊れた形・紛らわしい形を
 * 純粋入口 parseThemeMap(source, file) へ直接渡して両方向を固定する。
 */

const FIXTURE = "fixture.css";
const parse = (source: string) => parseThemeMap(source, FIXTURE);

describe("theme-map: @theme ブロックの検出 (負例)", () => {
    it("負例 1: @theme を 2 ブロック持つ検体は blocks が 2 件になる (呼び出し側が落とせる)", () => {
        const map = parse("@theme { --a: 1px; }\n@theme { --b: 2px; }");
        expect(map.blocks.length).toBe(2);
        expect(map.blocks.every((b) => b.topLevel)).toBe(true);
    });

    it("負例 2: @media の中の @theme は数えるが topLevel でなく、宣言も採らない", () => {
        const map = parse("@media (min-width: 1px) { @theme { --a: 1px; } }");
        expect(map.blocks.length).toBe(1);
        expect(map.blocks[0].topLevel).toBe(false);
        expect(map.declarations.size).toBe(0);
    });

    it("負例 3: コメントの中の @theme は数えない", () => {
        expect(parse("/* @theme { --color-x: red; } */").blocks.length).toBe(0);
    });

    it("負例 4: 同名変数の再宣言は例外", () => {
        expect(() => parse("@theme { --a: 1px; --a: 2px; }")).toThrow(/重複/);
    });

    it("負例 5: @theme の中の別の AtRule は例外", () => {
        expect(() => parse("@theme { @media screen { --a: 1px; } }")).toThrow(/直接の子/);
    });

    it("負例 6: 閉じないブロックは例外 (CssSyntaxError)", () => {
        expect(() => parse("@theme {")).toThrow();
    });

    it("負例 10b: @theme-extra / @utility-extra は名前が違うので数えない", () => {
        const map = parse("@theme-extra { --a: 1px; }\n@utility-extra text-x { color: red; }");
        expect(map.blocks.length).toBe(0);
        expect(map.rampUtilities.size).toBe(0);
    });

    it("負例 10c: 未終端のコメントは例外", () => {
        expect(() => parse("@theme { --a: 1px; }\n/* unterminated")).toThrow();
    });

    it("負例 10d: 未終端の文字列は例外", () => {
        expect(() => parse("@theme { --a: 'unterminated }")).toThrow();
    });

    it("負例 10e: 宣言値の中の @theme はブロックとして数えない", () => {
        expect(parse("--x: '@theme { }';").blocks.length).toBe(0);
    });

    it("負例 10f: @/* c */theme は例外 (At-rule without name)", () => {
        expect(() => parse("@/* c */theme { --a: 1px; }")).toThrow();
    });

    it("負例 10g: @theme の中の Rule は例外", () => {
        expect(() => parse("@theme { :root { color: red; } }")).toThrow(/直接の子/);
    });

    it("負例 10h: ブロックを持たない @theme; は例外", () => {
        expect(() => parse("@theme;")).toThrow(/ブロックを持たない/);
    });

    it("負例 10i: params つきの @theme foo { } は例外", () => {
        expect(() => parse("@theme foo { --a: 1px; }")).toThrow(/params/);
    });

    it("負例 10j: @utility の重複と規則外 params は例外", () => {
        expect(() =>
            parse("@utility text-x { font-size: 1px; }\n@utility text-x { font-size: 2px; }"),
        ).toThrow(/重複/);
        expect(() => parse("@utility bg-x { color: red; }")).toThrow(/params/);
    });
});

describe("theme-map: 文字列状態の裏取り (負例 11〜14)", () => {
    it("値の中のコメント風文字列は潰されない", () => {
        const map = parse("@theme { --x: '/* not a comment */'; }");
        expect(map.declarations.size).toBe(1);
        expect(requiredMapValue(map.declarations, "--x", "--x")).toBe("'/* not a comment */'");
    });

    it("値の中の波括弧でブロックの対応が壊れない", () => {
        const map = parse("@theme { --x: '{'; --y: '}'; --z: 1px; }");
        expect([...map.declarations.keys()]).toEqual(["--x", "--y", "--z"]);
    });

    it("エスケープした引用符で文字列がそこで閉じない", () => {
        const map = parse("@theme { --x: 'it\\'s'; --y: 1px; }");
        expect([...map.declarations.keys()]).toEqual(["--x", "--y"]);
    });

    it("現行 --font-sans と同形 (引用符つき family 8 個) が丸ごと 1 宣言として取れる", () => {
        const source =
            "@theme {\n" +
            "    --font-sans:  'Noto Sans JP', 'Hiragino Sans', 'Yu Gothic UI', 'Segoe UI',\n" +
            "                  ui-sans-serif, system-ui, sans-serif,\n" +
            "                  'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';\n" +
            "    --x: 1px;\n" +
            "}";
        const map = parse(source);
        expect([...map.declarations.keys()]).toEqual(["--font-sans", "--x"]);
        expect(requiredMapValue(map.declarations, "--font-sans", "--font-sans")).toContain(
            "Noto Color Emoji",
        );
    });
});

describe("theme-map: 正例", () => {
    it("正例 4: 文字列の中の { を誤認しない", () => {
        const map = parse('@theme { --f: "a{b"; --g: 2px; }');
        expect(map.declarations.size).toBe(2);
    });

    it("正例 5: Comment を無視して宣言と ramp を採る", () => {
        const map = parse(
            "@theme { /* 節見出し */ --a: 1px; }\n@utility text-x { /* c */ font-size: 1px; }",
        );
        expect([...map.declarations.keys()]).toEqual(["--a"]);
        expect(requiredMapValue(map.rampUtilities, "x", "text-x").get("font-size")).toBe("1px");
    });

    it("正例 1: 現行 tokens.css と同形の検体で色 / radius / ramp が取れる", () => {
        const map = parse(
            [
                "@theme {",
                "    --color-primary: #1d4ed8;",
                "    --color-primary-soft: rgba(29, 78, 216, 0.12);  /* soft */",
                "    --radius-sm: 4px;",
                "}",
                "@utility text-body {",
                "    font-size: 16px;",
                "    font-weight: 400;",
                "}",
            ].join("\n"),
        );
        expect(requiredMapValue(map.declarations, "--color-primary", "primary")).toBe("#1d4ed8");
        expect(requiredMapValue(map.declarations, "--radius-sm", "radius")).toBe("4px");
        expect(requiredMapValue(map.rampUtilities, "body", "text-body").get("font-weight")).toBe(
            "400",
        );
    });
});

describe("theme-map: parseCssColor", () => {
    it("負例 7: color-mix は例外 (扱えない色表現を読めたことにしない)", () => {
        expect(() => parseCssColor("color-mix(in oklab, red 10%, transparent)")).toThrow();
    });

    it("負例 8: RGB が範囲外は例外", () => {
        expect(() => parseCssColor("rgba(300, 0, 0, 0.1)")).toThrow();
    });

    it("負例 9: alpha が範囲外は例外", () => {
        expect(() => parseCssColor("rgba(29, 78, 216, 1.5)")).toThrow();
    });

    it("負例 10: 余分な末尾文字は例外", () => {
        expect(() => parseCssColor("#1d4ed8ff")).toThrow();
    });

    it("正例 2: rgba(...) を alpha 色として読む", () => {
        expect(parseCssColor("rgba(29, 78, 216, 0.12)")).toEqual({
            kind: "alpha",
            rgb: { r: 29, g: 78, b: 216 },
            alpha: 0.12,
        });
    });

    it("正例 3: #rrggbb を不透明色として読む", () => {
        expect(parseCssColor("#1d4ed8")).toEqual({
            kind: "opaque",
            rgb: { r: 29, g: 78, b: 216 },
        });
    });

    it("空白区切り + スラッシュ記法も読む", () => {
        expect(parseCssColor("rgb(29 78 216 / 0.5)")).toEqual({
            kind: "alpha",
            rgb: { r: 29, g: 78, b: 216 },
            alpha: 0.5,
        });
    });
});

describe("theme-map: 実ファイルの母集団が空でない", () => {
    it("tokens.css の宣言 / 色 / radius / ramp が 0 件でない", () => {
        expect(tokensCssThemeMap().declarations.size).toBeGreaterThan(0);
        expect(cssColorTokens().size).toBeGreaterThan(0);
        expect(cssRadiusTokens().size).toBeGreaterThan(0);
        expect(cssRampUtilities().size).toBeGreaterThan(0);
    });

    it("resources/ の *.css が 0 件でない (走査の空振り防止)", () => {
        expect(resourceCssFiles().length).toBeGreaterThan(0);
    });
});

describe("theme-map: requiredMapValue", () => {
    it("不在は例外にする (undefined を文字列補間で undefined に化けさせない)", () => {
        expect(() => requiredMapValue(new Map<string, string>(), "x", "ラベル")).toThrow(/ラベル/);
    });
});
