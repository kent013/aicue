import { describe, expect, it } from "vitest";
import { scanSources } from "../support/file-input-scan";
import type {
    FileInputAcceptEntry,
    FileInputPolicy,
    RawHtmlExemption,
    UnresolvedFormExemption,
} from "../support/file-input-accept-inventory";
import { evaluateFileInputInventory } from "../support/file-input-accept-inventory";
import type { FileInputScanResult, ScanDiagnosticReason } from "../support/file-input-scan";

/**
 * `file-input-accept-source-inventory` gate の走査器と判定関数の自己検査。
 *
 * **合成入力のみ**で実ファイルに依存しない。(A) は走査器の検出力 (負例で診断になること /
 * 正例で誤検出しないこと)、(B) は判定関数の分岐 (未登録・残置・件数 pin・母集団非空・
 * 診断の取り扱い) を両方向で固定する。
 *
 * (B) を独立に置く理由: (A) だけでは「実リポジトリが偶然適合しているせいで判定関数の
 * 比較分岐が壊れていても緑」という状態を検出できない。
 */

/** 1 ファイル分の合成ソースを走査する短縮形。 */
const scanOne = (source: string, file = "pages/Synthetic.svelte") => scanSources([{ file, source }]);

const reasonsOf = (result: FileInputScanResult): string[] =>
    result.diagnostics.map((d) => d.reason);

// ---------------------------------------------------------------------------
// (A) 走査器の負例 (診断になること)
// ---------------------------------------------------------------------------

describe("file input 走査器: 負例 (未解決の形は診断になる)", () => {
    it("1. spread 属性は type/accept を上書きしうるので診断になる", () => {
        const result = scanOne('<input type="file" accept="x" {...attrs} />');

        expect(reasonsOf(result)).toEqual(["spread-attribute"]);
        expect(result.fileInputs).toEqual([]);
        expect(result.nativeInputCount).toBe(1);
        expect(result.diagnostics[0].at).not.toBeNull();
        expect(result.diagnostics[0].detail.length).toBeGreaterThan(0);
    });

    it("2. type が式のときは「非 file」と決めつけず診断になる", () => {
        expect(reasonsOf(scanOne("<input type={kind} />"))).toEqual(["unresolved-type"]);
    });

    it("3. type の真偽短縮も診断になる", () => {
        expect(reasonsOf(scanOne("<input type />"))).toEqual(["unresolved-type"]);
    });

    it("4. file input に accept が無ければ診断になる", () => {
        expect(reasonsOf(scanOne('<input type="file" />'))).toEqual(["missing-accept"]);
    });

    it("5. type={\"file\"} は式を評価しないので診断になる", () => {
        expect(reasonsOf(scanOne('<input type={"file"} accept="x" />'))).toEqual([
            "unresolved-type",
        ]);
    });

    it("6. type が無くても spread があれば診断になる", () => {
        expect(reasonsOf(scanOne("<input {...attrs} />"))).toEqual(["spread-attribute"]);
    });

    it("7. accept の真偽短縮は診断になる", () => {
        expect(reasonsOf(scanOne('<input type="file" accept />'))).toEqual(["unresolved-accept"]);
    });

    it("8. parse 失敗はファイル単位の診断で、位置を持たない", () => {
        const result = scanOne("<div><span/>");

        expect(reasonsOf(result)).toEqual(["parse-failed"]);
        expect(result.diagnostics[0].at).toBeNull();
        expect(result.fileInputs).toEqual([]);
        expect(result.rawHtml).toEqual([]);
        // ファイル単位なので序数の概念を持たない
        expect(result.diagnostics[0]).not.toHaveProperty("occurrence");
    });

    it("16. <svelte:element this={tag}> は実行時に input になりうるので診断になる", () => {
        expect(reasonsOf(scanOne("<svelte:element this={tag} />"))).toEqual([
            "unresolved-native-element",
        ]);
    });

    it("40. 複数パートの type は診断になる (静的に file と確定できない)", () => {
        expect(reasonsOf(scanOne('<input type="fi{x}le" accept="y" />'))).toEqual([
            "unresolved-type",
        ]);
    });

    it("41. 綴りが同じ属性の重複は svelte の parse 自体が拒否する (parse-failed = fail-closed)", () => {
        const result = scanOne('<input type="file" type="text" accept="y" />');

        expect(reasonsOf(result)).toEqual(["parse-failed"]);
        expect(result.fileInputs).toEqual([]);
    });

    /*
     * **svelte の重複検査は大小文字を区別する**ため、大小文字違いの重複は parse を通る (実測)。
     * 走査器側が小文字化して先頭だけを採ると、後続の属性を無言で捨てて
     * 「実行時には file input なのに母集団外」になる (fail-open)。正規化後に複数件ある形は
     * どちらが効くか確定できないので診断へ落とす。
     */
    it("44. 大小文字違いの type の重複は parse を通るので走査器が診断へ落とす", () => {
        const result = scanOne('<input type="text" TYPE="file" accept="x" />');

        expect(reasonsOf(result)).toEqual(["unresolved-type"]);
        expect(result.fileInputs).toEqual([]);
        expect(result.nativeInputCount).toBe(1);
    });

    it("45. 大小文字違いの accept の重複も診断へ落とす", () => {
        const result = scanOne('<input type="file" accept="x" ACCEPT="y" />');

        expect(reasonsOf(result)).toEqual(["unresolved-accept"]);
        expect(result.fileInputs).toEqual([]);
    });

    it("46. 大小文字違いの重複は宣言順に関係なく診断になる", () => {
        expect(reasonsOf(scanOne('<input TYPE="file" type="text" accept="x" />'))).toEqual([
            "unresolved-type",
        ]);
    });
});

