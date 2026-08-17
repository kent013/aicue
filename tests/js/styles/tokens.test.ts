import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
// AtRule は値としても使う (親を遡る経路は判別 union にならないので instanceof で見る)。
import postcss, { AtRule, type Container, type Document, type Root, type Rule } from "postcss";
import tailwindcss from "@tailwindcss/postcss";
import { REPO_ROOT, designColors, designRamp, designRounded } from "./design-md";
import {
    COLOR_TOKEN_MAP,
    COMPILED_VALUE_EXEMPT_TOKENS,
    CSS_COLOR_SUFFIXES,
    RADIUS_TOKENS,
    ROUTE_LAYER_ANCHOR_TOKENS,
    TYPOGRAPHY_RAMPS,
} from "./inventory";

/*
 * tokens.test — tokens.css の宣言が Tailwind のコンパイルを通って生成 CSS に出ることを検査する。
 *
 * 【他の検査との境界】
 *   canonical-source-parity : DESIGN.md ⇔ tokens.css の**テキスト**一致 (正本 ⇔ 宣言)
 *   本ファイル              : tokens.css ⇒ **Tailwind 生成 CSS** (宣言 ⇒ 生成物)
 *   contrast-invariant      : DESIGN.md の色の可読性
 *   検査範囲は一部重なる。トークンの値を変えれば parity と本ファイルの双方が赤になり得るが、
 *   Tailwind の解釈が壊れる形 (`@theme` が効かなくなる等) は本ファイルだけが検出する。
 *
 * 【2 層に分ける理由】
 *   経路の層 (実 app.css) : アプリの入口から tokens.css へ実際に繋がっていることを見る。
 *                            **アンカー集合であって全件ではない**
 *   密閉の層 (組み立て入力): `source(none)` で自動走査を止め、`@source inline` で候補を明示供給する。
 *                            アプリの class 使用状況に依存せず全件を見る
 *
 * 【走査範囲を絞る理由 (重要)】
 *   テーマ変数は `@layer theme` 直下の `:root, :host` に出る。生成 CSS 全体を無差別に走査すると、
 *   `@theme` が壊れて素の `:root` 宣言になった場合でも同じ変数が拾えてしまい**緑で通る**
 *   (実測で確認済み)。よって themeVariables() は @layer theme の :root, :host の
 *   **直接の子宣言**だけを集める。
 *
 * 【保証しないもの】
 *   - Vite のビルド・アセット配信・ブラウザでの適用 (生成 CSS より先は見ていない)
 *   - `@import` の**順序**を入れ替えたときの破綻。実測では順序を入れ替えても生成物は壊れない。
 *     順序はリポジトリ規約であり、その固定は下の「取り込みの規約」が行う
 *   - font-family は**先頭 family だけ**を突き合わせる。フォールバック列は見ていない
 *   - 共有パーサ (design-md.ts) の**値の誤解析**。キーの取りこぼしは canonical-source-parity の
 *     集合一致が検出するが、値を誤って読む形は本ファイルも parity も同じ誤りを見るので検出できない
 */

/* ===== 検査対象の utility 候補 (注入と検査を同じ 1 つの値から作る) ===== */

const UTILITY_CANDIDATES = {
    color: CSS_COLOR_SUFFIXES.flatMap((s) => [`bg-${s}`, `text-${s}`, `border-${s}`]),
    radius: RADIUS_TOKENS.map((r) => `rounded-${r}`),
    ramp: TYPOGRAPHY_RAMPS.map((r) => `text-${r}`),
    hover: CSS_COLOR_SUFFIXES.filter((s) => s.endsWith("-hover")).map((s) => `hover:bg-${s}`),
} as const;

/**
 * 密閉入力を組み立てる。
 *
 * `source(none)` は Tailwind の自動ソース走査を止めるだけなので、
 * **候補を @source inline で与えないと utility は 1 つも生成されない**。
 * 注入する集合と検査する集合は UTILITY_CANDIDATES という 1 つの値から作る (2 か所に書かない)。
 */
