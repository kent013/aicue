import { describe, expect, it } from "vitest";
import {
    isScannedFileName,
    isWatchedCandidate,
    scanClassUsage,
    scanClassUsageSource,
    unsupportedEntryPoints,
    unsupportedEntryPointsSource,
    type ScannedPair,
    type UndecidableReason,
} from "./class-usage";
import { JS_SCAN_CHILD_CLASSIFICATION, UNDECIDABLE_REASONS } from "./inventory";
import { TONE_CLASSES } from "../../../resources/js/components/atoms/Badge.types";
import { VARIANT_CLASSES } from "../../../resources/js/components/atoms/Button.types";

/*
 * class 走査器そのものの仕様を固定検体で固定する (正典 i18)。
 *
 * 実リポジトリだけを相手にすると「分解が効いているから緑」なのか
 * 「分解が壊れていても緑」なのか区別できない。純粋入口
 * scanClassUsageSource(source, file) / unsupportedEntryPointsSource(source, file) へ
 * 直接渡して両方向 (検出する / 誤検出しない) を固定する。
 *
 * **本 gate が class 走査の診断の消費先である** — S3 (参照の閉包) / S5 (合成) / S7 (逆向き被覆) は
 * 「実リポジトリ走査の診断が 0 件」という保証に依存する。
 */

const TS = "fixture.ts";
const SVELTE = "fixture.svelte";

/** `.ts` の 1 行検体を作る (文字列リテラル 1 つを持つだけのソース)。 */
const tsUnit = (literal: string): string => `export const a = ${literal};\n`;

const scanTs = (literal: string) => scanClassUsageSource(tsUnit(literal), TS);

const pairsOf = (literal: string): readonly ScannedPair[] => scanTs(literal).pairs;

const opaquePairs = (literal: string): readonly string[] =>
    pairsOf(literal)
        .flatMap((p) => (p.kind === "opaque" ? [`${p.fg} on ${p.bg}`] : []))
        .sort();

const reasonsOf = (scan: { readonly pairs: readonly ScannedPair[] }): readonly string[] =>
    scan.pairs.flatMap((p) => (p.kind === "undecidable" ? [p.reason] : [])).sort();