// ---------------------------------------------------------------------------
// (A) 走査器の正例 (誤検出しないこと)
// ---------------------------------------------------------------------------

describe("file input 走査器: 正例 (規定どおりの入力を誤検出しない)", () => {
    it("9. 非 file の input は母集団に入らない (native input としては数える)", () => {
        const result = scanOne('<input type="text" /><input />');

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs).toEqual([]);
        expect(result.nativeInputCount).toBe(2);
    });

    it("10. accept が式なら expression", () => {
        const result = scanOne('<input type="file" accept={x} />');

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs).toEqual([
            { file: "pages/Synthetic.svelte", occurrence: 1, syntax: "expression", literal: null },
        ]);
    });

    it("11. accept の短縮記法 (実コードで使用中) も expression", () => {
        const result = scanOne('<input type="file" {accept} />');

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs[0].syntax).toBe("expression");
        expect(result.fileInputs[0].literal).toBeNull();
    });

    it("12. 三項演算子 (実コードで使用中) も expression", () => {
        const result = scanOne('<input type="file" accept={a ? "x" : "y"} />');

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs[0].syntax).toBe("expression");
    });

    it("13. type=\"FILE\" も file 扱いで、静的テキストの accept は literal を記録する", () => {
        const result = scanOne('<input type="FILE" accept="image/*" />');

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs[0].syntax).toBe("static-text");
        expect(result.fileInputs[0].literal).toBe("image/*");
    });

    it("14. 複数パートの accept は expression", () => {
        const result = scanOne('<input type="file" accept="a{b}c" />');

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs[0].syntax).toBe("expression");
        expect(result.fileInputs[0].literal).toBeNull();
    });

    it("15. 同一ファイルの file input には出現順に序数が付く", () => {
        const result = scanOne(
            '<input type="file" accept="a" /><div><input type="file" accept={b} /></div>',
        );

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs.map((r) => [r.occurrence, r.syntax])).toEqual([
            [1, "static-text"],
            [2, "expression"],
        ]);
    });

    it("17. {@html …} は診断ではなく生 HTML の実測として記録される", () => {
        const result = scanOne("{@html markup}");

        expect(result.diagnostics).toEqual([]);
        expect(result.rawHtml).toHaveLength(1);
        expect(result.rawHtml[0].occurrence).toBe(1);
        expect(result.rawHtml[0].at).not.toBeNull();
    });

    it("18. <svelte:element this=\"input\"> は file input として数える", () => {
        const result = scanOne('<svelte:element this="input" type="file" accept={x} />');

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs[0].syntax).toBe("expression");
        expect(result.nativeInputCount).toBe(1);
    });

    it("19. 要素名の大文字小文字は無視する (this=\"INPUT\")", () => {
        const result = scanOne('<svelte:element this="INPUT" type="file" accept="image/*" />');

        expect(result.diagnostics).toEqual([]);
        expect(result.fileInputs[0].syntax).toBe("static-text");
        expect(result.fileInputs[0].literal).toBe("image/*");
    });

    it("20. 静的に非 input と確定できる <svelte:element this=\"div\"> は母集団外", () => {
        const result = scanOne('<svelte:element this="div" />');

        expect(result.diagnostics).toEqual([]);
        expect(result.nativeInputCount).toBe(0);
        expect(result.fileInputs).toEqual([]);
    });

    it("21. component は母集団外 (native input ではない)", () => {
        const result = scanOne("<Foo /><svelte:component this={C} />");

        expect(result.diagnostics).toEqual([]);
        expect(result.nativeInputCount).toBe(0);
        expect(result.fileInputs).toEqual([]);
    });

    it("21b. 同一ファイルの {@html} には出現順に序数が付く", () => {
        const result = scanOne("{@html a}<div>{@html b}</div>");

        expect(result.rawHtml.map((r) => r.occurrence)).toEqual([1, 2]);
    });

    /*
     * native HTML の属性名は ASCII 大文字小文字を区別しない。属性名の照合を区別する実装だと
     * `TYPE="file"` が「type 属性なし」として母集団から無言で外れ、accept の供給元宣言を
     * 回避できる (fail-open)。要素名・type の値と同じ扱いに揃える。
     */
    it("42. 属性名の大文字小文字を無視する (TYPE / ACCEPT でも file input として数える)", () => {
        const result = scanOne('<input TYPE="file" ACCEPT="image/*" />');

        expect(result.diagnostics).toEqual([]);
        expect(result.nativeInputCount).toBe(1);
        expect(result.fileInputs).toEqual([
            {
                file: "pages/Synthetic.svelte",
                occurrence: 1,
                syntax: "static-text",
                literal: "image/*",
            },
        ]);
    });

    it("43. 属性名の大文字小文字を無視するので TYPE=\"file\" の accept 欠落も診断になる", () => {
        expect(reasonsOf(scanOne('<input TYPE="file" />'))).toEqual(["missing-accept"]);
    });

    it("走査したファイル数を返す (走査根が生きていることの確認用)", () => {
        const result = scanSources([
            { file: "a.svelte", source: '<input type="file" accept="x" />' },
            { file: "b.svelte", source: "<div />" },
        ]);

        expect(result.svelteFileCount).toBe(2);
        expect(result.fileInputs.map((r) => r.file)).toEqual(["a.svelte"]);
    });
});