function sealedInput(): string {
    const candidates = Object.values(UTILITY_CANDIDATES).flat().join(" ");
    return [
        '@import "tailwindcss" source(none);',
        '@import "../../../resources/css/tokens.css";',
        `@source inline("${candidates}");`,
    ].join("\n");
}

/*
 * @tailwindcss/postcss は `from` に渡したパスを鍵に結果をキャッシュする。
 * 1 つの `from` に 1 つの入力を対応させる (同じ from で別の入力を流さない)。
 * 密閉入力の from は実在しないパスでよい (相対 @import の解決にだけ使われる)。
 */
const SEALED_FROM = path.join(REPO_ROOT, "tests/js/styles/__sealed-tokens-input.css");
const APP_CSS_PATH = path.join(REPO_ROOT, "resources/css/app.css");

/** postcss + Tailwind でコンパイルする。失敗は握り潰さずそのまま伝播させる。 */
async function compile(css: string, from: string): Promise<Root> {
    const result = await postcss([tailwindcss()]).process(css, { from, to: undefined });
    return result.root;
}

interface CollectedDeclarations {
    readonly values: ReadonlyMap<string, string>;
    /** 同名で値の違う宣言が複数あったもの。空であることをテストが確かめる。 */
    readonly conflicts: readonly string[];
    /**
     * 宣言に至るまでに通った条件つき at-rule を `<名前> <条件文>` の形で並べたもの。
     * **名前を捨てない** — `@media (hover: hover)` と `@supports (hover: hover)` は
     * 別物なので、条件文だけで照合すると後者を前者として許してしまう。
     */
    readonly conditions: readonly string[];
}

/**
 * `@layer` は「どの層に置くか」を表すだけで**適用の条件ではない**。
 * それ以外の at-rule (`@media` / `@supports` / `@container` …) は条件つきである。
 */
function isConditionalAtRule(name: string): boolean {
    return name.toLowerCase() !== "layer";
}

/**
 * container の**直接の子宣言**と、その下の at-rule (`@media` 等) 配下の宣言を集める。
 *
 * **子孫の Rule には降りない** — `&:focus` のような別セレクタの宣言を混ぜないため。
 * 同名プロパティで値が違えば競合として記録する (後勝ちで黙らせない)。
 * 通った条件つき at-rule の条件文は `conditions` に残す — 呼び出し側が
 * 「どの条件の下でだけ成り立つ宣言か」を検査できるようにするため
 * (成立しない条件の中にしか無い宣言を「生成されている」と数えないため)。
 */
function collectDeclarations(container: Container): CollectedDeclarations {
    const values = new Map<string, string>();
    const conflicts = new Set<string>();
    const conditions = new Set<string>();

    const visit = (node: Container): void => {
        for (const child of node.nodes ?? []) {
            if (child.type === "decl") {
                const value = child.value.trim();
                const previous = values.get(child.prop);
                if (previous !== undefined && previous !== value) conflicts.add(child.prop);
                values.set(child.prop, value);
                continue;
            }
            if (child.type === "atrule") {
                if (isConditionalAtRule(child.name)) {
                    conditions.add(`${child.name.toLowerCase()} ${child.params.trim()}`);
                }
                visit(child);
            }
            // child.type === "rule" は辿らない
        }
    };
    visit(container);

    return { values, conflicts: [...conflicts].sort(), conditions: [...conditions].sort() };
}

/**
 * **ルート直下の** `@layer theme` の中の `:root, :host` 規則の**直接の子**である
 * CSS 変数だけを集める。
 *
 * 「Tailwind がテーマとして解釈し、条件なしで適用される結果」だけを見るための絞り込みであり、
 * ここを緩めると `@theme` の破損が検出できなくなる (ファイル冒頭の「走査範囲を絞る理由」)。
 * 条件つき at-rule (`@media` / `@supports` 等) の中に入った `@layer theme` は**採らない** —
 * 生成 CSS に文字列としては現れても、条件が成立しなければ画面には効かないためである。
 * `@media` 等で入れ子になった `:root` も同じ理由で採らない。
 *
 * **selector は `:root` 単独・`:host` 単独も受理する** (`:root, :host` の完全一致は要求しない)。
 * 複合 selector の綴りは Tailwind v4 の出力形であって本アプリの不変条件ではないためで、
 * 守りたいのは「条件なしでテーマ変数が到達すること」の方である。
 */