describe("class-usage: 字句 (解析器に任せた結果を固定検体で確かめる)", () => {
    it("コメントの中のリテラルは拾わない", () => {
        const scan = scanClassUsageSource('// "bg-primary text-danger"\nexport const a = 1;\n', TS);
        expect(scan.occurrences).toEqual([]);
        expect(scan.diagnostics).toEqual([]);
    });

    it("エスケープした引用符で文字列が途中で閉じない", () => {
        const scan = scanTs("'it\\'s bg-primary'");
        expect(scan.occurrences.map((o) => o.utility)).toEqual(["bg-primary"]);
    });

    it("複数行のバッククォートリテラルを 1 単位として扱う", () => {
        const scan = scanClassUsageSource(
            "export const a = `bg-surface\n    text-text`;\n",
            TS,
        );
        expect(opaquePairs("`bg-surface\n    text-text`")).toEqual(["text on surface"]);
        expect(scan.occurrences.length).toBe(2);
    });

    it("補間を含む単位は interpolated の判定不能になる (通常リテラルに落とさない)", () => {
        expect(reasonsOf(scanTs("`${x} bg-primary text-neutral`"))).toEqual(["interpolated"]);
    });

    it("補間の中に閉じ波括弧を含む文字列を終端と誤認しない (以降のソースを読み落とさない)", () => {
        const source = 'export const a = `${ cond ? "}" : x }`;\nexport const b = "bg-surface text-text";\n';
        expect(scanClassUsageSource(source, TS).diagnostics).toEqual([]);
        expect(
            scanClassUsageSource(source, TS).pairs.flatMap((p) =>
                p.kind === "opaque" ? [`${p.fg} on ${p.bg}`] : [],
            ),
        ).toEqual(["text on surface"]);
    });

    it("補間の中の object literal と入れ子 template を終端と誤認しない", () => {
        const source =
            "export const a = `${ { k: `${y}` } }`;\nexport const b = \"bg-neutral text-text\";\n";
        const scan = scanClassUsageSource(source, TS);
        expect(scan.diagnostics).toEqual([]);
        expect(scan.occurrences.map((o) => o.utility).sort()).toEqual(["bg-neutral", "text-text"]);
    });

    it("補間内部の class 風文字列を二重に拾わない", () => {
        const scan = scanTs('`${"bg-primary text-danger"}`');
        expect(scan.occurrences).toEqual([]);
    });

    it("静的部分に監視対象語を持たない補間は単位を作らない (保証範囲の外であることの固定)", () => {
        // ★これは**検出できている**ことの検査ではなく、**検出しないと宣言した形**を
        //   固定検体で見える化するものである (共通規約 (b): 保証範囲の外にした構文は
        //   docblock に明記し、その構文について検出力を主張しない)。
        //   この形で class を組み立てると本走査器の全 gate を迂回できるため、
        //   迂回を止めているのは走査器ではなく規約と人のレビューである。
        const scan = scanTs("`${classes}`");
        expect(scan.occurrences).toEqual([]);
        expect(scan.pairs).toEqual([]);
        expect(scan.diagnostics).toEqual([]);
        // 静的部分に監視対象語が 1 つでもあれば判定不能として台帳に載る (対比)
        expect(reasonsOf(scanTs("`${classes} bg-primary`"))).toEqual(["interpolated"]);
    });

    it("未終端の文字列 / template / コメントは診断になり、当該ファイルの結果は空になる", () => {
        for (const source of [
            'export const a = "bg-primary;\n',
            "export const a = `bg-primary;\n",
            "/* unterminated\nexport const a = 1;\n",
        ]) {
            const scan = scanClassUsageSource(source, TS);
            expect(scan.diagnostics.map((d) => d.reason), source).toEqual(["ts-diagnostic"]);
            expect(scan.occurrences, source).toEqual([]);
            expect(scan.pairs, source).toEqual([]);
        }
    });

    it("括弧の不整合 (字句エラーではない構文エラー) も診断になる", () => {
        const scan = scanClassUsageSource('export const a = (1;\n', TS);
        expect(scan.diagnostics.map((d) => d.reason)).toEqual(["ts-diagnostic"]);
    });

    it(".svelte の parse 失敗は診断 svelte-parse-failed として残る", () => {
        const scan = scanClassUsageSource("<div class='bg-primary'>", SVELTE);
        expect(scan.diagnostics.map((d) => d.reason)).toEqual(["svelte-parse-failed"]);
        expect(scan.occurrences).toEqual([]);
    });

    it(".svelte の class 属性の静的テキストと script のリテラルを両方拾う", () => {
        const source =
            "<script>\n  const x = \"bg-neutral text-text\";\n</script>\n" +
            '<div class="bg-surface text-danger"></div>\n';
        const scan = scanClassUsageSource(source, SVELTE);
        expect(scan.occurrences.map((o) => o.utility).sort()).toEqual([
            "bg-neutral",
            "bg-surface",
            "text-danger",
            "text-text",
        ]);
    });
});