// ---------------------------------------------------------------------------
// (B) 判定関数の負例・正例
// ---------------------------------------------------------------------------

const RATIONALE = "サーバの単一の情報源から props で受け取るため、ここでは静的な値を持たない";

function entry(overrides: Partial<FileInputAcceptEntry> = {}): FileInputAcceptEntry {
    return {
        file: "pages/A.svelte",
        occurrence: 1,
        syntax: "expression",
        supply: "server-prop",
        rationale: RATIONALE,
        ...overrides,
    };
}

function scan(overrides: Partial<FileInputScanResult> = {}): FileInputScanResult {
    return {
        svelteFileCount: 3,
        nativeInputCount: 2,
        fileInputs: [
            { file: "pages/A.svelte", occurrence: 1, syntax: "expression", literal: null },
        ],
        diagnostics: [],
        rawHtml: [],
        ...overrides,
    };
}

function policy(overrides: Partial<FileInputPolicy> = {}): FileInputPolicy {
    return {
        inventory: [entry()],
        countPin: 1,
        rawHtmlExemptions: [],
        rawHtmlExemptionCountPin: 0,
        unresolvedFormExemptions: [],
        unresolvedFormExemptionCountPin: 0,
        ...overrides,
    };
}

const rawHtmlRecord = (occurrence = 1) => ({
    file: "pages/B.svelte",
    occurrence,
    at: { line: 1, column: 0 },
});

const rawHtmlExemption = (overrides: Partial<RawHtmlExemption> = {}): RawHtmlExemption => ({
    file: "pages/B.svelte",
    occurrence: 1,
    rationale: "サーバが生成した SVG をそのまま描画する箇所で、ファイル入力を作らないため免除する",
    ...overrides,
});

