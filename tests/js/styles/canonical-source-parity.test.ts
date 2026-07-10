import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import {
    COLOR_TOKEN_MAP,
    DERIVED_COLOR_TOKENS,
    RADIUS_TOKENS,
    TYPOGRAPHY_RAMPS,
} from "./inventory";

/**
 * DESIGN.md (canonical) ⇔ resources/css/tokens.css (実装写像) の双方向同期を機械検証する。
 * 片方だけ更新された PR をここで落とす (docs/design-system.md の同期契約)。
 */

const ROOT = path.resolve(__dirname, "../../..");
const designMd = fs.readFileSync(path.join(ROOT, "DESIGN.md"), "utf-8");
const tokensCss = fs.readFileSync(path.join(ROOT, "resources/css/tokens.css"), "utf-8");

const frontmatter = (() => {
    const m = designMd.match(/^---\n([\s\S]*?)\n---/);
    if (!m) throw new Error("DESIGN.md frontmatter not found");
    return m[1];
})();

function designColors(): Map<string, string> {
    const section = frontmatter.match(/^colors:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md colors section not found");
    const map = new Map<string, string>();
    for (const line of section[1].matchAll(/^ {4}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
        map.set(line[1], line[2].toLowerCase());
    }
    return map;
}

function cssColorTokens(): Map<string, string> {
    const map = new Map<string, string>();
    for (const m of tokensCss.matchAll(/--color-([a-z-]+):\s*([^;]+);/g)) {
        map.set(m[1], m[2].replace(/\/\*.*?\*\//g, "").trim().toLowerCase());
    }
    return map;
}

describe("canonical source parity: colors", () => {
    it("DESIGN.md の色集合と tokens.css の --color-* が一致する (set equality)", () => {
        const design = designColors();
        const css = cssColorTokens();

        const expected = [
            ...Object.values(COLOR_TOKEN_MAP),
            ...DERIVED_COLOR_TOKENS,
        ].sort();
        expect([...css.keys()].sort()).toEqual(expected);
        expect([...design.keys()].sort()).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());
    });

    it("DESIGN.md と tokens.css の色の値が一致する (value parity)", () => {
        const design = designColors();
        const css = cssColorTokens();

        for (const [designKey, cssSuffix] of Object.entries(COLOR_TOKEN_MAP)) {
            expect(css.get(cssSuffix), `--color-${cssSuffix}`).toBe(design.get(designKey));
        }
    });
});

describe("canonical source parity: radius", () => {
    it("DESIGN.md rounded と tokens.css の --radius-* が一致する", () => {
        const section = frontmatter.match(/^rounded:\n((?: {4}\S[^\n]*\n)+)/m);
        expect(section).not.toBeNull();
        const design = new Map<string, string>();
        for (const m of section![1].matchAll(/^ {4}([a-z]+): (\d+px)$/gm)) {
            design.set(m[1], m[2]);
        }

        const css = new Map<string, string>();
        for (const m of tokensCss.matchAll(/--radius-([a-z]+):\s*([^;]+);/g)) {
            css.set(m[1], m[2].trim());
        }

        expect([...css.keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
        for (const key of RADIUS_TOKENS) {
            expect(css.get(key), `--radius-${key}`).toBe(design.get(key));
        }
    });
});

describe("canonical source parity: typography ramp", () => {
    function designRamp(name: string): Record<string, string> {
        const m = frontmatter.match(
            new RegExp(`^ {4}${name}:\\n((?: {8}\\S[^\\n]*\\n)+)`, "m"),
        );
        if (!m) throw new Error(`DESIGN.md typography ramp not found: ${name}`);
        const props: Record<string, string> = {};
        for (const line of m[1].matchAll(/^ {8}([a-zA-Z]+): "?([^"\n]+)"?$/gm)) {
            props[line[1]] = line[2];
        }
        return props;
    }

    function cssRamp(name: string): Record<string, string> {
        const m = tokensCss.match(new RegExp(`@utility text-${name} \\{([^}]+)\\}`));
        if (!m) throw new Error(`tokens.css @utility not found: text-${name}`);
        const props: Record<string, string> = {};
        for (const line of m[1].matchAll(/([a-z-]+):\s*([^;]+);/g)) {
            props[line[1]] = line[2].trim();
        }
        return props;
    }

    it.each([...TYPOGRAPHY_RAMPS])("text-%s の size/weight/line-height が DESIGN.md と一致する", (name) => {
        const design = designRamp(name);
        const css = cssRamp(name);

        expect(css["font-size"], "font-size").toBe(design["fontSize"]);
        expect(css["font-weight"], "font-weight").toBe(design["fontWeight"]);
        expect(css["line-height"], "line-height").toBe(design["lineHeight"]);
        if (design["letterSpacing"]) {
            expect(css["letter-spacing"], "letter-spacing").toBe(design["letterSpacing"]);
        }
    });

    it("ramp の font-weight は 400/500 のみ (DESIGN.md §Typography)", () => {
        for (const name of TYPOGRAPHY_RAMPS) {
            const css = cssRamp(name);
            expect(["400", "500"], `text-${name} font-weight`).toContain(css["font-weight"]);
        }
    });
});