describe("class-usage: 監視対象の判定 (isWatchedCandidate)", () => {
    it.each(["./Button.types", "https://example.com/a", "保存しました", "flex", "px-3"])(
        "%s は非監視 (文字検証に掛からず無視される)",
        (candidate) => {
            expect(isWatchedCandidate(candidate)).toBe(false);
        },
    );

    it.each(["bg-primary", "sm:hover:bg-primary", "!bg-primary", "text-center", "bg-primaryあ"])(
        "%s は監視対象",
        (candidate) => {
            expect(isWatchedCandidate(candidate)).toBe(true);
        },
    );

    it("非 ASCII の混入は候補全体が unparsable-token になる (bg-primary へ縮退しない)", () => {
        for (const literal of ['"bg-primaryあ"', '"sm:bg-primaryあ"']) {
            const scan = scanClassUsageSource(`export const a = ${literal};\n`, TS);
            expect(scan.occurrences.length, literal).toBe(1);
            expect(scan.occurrences[0].resolution, literal).toEqual({
                kind: "unresolved",
                reason: "unparsable-token",
            });
        }
    });

    it("任意値の中のコロンを variant 境界と誤認しない (候補ごと消える fail-open を塞ぐ)", () => {
        // 素朴に split(":") すると rest が `#ffffff]` になって監視対象から外れ、
        // hex 直書きなのに occurrence 自体が作られない。
        const arbitrary = scanTs('"text-[color:#ffffff]"').occurrences;
        expect(arbitrary.length).toBe(1);
        expect(arbitrary[0].variants).toEqual([]);
        expect(arbitrary[0].utility).toBe("text-[color:#ffffff]");
        expect(arbitrary[0].resolution).toEqual({ kind: "unresolved", reason: "unknown-token" });

        // 角括弧の**外**のコロンは従来どおり variant 境界である
        const variantArbitrary = scanTs('"[&_svg]:stroke-current"').occurrences;
        expect(variantArbitrary[0].variants).toEqual(["[&_svg]"]);
        expect(variantArbitrary[0].utility).toBe("stroke-current");
    });

    it("bg-(--var) も候補全体が unparsable-token になる", () => {
        const scan = scanTs('"bg-(--var)"');
        expect(scan.occurrences[0].resolution).toEqual({
            kind: "unresolved",
            reason: "unparsable-token",
        });
    });

    it("import 指定子や日本語の文字列は occurrences を 1 件も作らない", () => {
        expect(scanTs('"./Button.types"').occurrences).toEqual([]);
        expect(scanTs('"保存しました"').occurrences).toEqual([]);
    });
});

describe("class-usage: 変種・重要度・不透明度の 3 形 (共通規約 (e))", () => {
    it("接頭辞つき / 打ち消しつき / 接尾辞つきをそれぞれ正しく解決する", () => {
        const prefixed = scanTs('"sm:bg-primary"').occurrences[0];
        expect(prefixed.variants).toEqual(["sm"]);
        expect(prefixed.utility).toBe("bg-primary");
        expect(prefixed.resolution.kind).toBe("color");

        const important = scanTs('"!bg-primary"').occurrences[0];
        expect(important.important).toBe(true);
        expect(important.utility).toBe("bg-primary");

        const alpha = scanTs('"bg-primary/10"').occurrences[0];
        expect(alpha.alphaPercent).toBe(10);
        expect(alpha.utility).toBe("bg-primary");
    });

    it("色でない utility への不透明度修飾は alpha-on-non-color (text-center として通さない)", () => {
        expect(scanTs('"text-center/50"').occurrences[0].resolution).toEqual({
            kind: "unresolved",
            reason: "alpha-on-non-color",
        });
        // 一方 3 形のうち接頭辞つき / 打ち消しつきは正しく解決する
        expect(scanTs('"sm:text-center"').occurrences[0].resolution).toEqual({
            kind: "contract",
            word: "text-center",
        });
        expect(scanTs('"!text-center"').occurrences[0].resolution).toEqual({
            kind: "contract",
            word: "text-center",
        });
    });

    it("不透明度修飾の端点", () => {
        expect(scanTs('"bg-primary/100"').occurrences[0].alphaPercent).toBeNull();
        expect(reasonsOf(scanTs('"bg-primary/0 text-text"'))).toEqual(["keyword-color"]);
        for (const literal of ['"bg-primary/101"', '"bg-primary/[0.35]"']) {
            expect(
                scanClassUsageSource(`export const a = ${literal};\n`, TS).occurrences[0].resolution,
                literal,
            ).toEqual({ kind: "unresolved", reason: "unsupported-alpha-syntax" });
        }
    });

    it("ramp と整列語を前景色として拾わない", () => {
        expect(scanTs('"text-body"').occurrences[0].resolution).toEqual({
            kind: "ramp",
            name: "body",
        });
        expect(scanTs('"text-center"').occurrences[0].resolution.kind).toBe("contract");
        // ramp と整列語だけの単位は前景の宣言を持たないので組にならない
        expect(pairsOf('"bg-surface text-body text-center"')).toEqual([]);
    });

    it("DESIGN.md のキーとの衝突: text-primary は前景色 primary、text-text は前景色 text", () => {
        expect(scanTs('"text-primary"').occurrences[0].resolution).toEqual({
            kind: "color",
            channel: "foreground",
            suffix: "primary",
        });
        expect(scanTs('"text-text"').occurrences[0].resolution).toEqual({
            kind: "color",
            channel: "foreground",
            suffix: "text",
        });
    });
});