const unresolvedExemption = (
    overrides: Partial<UnresolvedFormExemption> = {},
): UnresolvedFormExemption => ({
    file: "components/atoms/Input.svelte",
    reason: "spread-attribute",
    count: 1,
    rationale: "汎用入力 atom は呼び出し側の属性をそのまま転送する設計で、accept の供給元を持たない",
    ...overrides,
});

describe("判定関数: 正例", () => {
    it("22. 適合する組は違反 0 件", () => {
        expect(evaluateFileInputInventory(scan(), policy())).toEqual([]);
    });

    it("35. 生 HTML の実測が免除目録にあれば違反にならない", () => {
        const violations = evaluateFileInputInventory(
            scan({ rawHtml: [rawHtmlRecord()] }),
            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
        );

        expect(violations).toEqual([]);
    });
});

describe("判定関数: 負例 (目録の突き合わせ)", () => {
    it("23. 目録に無い実測は未登録の違反", () => {
        const violations = evaluateFileInputInventory(
            scan(),
            policy({ inventory: [], countPin: 0 }),
        );

        expect(violations.join("\n")).toContain("未登録");
    });

    it("24. 実測に無い目録は残置の違反", () => {
        const violations = evaluateFileInputInventory(
            scan({ fileInputs: [] }),
            policy(),
        );

        expect(violations.join("\n")).toContain("残置");
    });

    it("25. syntax の宣言が実測と違えば違反", () => {
        const violations = evaluateFileInputInventory(
            scan(),
            policy({ inventory: [entry({ syntax: "static-text", supply: "client-owned" })] }),
        );

        expect(violations.join("\n")).toContain("syntax");
    });

    it("26. 目録キーの重複は違反", () => {
        const violations = evaluateFileInputInventory(
            scan(),
            policy({ inventory: [entry(), entry()], countPin: 2 }),
        );

        expect(violations.join("\n")).toContain("重複");
    });

    it("27. rationale が 29 文字なら違反 (supply が server-prop でも検査する)", () => {
        const short = "あ".repeat(29);
        const violations = evaluateFileInputInventory(
            scan(),
            policy({ inventory: [entry({ rationale: short })] }),
        );

        expect(violations.join("\n")).toContain("30 文字");
    });

    it("28. occurrence が 0 なら違反", () => {
        const violations = evaluateFileInputInventory(
            scan(),
            policy({ inventory: [entry({ occurrence: 0 })] }),
        );

        expect(violations.join("\n")).toContain("occurrence");
    });

    it("29. 件数 pin が実測と 1 件ずれれば違反", () => {
        const violations = evaluateFileInputInventory(scan(), policy({ countPin: 2 }));

        expect(violations.join("\n")).toContain("件数");
    });

    it("34. server-prop と static-text の組み合わせは整合違反", () => {
        const violations = evaluateFileInputInventory(
            scan({
                fileInputs: [
                    { file: "pages/A.svelte", occurrence: 1, syntax: "static-text", literal: "x" },
                ],
            }),
            policy({ inventory: [entry({ syntax: "static-text" })] }),
        );

        expect(violations.join("\n")).toContain("server-prop");
    });
});

describe("判定関数: 負例 (母集団と診断)", () => {
    it("30. 走査が空振りしていれば違反", () => {
        const violations = evaluateFileInputInventory(scan({ svelteFileCount: 0 }), policy());

        expect(violations.join("\n")).toContain("空振り");
    });

    it("31/32. 母集団が空の 2 条件は別の違反として返る", () => {
        const violations = evaluateFileInputInventory(
            scan({ nativeInputCount: 0, fileInputs: [] }),
            policy({ inventory: [], countPin: 0 }),
        );

        expect(violations.filter((v) => v.includes("native input"))).toHaveLength(1);
        expect(violations.filter((v) => v.includes("file input"))).toHaveLength(1);
        expect(violations).toHaveLength(2);
    });

    it("33. 免除目録に無い診断は違反になる (走査器が集めた診断を判定が無視しない)", () => {
        const violations = evaluateFileInputInventory(
            scan({
                diagnostics: [
                    {
                        file: "pages/C.svelte",
                        reason: "unresolved-type",
                        at: { line: 3, column: 4 },
                        detail: "type 属性が式である",
                    },
                ],
            }),
            policy(),
        );

        expect(violations.join("\n")).toContain("unresolved-type");
    });
});

