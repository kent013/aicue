import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import {
    COLOR_TOKEN_MAP,
    COMPILED_VALUE_EXEMPT_TOKENS,
    DERIVED_COLOR_TOKENS,
    FRONTMATTER_SECTION_OWNERS,
    RADIUS_TOKENS,
    TYPOGRAPHY_RAMPS,
} from "./inventory";
// DESIGN.md 側のパーサは contrast-invariant と共有する (二重実装しない)。
import {
    REPO_ROOT,
    designColors,
    designFrontmatterSections,
    designRamp,
    designRounded,
    designTypographyNames,
} from "./design-md";

/**
 * DESIGN.md (canonical) ⇔ resources/css/tokens.css (実装写像) の双方向同期を機械検証する。
 * 片方だけ更新された PR をここで落とす (docs/design-system.md の同期契約)。
 */

const tokensCss = fs.readFileSync(path.join(REPO_ROOT, "resources/css/tokens.css"), "utf-8");

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
        // section 不在は designRounded() が例外で落とす (旧 expect(section).not.toBeNull() 相当)
        const design = designRounded();

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

/**
 * 検査の**母集団**が DESIGN.md / tokens.css と集合一致していることを固定する。
 *
 * これが無いと「DESIGN.md に ramp や角丸を足したのに検査側の固定配列に入らず、
 * 誰も見ないまま通る」形が起きる (色だけは既存の set equality が守っていた)。
 */
describe("canonical source parity: 検査の母集団", () => {
    it("DESIGN.md typography の子キーと TYPOGRAPHY_RAMPS が集合一致する", () => {
        const names = designTypographyNames();
        expect(names.length, "ramp 名が 0 件 (抽出の空振り)").toBeGreaterThan(0);
        expect([...names].sort()).toEqual([...TYPOGRAPHY_RAMPS].sort());
    });

    it("tokens.css の @utility text-* と TYPOGRAPHY_RAMPS が集合一致する", () => {
        const utilities = [...tokensCss.matchAll(/@utility\s+text-([a-z0-9-]+)\s*\{/g)].map(
            (m) => m[1],
        );
        expect(utilities.length, "@utility が 0 件 (抽出の空振り)").toBeGreaterThan(0);
        expect([...utilities].sort()).toEqual([...TYPOGRAPHY_RAMPS].sort());
    });

    it("DESIGN.md rounded のキーと RADIUS_TOKENS が集合一致する", () => {
        expect([...designRounded().keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
    });

    it("値検査を免除する派生色と DERIVED_COLOR_TOKENS が集合一致する", () => {
        // 契約: 派生色は全件が値免除である (DESIGN.md に期待値が無いため)。
        // 派生色を足したのに「値も見ていない・免除にも入っていない」状態を作れないようにする。
        expect(Object.keys(COMPILED_VALUE_EXEMPT_TOKENS).sort()).toEqual(
            [...DERIVED_COLOR_TOKENS].sort(),
        );
    });

    it("免除の理由が書かれている", () => {
        for (const [token, reason] of Object.entries(COMPILED_VALUE_EXEMPT_TOKENS)) {
            expect(reason.length, `${token}: 理由`).toBeGreaterThan(30);
        }
    });
});

/**
 * DESIGN.md frontmatter の節が、どの検査の担当かを既定拒否で固定する。
 *
 * 正本に節を足したのに誰も見ていない、という状態を作れないようにするための宣言。
 * 未検査の節は kind: "pending" として理由・解消条件・追跡先つきで登録する
 * (「検査があるから守られている」という誤読を防ぐ明示宣言であって免罪符ではない)。
 *
 * **kind: "checked" は「担当がいる」ことだけを表す**。節の中身の網羅は上の
 * 「検査の母集団」describe が別に固定している。
 */
describe("canonical source parity: frontmatter の節の担当宣言", () => {
    const sections = designFrontmatterSections();

    it("節が 0 件でない (抽出の空振り防止)", () => {
        expect(sections.length).toBeGreaterThan(0);
    });

    it("宣言と frontmatter の節が集合一致する (既定拒否)", () => {
        expect([...sections].sort(), "未宣言の節、または実在しない節の宣言がある").toEqual(
            Object.keys(FRONTMATTER_SECTION_OWNERS).sort(),
        );
    });

    it("metadata 宣言は理由を持ち、checked 宣言は担当 gate を 1 つ以上持つ", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind === "metadata") {
                expect(owner.reason.length, `${section}: reason`).toBeGreaterThan(0);
            }
            if (owner.kind === "checked") {
                expect(owner.by.length, `${section}: by`).toBeGreaterThan(0);
            }
        }
    });

    it("pending 宣言は理由・解消条件・追跡先をすべて埋めている", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "pending") continue;
            expect(owner.reason.length, `${section}: reason`).toBeGreaterThan(30);
            expect(owner.exit.length, `${section}: exit`).toBeGreaterThan(30);
            expect(owner.tracking.length, `${section}: tracking`).toBeGreaterThan(0);
        }
    });

    it("pending の追跡先が実在する (書式だけ整った死んだ参照を作らせない)", () => {
        // TODO の ID は**表の ID 列**から取る。散文に現れた文字列や、
        // T1234 に含まれる T123 のような部分一致で通らないようにする。
        const todoIds = new Set(
            ["docs/TODO.md", "docs/TODO-closed.md"]
                .map((rel) => fs.readFileSync(path.join(REPO_ROOT, rel), "utf-8"))
                .join("\n")
                .split(/\r?\n/)
                .flatMap((line) => line.match(/^\|\s*(T\d{3,})\s*\|/)?.[1] ?? []),
        );
        expect(todoIds.size, "TODO の ID が 1 件も取れない (抽出の空振り)").toBeGreaterThan(0);

        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "pending") continue;
            const { tracking } = owner;

            if (/^T\d{3,}$/.test(tracking)) {
                expect(todoIds.has(tracking), `${section}: ${tracking} が TODO の表に無い`).toBe(
                    true,
                );
                continue;
            }
            expect(tracking, `${section}: 追跡先の書式`).toMatch(/^devnotes\/[\w.-]+\/$/);
            expect(
                fs.existsSync(path.join(REPO_ROOT, tracking)),
                `${section}: ${tracking} が実在しない`,
            ).toBe(true);
        }
    });
});