describe("class-usage: 状態単位の分解 (i15 の設計核心)", () => {
    it("実在しない組を作らない (直積にしない)", () => {
        expect(opaquePairs('"bg-surface text-danger hover:bg-danger hover:text-neutral"')).toEqual([
            "danger on surface",
            "neutral on danger",
        ]);
    });

    it("状態の継承を片側だけ上書きする形も正しく解ける", () => {
        expect(opaquePairs('"text-text hover:bg-danger"')).toEqual(["text on danger"]);
        expect(opaquePairs('"bg-surface hover:text-danger"')).toEqual([
            "danger on surface",
            "text on surface",
        ].filter((p) => p === "danger on surface"));
    });

    it("variant の合成: 4 形をそれぞれ固定する", () => {
        // 1. 基底 + hover: → 解決可能
        expect(opaquePairs('"bg-surface hover:text-danger"')).toEqual(["danger on surface"]);
        // 2. 両 channel が同じ hover: → 解決可能
        expect(opaquePairs('"bg-surface text-text hover:bg-danger hover:text-neutral"')).toEqual([
            "neutral on danger",
            "text on surface",
        ]);
        // 3. sm: + sm:hover: → 判定不能
        expect(reasonsOf(scanTs('"bg-surface sm:bg-neutral sm:hover:text-danger"'))).toEqual([
            "variant-composition",
        ]);
        // 4. sm: + hover: → 判定不能 (同時成立を否定できない)
        expect(reasonsOf(scanTs('"bg-surface sm:bg-neutral hover:text-danger"'))).toEqual([
            "variant-composition",
        ]);
    });

    it("二重 alpha は判定不能にしない (実効値を作るのは gate 側の 1 か所だけ)", () => {
        expect(pairsOf('"bg-primary-soft/40 text-text"')).toEqual([
            {
                kind: "alpha-background",
                file: TS,
                fg: "text",
                bg: "primary-soft",
                modifierPercent: 40,
            },
        ]);
    });

    it("token の値が alpha を持つ背景は修飾なしでも alpha-background になる", () => {
        expect(pairsOf('"bg-primary-soft text-primary"')).toEqual([
            {
                kind: "alpha-background",
                file: TS,
                fg: "primary",
                bg: "primary-soft",
                modifierPercent: null,
            },
        ]);
    });
});

/**
 * 判定不能の**全分類**が固定検体で点灯することを確かめる。
 *
 * 分類数を散文に書かない — 網羅は `UNDECIDABLE_REASONS` (実行時の配列) から導出する。
 * 実リポジトリに「各分類が必ず存在する」ことを要求すると、コードが良くなって 0 件になった
 * 正常状態を赤にしてしまうので、点灯は合成入力で確かめる。
 */
const UNDECIDABLE_FIXTURES: Readonly<Record<UndecidableReason, string>> = {
    "foreground-alpha": '"bg-surface text-danger/70"',
    "keyword-color": '"bg-transparent text-danger"',
    "alpha-background-no-text": '"bg-primary/10"',
    "opaque-and-alpha-background": '"bg-surface bg-primary/10 text-text"',
    "multiple-background": '"bg-surface bg-neutral text-text"',
    "multiple-foreground": '"bg-surface text-text text-danger"',
    "element-opacity": '"bg-primary text-neutral opacity-40"',
    "interpolated": "`${x} bg-primary text-neutral`",
    "variant-composition": '"bg-surface sm:bg-neutral hover:text-danger"',
};

