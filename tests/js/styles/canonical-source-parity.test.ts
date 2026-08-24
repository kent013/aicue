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
// 写像 (tokens.css) 側のパーサは 1 実装へ集約する (正典 i21)。ローカルの抽出は持たない。
import {
    cssColorTokens,
    cssRadiusTokens,
    cssRampUtilities,
    parseThemeMap,
    readResourceCss,
    requiredMapValue,
    parseCssColor,
    resourceCssFiles,
    tokensCssThemeMap,
} from "./theme-map";

/**
 * DESIGN.md (canonical) ⇔ resources/css/tokens.css (実装写像) の双方向同期を機械検証する。
 * 片方だけ更新された PR をここで落とす (docs/design-system.md の同期契約)。
 */

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

        const css = cssRadiusTokens();

        expect([...css.keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
        for (const key of RADIUS_TOKENS) {
            expect(css.get(key), `--radius-${key}`).toBe(design.get(key));
        }
    });
});

describe("canonical source parity: typography ramp", () => {
    function cssRamp(name: string): ReadonlyMap<string, string> {
        return requiredMapValue(cssRampUtilities(), name, `tokens.css @utility text-${name}`);
    }

    it.each([...TYPOGRAPHY_RAMPS])("text-%s の size/weight/line-height が DESIGN.md と一致する", (name) => {
        const design = designRamp(name);
        const css = cssRamp(name);

        expect(css.get("font-size"), "font-size").toBe(design["fontSize"]);
        expect(css.get("font-weight"), "font-weight").toBe(design["fontWeight"]);
        expect(css.get("line-height"), "line-height").toBe(design["lineHeight"]);
        if (design["letterSpacing"]) {
            expect(css.get("letter-spacing"), "letter-spacing").toBe(design["letterSpacing"]);
        }
    });

    it("ramp の font-weight は 400/500 のみ (DESIGN.md §Typography)", () => {
        for (const name of TYPOGRAPHY_RAMPS) {
            const css = cssRamp(name);
            expect(["400", "500"], `text-${name} font-weight`).toContain(css.get("font-weight"));
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
        const utilities = [...cssRampUtilities().keys()];
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

/**
 * 写像 (tokens.css) の**形**そのものを固定する。
 *
 * 値の一致 (上の describe) は「見ている宣言が正しい値か」しか見ない。
 * 見ていない場所に 2 つ目の `@theme` を置くと、どの検査も見ない token 空間が育つ
 * (正典 i2 前半)。ブロックの一意性はここで固定する。
 */
describe("canonical source parity: 写像の形", () => {
    it("@theme ブロックがリポジトリに 1 つだけある (2 つ目の宣言が検査を素通りする経路を塞ぐ)", () => {
        // 走査は resources/ 配下の *.css 全数。tokens.css の外に @theme を置くと
        // canonical-source-parity / tokens の両方が見ない token 空間が育つ。
        const cssFiles = resourceCssFiles();
        expect(cssFiles.length, "*.css が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);

        // 判定は parseThemeMap の結果で行う (コメントの中の @theme を数えない)。
        const withTheme = cssFiles.filter(
            (rel) => parseThemeMap(readResourceCss(rel), rel).blocks.length > 0,
        );
        expect(withTheme).toEqual(["resources/css/tokens.css"]);
        expect(tokensCssThemeMap().blocks.length, "tokens.css の @theme が 1 ブロックでない").toBe(
            1,
        );
        expect(tokensCssThemeMap().blocks[0].topLevel, "@theme がルート直下でない").toBe(true);
    });

    it("COLOR_TOKEN_MAP の逆写像が一意である (suffix → DESIGN キーが後勝ちにならない)", () => {
        // 走査器は suffix 空間を返し、gate は逆写像で DESIGN キー空間へ写す。
        // 値に重複があると逆引きが後勝ちになり、別のトークンの値で検査してしまう。
        const suffixes = Object.values(COLOR_TOKEN_MAP);
        expect(suffixes.length, "COLOR_TOKEN_MAP が空 (走査の空振り)").toBeGreaterThan(0);
        expect(new Set(suffixes).size).toBe(suffixes.length);
    });

    it("tokens.css の色宣言が parseCssColor で全件読める (読めない値を素通りさせない)", () => {
        const colors = cssColorTokens();
        expect(colors.size, "色トークンが 0 件 (走査の空振り)").toBeGreaterThan(0);
        for (const [suffix, value] of colors) {
            expect(() => parseCssColor(value), `--color-${suffix}: ${value}`).not.toThrow();
        }
    });
});