describe("判定関数: 負例 (生 HTML の免除目録)", () => {
    it("36. 免除目録に無い生 HTML は未登録の違反", () => {
        const violations = evaluateFileInputInventory(scan({ rawHtml: [rawHtmlRecord()] }), policy());

        expect(violations.join("\n")).toContain("生 HTML");
    });

    it("37. 実測に無い免除は残置の違反", () => {
        const violations = evaluateFileInputInventory(
            scan(),
            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
        );

        expect(violations.join("\n")).toContain("残置");
    });

    it("38. 免除済みファイルに 2 件目の {@html} が増えたら未登録の違反", () => {
        const violations = evaluateFileInputInventory(
            scan({ rawHtml: [rawHtmlRecord(1), rawHtmlRecord(2)] }),
            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
        );

        expect(violations.join("\n")).toContain("生 HTML");
        expect(violations.join("\n")).toContain("occurrence=2");
    });

    it("39a. 免除の rationale が 29 文字なら違反", () => {
        const violations = evaluateFileInputInventory(
            scan({ rawHtml: [rawHtmlRecord()] }),
            policy({
                rawHtmlExemptions: [rawHtmlExemption({ rationale: "あ".repeat(29) })],
                rawHtmlExemptionCountPin: 1,
            }),
        );

        expect(violations.join("\n")).toContain("30 文字");
    });

    it("39b. 免除の occurrence が 0 なら違反", () => {
        const violations = evaluateFileInputInventory(
            scan({ rawHtml: [rawHtmlRecord()] }),
            policy({
                rawHtmlExemptions: [rawHtmlExemption({ occurrence: 0 })],
                rawHtmlExemptionCountPin: 1,
            }),
        );

        expect(violations.join("\n")).toContain("occurrence");
    });

    it("39c. 免除キーの重複は違反", () => {
        const violations = evaluateFileInputInventory(
            scan({ rawHtml: [rawHtmlRecord()] }),
            policy({
                rawHtmlExemptions: [rawHtmlExemption(), rawHtmlExemption()],
                rawHtmlExemptionCountPin: 2,
            }),
        );

        expect(violations.join("\n")).toContain("重複");
    });

    it("39d. 免除の件数 pin が 1 件ずれれば違反", () => {
        const violations = evaluateFileInputInventory(
            scan({ rawHtml: [rawHtmlRecord()] }),
            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 2 }),
        );

        expect(violations.join("\n")).toContain("件数");
    });
});

/*
 * 未解決の形の免除目録。
 *
 * **設計からの逸脱**: 詳細設計は「診断に免除の概念は無い (無条件で違反)」としていたが、
 * その前提 (実リポジトリの診断が 0 件) は実測で成り立たなかった。汎用入力 atom
 * (`components/atoms/Input.svelte`) は `{type}` と `{...rest}` を持ち、静的には file input に
 * なりうる形が正当に実在する。無条件違反にすると gate が実装できないため、
 * **免除できる理由を 1 つに限った上で** deny-by-default の免除目録で扱う。
 *
 * 鍵は `file` + `reason` + **件数の完全一致**である。「名指し」と呼べる精度ではなく、
 * **同一ファイル・同一理由・同数の置き換えは検出しない** (最後の負のコントロールで
 * その境界を機械 pin している)。未登録の未解決形と免除できない理由は違反である。
 */