describe("class-usage: 分類分岐の点灯", () => {
    it("UNDECIDABLE_REASONS の全分類に検体があり、その分類が実際に出る", () => {
        expect(Object.keys(UNDECIDABLE_FIXTURES).sort()).toEqual(
            UNDECIDABLE_REASONS.map((r) => r.id).sort(),
        );
        for (const [reason, literal] of Object.entries(UNDECIDABLE_FIXTURES)) {
            expect(reasonsOf(scanTs(literal)), `${reason}: ${literal}`).toContain(reason);
        }
    });

    it("不完全な単位の分類が両方向とも点灯する", () => {
        expect(scanTs('"bg-surface"').incompleteOpaque).toEqual({
            backgroundOnly: 1,
            foregroundOnly: 0,
        });
        expect(scanTs('"text-text"').incompleteOpaque).toEqual({
            backgroundOnly: 0,
            foregroundOnly: 1,
        });
    });
});

/**
 * 期待する分解結果を**意味まで**書く (件数と kind だけでは、誤った fg/bg や
 * 誤った reason に分類されても通ってしまう)。
 *
 * 表記は `fg on bg` (不透明) / `fg on bg/修飾率` (半透明。修飾なしは `-`) /
 * `!理由` (判定不能)。キー集合が実装の variant 表と一致することを別に固定するので、
 * 件数は散文に書かない。
 */
const describePair = (pair: ScannedPair): string => {
    if (pair.kind === "opaque") return `${pair.fg} on ${pair.bg}`;
    if (pair.kind === "alpha-background") {
        return `${pair.fg} on ${pair.bg}/${pair.modifierPercent ?? "-"}`;
    }

    return `!${pair.reason}`;
};

const decomposed = (classes: string): readonly string[] =>
    pairsOf(JSON.stringify(classes)).map(describePair).sort();

const EXPECTED_TONE_PAIRS: Readonly<Record<string, readonly string[]>> = {
    primary: ["primary on primary-soft/-"],
    tertiary: ["tertiary on tertiary/10"],
    success: ["success on success/10"],
    warning: ["warning on warning/10"],
    danger: ["danger on danger/10"],
    neutral: ["text-secondary on neutral"],
};

const EXPECTED_VARIANT_PAIRS: Readonly<Record<string, readonly string[]>> = {
    "primary": ["neutral on primary", "neutral on primary-hover"],
    "tertiary": ["neutral on tertiary", "neutral on tertiary-hover"],
    "ghost": ["!keyword-color"],
    "neutral": ["text on border", "text on neutral"],
    "success": ["!element-opacity", "neutral on success"],
    "danger": ["!element-opacity", "neutral on danger"],
    "danger-outline": ["danger on surface", "neutral on danger"],
    "danger-ghost": ["!keyword-color", "danger on danger/10"],
};

describe("class-usage: 既知の要求組が抽出結果から生成される (正例)", () => {
    it("Badge の全 tone が期待どおりの組へ分解される", () => {
        expect(Object.keys(EXPECTED_TONE_PAIRS).sort()).toEqual(Object.keys(TONE_CLASSES).sort());
        for (const [tone, classes] of Object.entries(TONE_CLASSES)) {
            expect(decomposed(classes), `${tone}: ${classes}`).toEqual(
                [...EXPECTED_TONE_PAIRS[tone]].sort(),
            );
        }
    });

    it("Button の全 variant が期待どおりの組 / 判定不能へ分解される", () => {
        expect(Object.keys(EXPECTED_VARIANT_PAIRS).sort()).toEqual(
            Object.keys(VARIANT_CLASSES).sort(),
        );
        for (const [variant, classes] of Object.entries(VARIANT_CLASSES)) {
            expect(decomposed(classes), `${variant}: ${classes}`).toEqual(
                [...EXPECTED_VARIANT_PAIRS[variant]].sort(),
            );
        }
    });
});