function themeVariables(root: Root): CollectedDeclarations {
    const values = new Map<string, string>();
    const conflicts = new Set<string>();

    const themeLayers = (root.nodes ?? []).filter(
        (node): node is AtRule =>
            node.type === "atrule" &&
            node.name === "layer" &&
            node.params
                .split(",")
                .map((name) => name.trim())
                .includes("theme"),
    );

    for (const layer of themeLayers) {
        for (const node of layer.nodes ?? []) {
            // 直接の子の Rule だけを見る (@media 等で入れ子になった :root は採らない)
            if (node.type !== "rule") continue;
            const selectors = node.selector.split(",").map((sel) => sel.trim());
            if (!selectors.every((sel) => sel === ":root" || sel === ":host")) continue;

            for (const child of node.nodes ?? []) {
                if (child.type !== "decl" || !child.prop.startsWith("--")) continue;
                const value = child.value.trim().toLowerCase();
                const previous = values.get(child.prop);
                if (previous !== undefined && previous !== value) conflicts.add(child.prop);
                values.set(child.prop, value);
            }
        }
    }

    return { values, conflicts: [...conflicts].sort(), conditions: [] };
}

/** 祖先に条件つき at-rule (`@media` / `@supports` 等) があるか。 */
function hasConditionalAncestor(node: Rule): boolean {
    let current: Container | Document | undefined = node.parent;
    while (current !== undefined) {
        if (current instanceof AtRule && isConditionalAtRule(current.name)) return true;
        current = current.parent;
    }
    return false;
}

/**
 * selector が完全一致する規則を出現順に返す。
 *
 * **条件つき at-rule の中にある規則は数えない** — 成立しない `@supports` や
 * `@media` の中にしか無い規則を「生成されている」と数えると、
 * 画面に出ない utility で緑になってしまうためである
 * (`@layer` は層の指定であって条件ではないので通す)。
 */
function rulesWithSelector(root: Root, selector: string): readonly Rule[] {
    const found: Rule[] = [];
    root.walkRules((rule) => {
        if (rule.selector !== selector) return;
        if (hasConditionalAncestor(rule)) return;
        found.push(rule);
    });
    return found;
}

/**
 * 出現がちょうど 1 件であることを確かめて、その規則の**直接の**宣言を返す。
 * 0 件も重複もここで落ちる。子孫 (`&:hover` や `@media` の中) は含めない。
 */
function soleRule(root: Root, selector: string): ReadonlyMap<string, string> {
    const rules = rulesWithSelector(root, selector);
    expect(rules.length, `${selector} の規則が 1 件でない (実際 ${rules.length} 件)`).toBe(1);

    const decls = new Map<string, string>();
    for (const node of rules[0].nodes ?? []) {
        if (node.type !== "decl") continue;
        decls.set(node.prop, node.value.trim());
    }
    return decls;
}

/**
 * `.hover\:…` 規則の中の **`&:hover` 入れ子配下**の宣言を返す。
 *
 * 外側の規則も `&:hover` も**ちょうど 1 件**であることを確かめてから中を見る
 * (複数出現を後勝ちで黙らせない)。`&:focus` のような別セレクタの入れ子には降りない。
 * `&:hover` の下でさらに `@media (hover: hover)` に包まれる形は Tailwind の実装詳細だが、
 * **どんな条件でも通す**と `@media (hover: none)` のように**成立時に打ち消す条件**へ
 * 移されても緑になる。そこで宣言は拾いつつ、通った条件は `conditions` として返し、
 * 呼び出し側 (D) が許容する条件の一覧と突き合わせる。
 * 条件の綴りが Tailwind の版で変わったら赤になるが、それは
 * 「新しい出力形に合わせて読み方を直す」契機であって緩める理由にはしない
 * (ファイル冒頭のリスク欄と同じ方針)。
 */