describe("判定関数: 未解決の形の免除目録", () => {
    const diagnostic = (file = "components/atoms/Input.svelte") =>
        ({
            file,
            reason: "spread-attribute" as const,
            at: { line: 1, column: 0 },
            detail: "spread 属性が type/accept を上書きしうる",
        });

    it("免除目録に登録済みの未解決形は違反にならない (件数まで一致)", () => {
        const violations = evaluateFileInputInventory(
            scan({ diagnostics: [diagnostic()] }),
            policy({
                unresolvedFormExemptions: [unresolvedExemption()],
                unresolvedFormExemptionCountPin: 1,
            }),
        );

        expect(violations).toEqual([]);
    });

    it("免除済みファイルに 2 件目の未解決形が増えたら件数不一致で違反", () => {
        const violations = evaluateFileInputInventory(
            scan({ diagnostics: [diagnostic(), diagnostic()] }),
            policy({
                unresolvedFormExemptions: [unresolvedExemption()],
                unresolvedFormExemptionCountPin: 1,
            }),
        );

        expect(violations.join("\n")).toContain("件数");
    });

    it("実測に無い未解決形の免除は残置の違反", () => {
        const violations = evaluateFileInputInventory(
            scan(),
            policy({
                unresolvedFormExemptions: [unresolvedExemption()],
                unresolvedFormExemptionCountPin: 1,
            }),
        );

        expect(violations.join("\n")).toContain("残置");
    });

    it("同じ reason でも別ファイルの未解決形は免除に一致しない", () => {
        const violations = evaluateFileInputInventory(
            scan({ diagnostics: [diagnostic("pages/Other.svelte")] }),
            policy({
                unresolvedFormExemptions: [unresolvedExemption()],
                unresolvedFormExemptionCountPin: 1,
            }),
        );

        expect(violations.join("\n")).toContain("pages/Other.svelte");
    });

    /*
     * 免除できる理由は狭い union (`ExemptibleDiagnosticReason`) に限る。
     * 型でも塞いでいるが、目録は人が書くデータなので実行時にも拒否する
     * (型は同一 PR 内でしか効かず、`as` で抜けられる)。
     */
    it.each<ScanDiagnosticReason>([
        "parse-failed",
        "missing-accept",
        "unresolved-accept",
        "unresolved-native-element",
        "unresolved-type",
    ])("免除できない理由 (%s) は目録へ登録しても違反のまま", (reason) => {
        const violations = evaluateFileInputInventory(
            scan({
                diagnostics: [
                    {
                        file: "components/atoms/Input.svelte",
                        reason,
                        at: reason === "parse-failed" ? null : { line: 1, column: 0 },
                        detail: "合成した診断",
                    },
                ],
            }),
            policy({
                // 型では通らない登録を実行時にも拒否することを確かめる
                unresolvedFormExemptions: [
                    unresolvedExemption({ reason } as Partial<UnresolvedFormExemption>),
                ],
                unresolvedFormExemptionCountPin: 1,
            }),
        );

        expect(violations.join("\n")).toContain(reason);
        expect(violations.join("\n")).toContain("免除できない");
    });

    /*
     * **保証範囲の境界 (負のコントロール)**: 鍵は `file` + `reason` + 件数であり、
     * 同一ファイル・同一理由・同数の**置き換え**は検出しない (docblock に明記した限界)。
     * ここを厳しくする実装へ変えたら本テストが落ちて、docblock と AGENTS.md の
     * 記述を直す契機になる。
     */
    it("保証範囲の境界: 同一ファイル・同一理由・同数の置き換えは検出しない", () => {
        const moved = {
            file: "components/atoms/Input.svelte",
            reason: "spread-attribute" as const,
            at: { line: 99, column: 8 }, // 位置が変わっても件数は 1 のまま
            detail: "別の要素へ移った spread 属性",
        };

        const violations = evaluateFileInputInventory(
            scan({ diagnostics: [moved] }),
            policy({
                unresolvedFormExemptions: [unresolvedExemption()],
                unresolvedFormExemptionCountPin: 1,
            }),
        );

        expect(violations).toEqual([]);
    });

    it("免除の rationale が 29 文字 / count が 0 / キー重複 / 件数 pin ずれはそれぞれ違反", () => {
        const base = scan({ diagnostics: [diagnostic()] });

        expect(
            evaluateFileInputInventory(
                base,
                policy({
                    unresolvedFormExemptions: [unresolvedExemption({ rationale: "あ".repeat(29) })],
                    unresolvedFormExemptionCountPin: 1,
                }),
            ).join("\n"),
        ).toContain("30 文字");

        expect(
            evaluateFileInputInventory(
                base,
                policy({
                    unresolvedFormExemptions: [unresolvedExemption({ count: 0 })],
                    unresolvedFormExemptionCountPin: 1,
                }),
            ).join("\n"),
        ).toContain("count");

        expect(
            evaluateFileInputInventory(
                base,
                policy({
                    unresolvedFormExemptions: [unresolvedExemption(), unresolvedExemption()],
                    unresolvedFormExemptionCountPin: 2,
                }),
            ).join("\n"),
        ).toContain("重複");

        expect(
            evaluateFileInputInventory(
                base,
                policy({
                    unresolvedFormExemptions: [unresolvedExemption()],
                    unresolvedFormExemptionCountPin: 2,
                }),
            ).join("\n"),
        ).toContain("件数");
    });
});