describe("class-usage: 扱えない既知の入口の deny", () => {
    it("3 群それぞれを合成入力で検出する", () => {
        expect(
            unsupportedEntryPointsSource("<div class:active={x}></div>\n", SVELTE).map((e) => e.kind),
        ).toEqual(["class-directive"]);
        expect(
            unsupportedEntryPointsSource(
                'import clsx from "clsx";\nexport const a = clsx("bg-primary");\n',
                TS,
            ).map((e) => e.kind),
        ).toEqual(["class-helper-library"]);
        expect(
            unsupportedEntryPointsSource("export const a = `bg-${tone}`;\n", TS).map((e) => e.kind),
        ).toEqual(["interpolated-prefix"]);
    });

    it.each(["twMerge", "tailwind-merge", "classnames", "cva"])("%s も語彙で検出する", (name) => {
        expect(
            unsupportedEntryPointsSource(`import x from "${name}";\n`, TS).length,
        ).toBeGreaterThan(0);
    });

    it("紛らわしい形を誤検出しない (接頭辞つき・打ち消しつき・接尾辞つきの 3 形を含む)", () => {
        // class: 直後が空白の分割代入 props は別物
        expect(
            unsupportedEntryPointsSource(
                "<script>let { class: extraClass } = $props();</script>\n<div></div>\n",
                SVELTE,
            ),
        ).toEqual([]);
        // 語彙の部分一致で当てない (接頭辞つき / 打ち消しつき / 接尾辞つき)
        for (const token of ["myclsx", "clsx-helper", "not_cva", "cvax", "xcva"]) {
            expect(
                unsupportedEntryPointsSource(`export const ${token} = 1;\n`, TS),
                token,
            ).toEqual([]);
        }
        // 完成した class 文字列を補間で差し込む形は入口の deny ではない (判定不能で受ける)
        expect(unsupportedEntryPointsSource("export const a = `${state} bg-primary`;\n", TS)).toEqual(
            [],
        );
        // テーマ名前空間でない接頭辞の直後の補間は当たらない
        expect(
            unsupportedEntryPointsSource("export const a = `take-thumbnail-${id}`;\n", TS),
        ).toEqual([]);
    });
});

describe("class-usage: 拡張子の最長接尾辞一致", () => {
    it.each([
        ["resources/js/vite-env.d.ts", false],
        ["resources/js/app.ts", true],
        ["resources/js/components/atoms/Badge.svelte", true],
        ["resources/js/components/atoms/icons/.gitkeep", false],
    ])("%s の走査可否が %s", (name, scanned) => {
        expect(isScannedFileName(name)).toBe(scanned);
    });

    it("未分類の拡張子は例外 (無言で走査対象から外さない)", () => {
        expect(() => isScannedFileName("resources/js/x.json")).toThrow(/未分類の拡張子/);
    });
});

describe("class-usage: 実リポジトリの走査", () => {
    const scan = scanClassUsage();

    it("解析の診断が 1 件も無い (本 gate が class 走査の診断の消費先である)", () => {
        expect(scan.diagnostics).toEqual([]);
    });

    it("走査分母が空でない", () => {
        expect(scan.files.length).toBeGreaterThan(0);
        expect(scan.occurrences.length).toBeGreaterThan(0);
        expect(scan.pairs.length).toBeGreaterThan(0);
    });

    it("直下の子の分類と走査結果のキーが集合一致し、要求する子は 0 件でない", () => {
        expect([...scan.perDirectory.keys()].sort()).toEqual(
            Object.keys(JS_SCAN_CHILD_CLASSIFICATION).sort(),
        );
        for (const [dir, spec] of Object.entries(JS_SCAN_CHILD_CLASSIFICATION)) {
            if (!spec.requiresOccurrences) continue;
            expect(scan.perDirectory.get(dir), `${dir} から 1 件も抽出できていない`).toBeGreaterThan(
                0,
            );
        }
    });

    it("扱えない既知の入口が 0 件である", () => {
        expect(unsupportedEntryPoints()).toEqual([]);
    });
});