function hoverDeclarations(root: Root, selector: string): CollectedDeclarations {
    const outer = rulesWithSelector(root, selector);
    expect(outer.length, `${selector} の規則が 1 件でない (実際 ${outer.length} 件)`).toBe(1);

    const hovers = (outer[0].nodes ?? []).filter(
        (node): node is Rule => node.type === "rule" && node.selector === "&:hover",
    );
    expect(hovers.length, `${selector} の &:hover が 1 件でない (実際 ${hovers.length} 件)`).toBe(1);

    return collectDeclarations(hovers[0]);
}

/** font-family 宣言の先頭 family を引用符抜き・小文字で取り出す。 */
function firstFamily(value: string): string {
    const head = value.split(",")[0].trim();
    return head.replace(/^['"]|['"]$/g, "").toLowerCase();
}

let sealed: Root;
let routed: Root;

beforeAll(async () => {
    sealed = await compile(sealedInput(), SEALED_FROM);
    routed = await compile(fs.readFileSync(APP_CSS_PATH, "utf-8"), APP_CSS_PATH);
}, 60_000);

/* ===== 空振り防止 ===== */

describe("tokens: 空振り防止", () => {
    it.each(Object.entries(UTILITY_CANDIDATES))(
        "utility 候補の区分 %s が 0 件でない",
        (_kind, list) => {
            // 注入と検査を 1 つの値から作るので、組み立てが壊れると両方が同時に空になり
            // 緑のまま通る。区分ごとに非空を確かめてそれを防ぐ。
            expect(list.length).toBeGreaterThan(0);
        },
    );

    it("密閉入力の生成 CSS がテーマ変数を持つ", () => {
        expect(themeVariables(sealed).values.size).toBeGreaterThan(0);
    });

    it("負のコントロール: 実在しない utility の規則は 0 件になる", () => {
        // rulesWithSelector が「何にでも一致して緑になる」実装でないことを確かめる
        expect(rulesWithSelector(sealed, ".bg-does-not-exist-token").length).toBe(0);
    });
});

/* ===== ヘルパの仕様固定 (fixture) =====
 *
 * 走査の絞り込みは本ファイルの検出力そのものである (絞りを外すと @theme の破損が
 * 検出できなくなる)。生成 CSS を相手にした検査だけだと「絞りが効いているから緑」なのか
 * 「絞りが無くても緑」なのか区別できないので、**壊れた形を含む小さな CSS** を
 * postcss で読ませて、ヘルパの仕様を恒久的に固定する。
 */

const HELPER_FIXTURE = `
@layer theme {
    :root, :host {
        --fixture-token: ok;
        --fixture-conflict: a;
        --fixture-conflict: b;
    }
    @media (min-width: 1px) {
        :root { --fixture-media: ng; }
    }
}
@supports (color: oklch(0 0 0)) {
    @layer theme {
        :root, :host { --fixture-conditional-layer: ng; }
    }
}
:root { --fixture-outside: ng; --fixture-token: ng; }
@layer utilities {
    .fixture-util {
        color: ok;
        .fixture-child { color: ng; }
    }
    @supports (color: oklch(0 0 0)) {
        .fixture-conditional-util { color: ng; }
    }
    .hover\\:fixture {
        &:hover { @media (hover: hover) { background-color: ok; } }
        &:focus { background-color: ng; }
    }
}
`;

describe("tokens: ヘルパの仕様固定 (fixture)", () => {
    const fixture = postcss.parse(HELPER_FIXTURE, { from: undefined });

    it("themeVariables はルート直下の @layer theme の :root/:host だけを見る", () => {
        const { values, conflicts } = themeVariables(fixture);

        expect(values.get("--fixture-token"), "テーマ層の値を採る").toBe("ok");
        expect(values.has("--fixture-outside"), "テーマ層の外は採らない").toBe(false);
        expect(values.has("--fixture-media"), "@media の入れ子は採らない").toBe(false);
        expect(
            values.has("--fixture-conditional-layer"),
            "条件つき at-rule の中のテーマ層は採らない",
        ).toBe(false);
        expect(conflicts, "同名で値の違う宣言は競合として出す").toEqual(["--fixture-conflict"]);
    });

    it("soleRule は規則の直接の宣言だけを返す", () => {
        expect(Object.fromEntries(soleRule(fixture, ".fixture-util"))).toEqual({ color: "ok" });
    });

    it("負のコントロール: 条件つき at-rule の中にしかない規則は数えない", () => {
        // 成立しない @supports / @media の中にしか無い utility を「生成されている」と
        // 数えると、画面に出ないものを緑で通してしまう。
        expect(rulesWithSelector(fixture, ".fixture-conditional-util").length).toBe(0);
    });

    it("hoverDeclarations は &:hover 配下だけを返し、&:focus を混ぜない", () => {
        const { values, conflicts, conditions } = hoverDeclarations(fixture, ".hover\\:fixture");
        expect(Object.fromEntries(values)).toEqual({ "background-color": "ok" });
        expect(conflicts).toEqual([]);
        expect(conditions, "通った条件つき at-rule を呼び出し側へ返す").toEqual([
            "media (hover: hover)",
        ]);
    });

    it("負のコントロール: 打ち消す条件の中の hover 宣言は条件として現れる", () => {
        // 条件を一切見ない実装だと「hover 時の背景色になる」が (hover: none) でも緑になる。
        const negated = postcss.parse(
            `.hover\\:negated { &:hover { @media (hover: none) { background-color: ng; } } }`,
            { from: undefined },
        );
        const { conditions } = hoverDeclarations(negated, ".hover\\:negated");
        expect(conditions).toEqual(["media (hover: none)"]);
        expect(ALLOWED_HOVER_CONDITIONS).not.toContain("media (hover: none)");
    });

    it("負のコントロール: 条件文が同じでも at-rule の種類が違えば別物として出る", () => {
        // 条件文だけで照合すると @supports (hover: hover) を @media (hover: hover) として
        // 許してしまう。名前を捨てないことをここで固定する。
        const supports = postcss.parse(
            `.hover\\:supports { &:hover { @supports (hover: hover) { background-color: ng; } } }`,
            { from: undefined },
        );
        const { conditions } = hoverDeclarations(supports, ".hover\\:supports");
        expect(conditions).toEqual(["supports (hover: hover)"]);
        expect(ALLOWED_HOVER_CONDITIONS).not.toContain("supports (hover: hover)");
    });
});

/* ===== A. テーマ変数 (密閉の層) ===== */

describe("tokens/A: @theme 由来の CSS 変数が生成 CSS に期待値で現れる", () => {
    it("同名変数の値が競合していない", () => {
        expect(themeVariables(sealed).conflicts).toEqual([]);
    });

    it.each(Object.entries(COLOR_TOKEN_MAP))(
        "DESIGN.md colors.%s の値が --color-%s に届く",
        (designKey, cssSuffix) => {
            const expected = designColors().get(designKey);
            expect(expected, `DESIGN.md colors に ${designKey} が無い`).toBeDefined();
            expect(themeVariables(sealed).values.get(`--color-${cssSuffix}`)).toBe(expected);
        },
    );

    it.each(Object.keys(COMPILED_VALUE_EXEMPT_TOKENS))(
        "派生トークン --color-%s は出現までを検査する (値は免除)",
        (suffix) => {
            // 値の突き合わせを免除する理由は inventory.ts の COMPILED_VALUE_EXEMPT_TOKENS にある。
            // 「見ていない」のは値だけで、出現そのものは見る。
            expect(themeVariables(sealed).values.has(`--color-${suffix}`)).toBe(true);
        },
    );

    it.each([...RADIUS_TOKENS])("DESIGN.md rounded.%s の値が対応する --radius-* に届く", (key) => {
        // ⚠ Tailwind 既定テーマにも --radius-sm/md/lg がある (0.25rem / 0.375rem / 0.5rem)。
        //    「存在するか」だけでは空振りするので、必ず値を突き合わせる。
        expect(themeVariables(sealed).values.get(`--radius-${key}`)).toBe(designRounded().get(key));
    });

    it("ramp の font-family が 1 つに揃っており、--font-sans の**先頭 family**と一致する", () => {
        // ⚠ --font-sans も Tailwind 既定テーマに存在する。ここも値で見る。
        //    フォールバック列は DESIGN.md 側が持っていないので突き合わせない (先頭 family だけ)。
        const families = new Set(TYPOGRAPHY_RAMPS.map((r) => designRamp(r)["fontFamily"]));
        expect(families.size, "DESIGN.md の ramp が複数の fontFamily を宣言している").toBe(1);

        const declared = [...families][0];
        const fontSans = themeVariables(sealed).values.get("--font-sans");
        expect(fontSans, "--font-sans が @layer theme に無い").toBeDefined();
        expect(firstFamily(fontSans ?? "")).toBe(firstFamily(declared));
    });
});

/* ===== B. typography ramp utility (密閉の層) ===== */

describe("tokens/B: ramp utility が DESIGN.md の値で生成される", () => {
    it.each([...TYPOGRAPHY_RAMPS])("text-%s の宣言が DESIGN.md と過不足なく一致する", (name) => {
        const design = designRamp(name);
        const decls = soleRule(sealed, `.text-${name}`);

        const expected = new Map<string, string>([
            ["font-family", "var(--font-sans)"],
            ["font-size", design["fontSize"]],
            ["font-weight", design["fontWeight"]],
            ["line-height", design["lineHeight"]],
        ]);
        // letterSpacing が DESIGN.md に**無い** ramp に letter-spacing を勝手に足すことも防ぐ
        // (キー集合の一致で見る)。
        if (design["letterSpacing"]) expected.set("letter-spacing", design["letterSpacing"]);

        expect(Object.fromEntries([...decls].sort())).toEqual(
            Object.fromEntries([...expected].sort()),
        );
    });
});

/* ===== C. 色 utility (密閉の層) ===== */

describe("tokens/C: 色 utility が var(--color-*) を参照して生成される", () => {
    it.each([...CSS_COLOR_SUFFIXES])("%s の bg / text / border が解決する", (suffix) => {
        const token = `var(--color-${suffix})`;
        expect(Object.fromEntries(soleRule(sealed, `.bg-${suffix}`))).toEqual({
            "background-color": token,
        });
        expect(Object.fromEntries(soleRule(sealed, `.text-${suffix}`))).toEqual({ color: token });
        expect(Object.fromEntries(soleRule(sealed, `.border-${suffix}`))).toEqual({
            "border-color": token,
        });
    });
});

/* ===== D. hover variant (密閉の層) ===== */

/**
 * `&:hover` の中で宣言を包んでよい条件つき at-rule の一覧 (`<名前> <条件文>`)。
 *
 * Tailwind v4 は hover 可能な入力機器に限る `@media (hover: hover)` で包む (実測)。
 * ここに無い条件が現れたら赤になる — 打ち消す条件 (`(hover: none)` 等) や
 * 別種の at-rule (`@supports (hover: hover)`) へ移された場合に緑で通さないための allowlist。
 */
const ALLOWED_HOVER_CONDITIONS: readonly string[] = ["media (hover: hover)"];

describe("tokens/D: hover variant が &:hover の中で解決する", () => {
    it.each([...UTILITY_CANDIDATES.hover])("%s が hover 時の背景色になる", (utility) => {
        const suffix = utility.replace("hover:bg-", "");
        const { values, conflicts, conditions } = hoverDeclarations(sealed, `.hover\\:bg-${suffix}`);
        expect(conflicts).toEqual([]);
        expect(values.get("background-color")).toBe(`var(--color-${suffix})`);
        for (const condition of conditions) {
            expect(ALLOWED_HOVER_CONDITIONS, `想定外の条件 ${condition}`).toContain(condition);
        }
    });
});

/* ===== E. radius utility (密閉の層) ===== */

describe("tokens/E: radius utility が var(--radius-*) を参照する", () => {
    it.each([...RADIUS_TOKENS])("rounded-%s が解決する", (key) => {
        // ⚠ この参照自体は Tailwind 既定テーマでも成立する (実測)。
        //    「aicue の値であること」を保証するのは A の値検査であって本テストではない。
        expect(Object.fromEntries(soleRule(sealed, `.rounded-${key}`))).toEqual({
            "border-radius": `var(--radius-${key})`,
        });
    });
});

/* ===== F. 経路の層 (実 app.css) ===== */

describe("tokens/F: 実 app.css のコンパイルで tokens.css が実際に効いている", () => {
    it("同名変数の値が競合していない", () => {
        // 誤った値のあとに正しい値が再宣言されると、後勝ちで正しい値だけが見えてしまう。
        // 密閉の層と同じく経路の層でも競合そのものを落とす。
        expect(themeVariables(routed).conflicts).toEqual([]);
    });

    it.each([...ROUTE_LAYER_ANCHOR_TOKENS])(
        "アンカー --color-%s が DESIGN.md の値で現れる",
        (suffix) => {
            // アンカー集合であって全件ではない (全件は密閉の層が見る)。
            // アンカーが使われなくなったら、テストを緩めず土台の別トークンへ差し替える。
            const designKey = Object.entries(COLOR_TOKEN_MAP).find(([, v]) => v === suffix)?.[0];
            expect(designKey, `COLOR_TOKEN_MAP に --color-${suffix} の対応が無い`).toBeDefined();
            expect(themeVariables(routed).values.get(`--color-${suffix}`)).toBe(
                designColors().get(designKey ?? ""),
            );
        },
    );

    it("主 CTA の塗り (.bg-primary) が生成される", () => {
        expect(Object.fromEntries(soleRule(routed, ".bg-primary"))).toEqual({
            "background-color": "var(--color-primary)",
        });
    });

    it("生成された自前トークンの値はすべて DESIGN.md と一致する", () => {
        // アンカー以外にも出ているトークンがあれば、ついでに値を確かめる (母集団は要求しない)。
        const vars = themeVariables(routed).values;
        for (const [designKey, cssSuffix] of Object.entries(COLOR_TOKEN_MAP)) {
            const actual = vars.get(`--color-${cssSuffix}`);
            if (actual === undefined) continue;
            expect(actual, `--color-${cssSuffix}`).toBe(designColors().get(designKey));
        }
    });
});

/* ===== G. 取り込みの規約 (AST でのテキスト検査) ===== */

describe("tokens/G: app.css の入口 2 行の規約", () => {
    it("最初の 2 つの at-rule が tailwindcss → ./tokens.css の @import である", () => {
        // これは**規約**の固定であって動作の不変条件ではない。
        // 実測では @import の順序を入れ替えても Tailwind v4 の生成物は壊れなかった。
        // 取り込みが失われる形の破綻は F (経路の層) が検出する。
        //
        // 行ベースでコメントを除くと複数行コメントを誤って解釈するので、
        // postcss で parse した AST の先頭ノードを見る。
        const appRoot = postcss.parse(fs.readFileSync(APP_CSS_PATH, "utf-8"), {
            from: APP_CSS_PATH,
        });
        const significant = appRoot.nodes.filter((node) => node.type !== "comment");

        expect(significant.length).toBeGreaterThanOrEqual(2);
        const [first, second] = significant;
        expect(first.type).toBe("atrule");
        expect(second.type).toBe("atrule");
        if (first.type !== "atrule" || second.type !== "atrule") return;

        expect(first.name).toBe("import");
        expect(first.params).toMatch(/^["']tailwindcss["']$/);
        expect(second.name).toBe("import");
        expect(second.params).toMatch(/^["']\.\/tokens\.css["']$/);
    });
});
