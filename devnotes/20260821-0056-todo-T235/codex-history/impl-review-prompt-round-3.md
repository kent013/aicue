Round 2 の指摘への対応が完了した。指摘は 3 件すべて正しく、とくに大小文字違いの重複属性は
実測で parse を通ることを確認した (私の Round 1 の「到達不能」判断が誤りだった)。

## 実測の記録 (svelte 5.56.3)

```
<input type="file" type="text" accept="y" />   -> REJECTED (Attributes need to be unique)
<input type="text" TYPE="file" accept="x" />   -> PARSED (attributes: type,TYPE,accept)
<input type="file" accept="x" ACCEPT="y" />    -> PARSED (attributes: type,accept,ACCEPT)
<input TYPE="file" type="text" accept="x" />   -> PARSED (attributes: TYPE,type,accept)
```

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Critical] 大小文字違いの重複属性が未検証 (`type="text" TYPE="file"`)

- 判断: **対応する** (指摘が正しく、Round 1 での私の「到達不能」判断は裏取り不足だった)
- 根拠: 実測した結果、**svelte の重複検査は大小文字を区別する**ことが分かった。
  - `<input type="file" type="text" accept="y" />` → `Attributes need to be unique` で parse 拒否
  - `<input type="text" TYPE="file" accept="x" />` → **parse を通る** (属性は `type,TYPE,accept`)
  - `<input type="file" accept="x" ACCEPT="y" />` → **parse を通る** (`type,accept,ACCEPT`)

  したがって Round 1 で属性名を小文字化した変更と引き換えに、`attributeNamed()` が
  正規化後の**先頭だけ**を採る形が新しい fail-open を作っていた
  (`type="text" TYPE="file"` は先頭の `type="text"` を見て母集団外になる = 実行時には file input)。
- 対応内容:
  - `attributeNamed()` を `attributesNamed()` へ戻し、**正規化後に複数件ある形を診断へ落とす**
    分岐を復活させた (`type` → `unresolved-type` / `accept` → `unresolved-accept`)。
    これらは免除できない理由なので無条件違反になる。
  - 撤去したのは「綴りが同じ重複」用の分岐という判断も改めた: 分岐は 1 本で両方を扱う
    (綴りが同じ重複は parse 側で落ちるため、この分岐に到達するのは大小文字違いだけ)。
  - 負例を 3 件追加した:
    - `44.` `type="text" TYPE="file" accept="x"` → `unresolved-type` (母集団から外れない)
    - `45.` `type="file" accept="x" ACCEPT="y"` → `unresolved-accept`
    - `46.` 宣言順を入れ替えた `TYPE="file" type="text"` でも `unresolved-type`
  - 既存の `41.` は「**綴りが同じ**属性の重複は parse が拒否する」ことの pin として残した
    (2 通りの経路がどちらも fail-closed であることを別々に固定する)。

## [Warning] `FileInputScanResult.diagnostics` の説明が実態と合っていない

- 判断: **対応する**
- 根拠: 指摘どおり。免除目録と突き合わせるのは `spread-attribute` だけで、既定は無条件違反である。
- 対応内容: 「判定側の既定は**無条件で違反**で、免除目録と突き合わせるのは免除できる理由に
  限られる (現在は `spread-attribute` だけ。正本は `ExemptibleDiagnosticReason`)」へ書き換えた。
  併せて走査器 docblock の属性重複の記述も、2 通りの経路 (綴り同じ = `parse-failed` /
  大小文字違い = `unresolved-type` / `unresolved-accept`) を書く形へ訂正した。

## [Warning] 自己検査の説明に「名指しの免除目録」が残っている

- 判断: **対応する**
- 根拠: 指摘どおり。AGENTS.md と目録 docblock は訂正したのに、テスト側の説明だけ古かった。
- 対応内容: 「鍵は `file` + `reason` + 件数の完全一致であり『名指し』と呼べる精度ではない。
  同一ファイル・同一理由・同数の置き換えは検出しない (最後の負のコントロールで境界を機械 pin)」
  へ書き換えた。

## [Suggestion] `ExemptibleDiagnosticReason` と `EXEMPTIBLE_DIAGNOSTIC_REASONS` の二重定義

- 判断: **対応する**
- 根拠: 二重定義は「片方だけ広げられる」ずれの余地を残す。提案の形で構造的に防げる。
- 対応内容: 提案どおり定数配列を正本にし、型をそこから導出する形へ変えた。
  `satisfies readonly ScanDiagnosticReason[]` を付けて、配列の要素が診断理由の
  値域から外れたらコンパイルで落ちるようにしてある。

---

## 修正後の内容 (走査器・目録・自己検査の 3 ファイル)

```diff
diff --git a/tests/js/architecture/file-input-scan.test.ts b/tests/js/architecture/file-input-scan.test.ts
new file mode 100644
index 00000000..eaa61de1
--- /dev/null
+++ b/tests/js/architecture/file-input-scan.test.ts
@@ -0,0 +1,714 @@
+import { describe, expect, it } from "vitest";
+import { scanSources } from "../support/file-input-scan";
+import type {
+    FileInputAcceptEntry,
+    FileInputPolicy,
+    RawHtmlExemption,
+    UnresolvedFormExemption,
+} from "../support/file-input-accept-inventory";
+import { evaluateFileInputInventory } from "../support/file-input-accept-inventory";
+import type { FileInputScanResult, ScanDiagnosticReason } from "../support/file-input-scan";
+
+/**
+ * `file-input-accept-source-inventory` gate の走査器と判定関数の自己検査。
+ *
+ * **合成入力のみ**で実ファイルに依存しない。(A) は走査器の検出力 (負例で診断になること /
+ * 正例で誤検出しないこと)、(B) は判定関数の分岐 (未登録・残置・件数 pin・母集団非空・
+ * 診断の取り扱い) を両方向で固定する。
+ *
+ * (B) を独立に置く理由: (A) だけでは「実リポジトリが偶然適合しているせいで判定関数の
+ * 比較分岐が壊れていても緑」という状態を検出できない。
+ */
+
+/** 1 ファイル分の合成ソースを走査する短縮形。 */
+const scanOne = (source: string, file = "pages/Synthetic.svelte") => scanSources([{ file, source }]);
+
+const reasonsOf = (result: FileInputScanResult): string[] =>
+    result.diagnostics.map((d) => d.reason);
+
+// ---------------------------------------------------------------------------
+// (A) 走査器の負例 (診断になること)
+// ---------------------------------------------------------------------------
+
+describe("file input 走査器: 負例 (未解決の形は診断になる)", () => {
+    it("1. spread 属性は type/accept を上書きしうるので診断になる", () => {
+        const result = scanOne('<input type="file" accept="x" {...attrs} />');
+
+        expect(reasonsOf(result)).toEqual(["spread-attribute"]);
+        expect(result.fileInputs).toEqual([]);
+        expect(result.nativeInputCount).toBe(1);
+        expect(result.diagnostics[0].at).not.toBeNull();
+        expect(result.diagnostics[0].detail.length).toBeGreaterThan(0);
+    });
+
+    it("2. type が式のときは「非 file」と決めつけず診断になる", () => {
+        expect(reasonsOf(scanOne("<input type={kind} />"))).toEqual(["unresolved-type"]);
+    });
+
+    it("3. type の真偽短縮も診断になる", () => {
+        expect(reasonsOf(scanOne("<input type />"))).toEqual(["unresolved-type"]);
+    });
+
+    it("4. file input に accept が無ければ診断になる", () => {
+        expect(reasonsOf(scanOne('<input type="file" />'))).toEqual(["missing-accept"]);
+    });
+
+    it("5. type={\"file\"} は式を評価しないので診断になる", () => {
+        expect(reasonsOf(scanOne('<input type={"file"} accept="x" />'))).toEqual([
+            "unresolved-type",
+        ]);
+    });
+
+    it("6. type が無くても spread があれば診断になる", () => {
+        expect(reasonsOf(scanOne("<input {...attrs} />"))).toEqual(["spread-attribute"]);
+    });
+
+    it("7. accept の真偽短縮は診断になる", () => {
+        expect(reasonsOf(scanOne('<input type="file" accept />'))).toEqual(["unresolved-accept"]);
+    });
+
+    it("8. parse 失敗はファイル単位の診断で、位置を持たない", () => {
+        const result = scanOne("<div><span/>");
+
+        expect(reasonsOf(result)).toEqual(["parse-failed"]);
+        expect(result.diagnostics[0].at).toBeNull();
+        expect(result.fileInputs).toEqual([]);
+        expect(result.rawHtml).toEqual([]);
+        // ファイル単位なので序数の概念を持たない
+        expect(result.diagnostics[0]).not.toHaveProperty("occurrence");
+    });
+
+    it("16. <svelte:element this={tag}> は実行時に input になりうるので診断になる", () => {
+        expect(reasonsOf(scanOne("<svelte:element this={tag} />"))).toEqual([
+            "unresolved-native-element",
+        ]);
+    });
+
+    it("40. 複数パートの type は診断になる (静的に file と確定できない)", () => {
+        expect(reasonsOf(scanOne('<input type="fi{x}le" accept="y" />'))).toEqual([
+            "unresolved-type",
+        ]);
+    });
+
+    it("41. 綴りが同じ属性の重複は svelte の parse 自体が拒否する (parse-failed = fail-closed)", () => {
+        const result = scanOne('<input type="file" type="text" accept="y" />');
+
+        expect(reasonsOf(result)).toEqual(["parse-failed"]);
+        expect(result.fileInputs).toEqual([]);
+    });
+
+    /*
+     * **svelte の重複検査は大小文字を区別する**ため、大小文字違いの重複は parse を通る (実測)。
+     * 走査器側が小文字化して先頭だけを採ると、後続の属性を無言で捨てて
+     * 「実行時には file input なのに母集団外」になる (fail-open)。正規化後に複数件ある形は
+     * どちらが効くか確定できないので診断へ落とす。
+     */
+    it("44. 大小文字違いの type の重複は parse を通るので走査器が診断へ落とす", () => {
+        const result = scanOne('<input type="text" TYPE="file" accept="x" />');
+
+        expect(reasonsOf(result)).toEqual(["unresolved-type"]);
+        expect(result.fileInputs).toEqual([]);
+        expect(result.nativeInputCount).toBe(1);
+    });
+
+    it("45. 大小文字違いの accept の重複も診断へ落とす", () => {
+        const result = scanOne('<input type="file" accept="x" ACCEPT="y" />');
+
+        expect(reasonsOf(result)).toEqual(["unresolved-accept"]);
+        expect(result.fileInputs).toEqual([]);
+    });
+
+    it("46. 大小文字違いの重複は宣言順に関係なく診断になる", () => {
+        expect(reasonsOf(scanOne('<input TYPE="file" type="text" accept="x" />'))).toEqual([
+            "unresolved-type",
+        ]);
+    });
+});
+
+// ---------------------------------------------------------------------------
+// (A) 走査器の正例 (誤検出しないこと)
+// ---------------------------------------------------------------------------
+
+describe("file input 走査器: 正例 (規定どおりの入力を誤検出しない)", () => {
+    it("9. 非 file の input は母集団に入らない (native input としては数える)", () => {
+        const result = scanOne('<input type="text" /><input />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs).toEqual([]);
+        expect(result.nativeInputCount).toBe(2);
+    });
+
+    it("10. accept が式なら expression", () => {
+        const result = scanOne('<input type="file" accept={x} />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs).toEqual([
+            { file: "pages/Synthetic.svelte", occurrence: 1, syntax: "expression", literal: null },
+        ]);
+    });
+
+    it("11. accept の短縮記法 (実コードで使用中) も expression", () => {
+        const result = scanOne('<input type="file" {accept} />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("expression");
+        expect(result.fileInputs[0].literal).toBeNull();
+    });
+
+    it("12. 三項演算子 (実コードで使用中) も expression", () => {
+        const result = scanOne('<input type="file" accept={a ? "x" : "y"} />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("expression");
+    });
+
+    it("13. type=\"FILE\" も file 扱いで、静的テキストの accept は literal を記録する", () => {
+        const result = scanOne('<input type="FILE" accept="image/*" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("static-text");
+        expect(result.fileInputs[0].literal).toBe("image/*");
+    });
+
+    it("14. 複数パートの accept は expression", () => {
+        const result = scanOne('<input type="file" accept="a{b}c" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("expression");
+        expect(result.fileInputs[0].literal).toBeNull();
+    });
+
+    it("15. 同一ファイルの file input には出現順に序数が付く", () => {
+        const result = scanOne(
+            '<input type="file" accept="a" /><div><input type="file" accept={b} /></div>',
+        );
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs.map((r) => [r.occurrence, r.syntax])).toEqual([
+            [1, "static-text"],
+            [2, "expression"],
+        ]);
+    });
+
+    it("17. {@html …} は診断ではなく生 HTML の実測として記録される", () => {
+        const result = scanOne("{@html markup}");
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.rawHtml).toHaveLength(1);
+        expect(result.rawHtml[0].occurrence).toBe(1);
+        expect(result.rawHtml[0].at).not.toBeNull();
+    });
+
+    it("18. <svelte:element this=\"input\"> は file input として数える", () => {
+        const result = scanOne('<svelte:element this="input" type="file" accept={x} />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("expression");
+        expect(result.nativeInputCount).toBe(1);
+    });
+
+    it("19. 要素名の大文字小文字は無視する (this=\"INPUT\")", () => {
+        const result = scanOne('<svelte:element this="INPUT" type="file" accept="image/*" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("static-text");
+        expect(result.fileInputs[0].literal).toBe("image/*");
+    });
+
+    it("20. 静的に非 input と確定できる <svelte:element this=\"div\"> は母集団外", () => {
+        const result = scanOne('<svelte:element this="div" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.nativeInputCount).toBe(0);
+        expect(result.fileInputs).toEqual([]);
+    });
+
+    it("21. component は母集団外 (native input ではない)", () => {
+        const result = scanOne("<Foo /><svelte:component this={C} />");
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.nativeInputCount).toBe(0);
+        expect(result.fileInputs).toEqual([]);
+    });
+
+    it("21b. 同一ファイルの {@html} には出現順に序数が付く", () => {
+        const result = scanOne("{@html a}<div>{@html b}</div>");
+
+        expect(result.rawHtml.map((r) => r.occurrence)).toEqual([1, 2]);
+    });
+
+    /*
+     * native HTML の属性名は ASCII 大文字小文字を区別しない。属性名の照合を区別する実装だと
+     * `TYPE="file"` が「type 属性なし」として母集団から無言で外れ、accept の供給元宣言を
+     * 回避できる (fail-open)。要素名・type の値と同じ扱いに揃える。
+     */
+    it("42. 属性名の大文字小文字を無視する (TYPE / ACCEPT でも file input として数える)", () => {
+        const result = scanOne('<input TYPE="file" ACCEPT="image/*" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.nativeInputCount).toBe(1);
+        expect(result.fileInputs).toEqual([
+            {
+                file: "pages/Synthetic.svelte",
+                occurrence: 1,
+                syntax: "static-text",
+                literal: "image/*",
+            },
+        ]);
+    });
+
+    it("43. 属性名の大文字小文字を無視するので TYPE=\"file\" の accept 欠落も診断になる", () => {
+        expect(reasonsOf(scanOne('<input TYPE="file" />'))).toEqual(["missing-accept"]);
+    });
+
+    it("走査したファイル数を返す (走査根が生きていることの確認用)", () => {
+        const result = scanSources([
+            { file: "a.svelte", source: '<input type="file" accept="x" />' },
+            { file: "b.svelte", source: "<div />" },
+        ]);
+
+        expect(result.svelteFileCount).toBe(2);
+        expect(result.fileInputs.map((r) => r.file)).toEqual(["a.svelte"]);
+    });
+});
+
+// ---------------------------------------------------------------------------
+// (B) 判定関数の負例・正例
+// ---------------------------------------------------------------------------
+
+const RATIONALE = "サーバの単一の情報源から props で受け取るため、ここでは静的な値を持たない";
+
+function entry(overrides: Partial<FileInputAcceptEntry> = {}): FileInputAcceptEntry {
+    return {
+        file: "pages/A.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "server-prop",
+        rationale: RATIONALE,
+        ...overrides,
+    };
+}
+
+function scan(overrides: Partial<FileInputScanResult> = {}): FileInputScanResult {
+    return {
+        svelteFileCount: 3,
+        nativeInputCount: 2,
+        fileInputs: [
+            { file: "pages/A.svelte", occurrence: 1, syntax: "expression", literal: null },
+        ],
+        diagnostics: [],
+        rawHtml: [],
+        ...overrides,
+    };
+}
+
+function policy(overrides: Partial<FileInputPolicy> = {}): FileInputPolicy {
+    return {
+        inventory: [entry()],
+        countPin: 1,
+        rawHtmlExemptions: [],
+        rawHtmlExemptionCountPin: 0,
+        unresolvedFormExemptions: [],
+        unresolvedFormExemptionCountPin: 0,
+        ...overrides,
+    };
+}
+
+const rawHtmlRecord = (occurrence = 1) => ({
+    file: "pages/B.svelte",
+    occurrence,
+    at: { line: 1, column: 0 },
+});
+
+const rawHtmlExemption = (overrides: Partial<RawHtmlExemption> = {}): RawHtmlExemption => ({
+    file: "pages/B.svelte",
+    occurrence: 1,
+    rationale: "サーバが生成した SVG をそのまま描画する箇所で、ファイル入力を作らないため免除する",
+    ...overrides,
+});
+
+const unresolvedExemption = (
+    overrides: Partial<UnresolvedFormExemption> = {},
+): UnresolvedFormExemption => ({
+    file: "components/atoms/Input.svelte",
+    reason: "spread-attribute",
+    count: 1,
+    rationale: "汎用入力 atom は呼び出し側の属性をそのまま転送する設計で、accept の供給元を持たない",
+    ...overrides,
+});
+
+describe("判定関数: 正例", () => {
+    it("22. 適合する組は違反 0 件", () => {
+        expect(evaluateFileInputInventory(scan(), policy())).toEqual([]);
+    });
+
+    it("35. 生 HTML の実測が免除目録にあれば違反にならない", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
+        );
+
+        expect(violations).toEqual([]);
+    });
+});
+
+describe("判定関数: 負例 (目録の突き合わせ)", () => {
+    it("23. 目録に無い実測は未登録の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [], countPin: 0 }),
+        );
+
+        expect(violations.join("\n")).toContain("未登録");
+    });
+
+    it("24. 実測に無い目録は残置の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ fileInputs: [] }),
+            policy(),
+        );
+
+        expect(violations.join("\n")).toContain("残置");
+    });
+
+    it("25. syntax の宣言が実測と違えば違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [entry({ syntax: "static-text", supply: "client-owned" })] }),
+        );
+
+        expect(violations.join("\n")).toContain("syntax");
+    });
+
+    it("26. 目録キーの重複は違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [entry(), entry()], countPin: 2 }),
+        );
+
+        expect(violations.join("\n")).toContain("重複");
+    });
+
+    it("27. rationale が 29 文字なら違反 (supply が server-prop でも検査する)", () => {
+        const short = "あ".repeat(29);
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [entry({ rationale: short })] }),
+        );
+
+        expect(violations.join("\n")).toContain("30 文字");
+    });
+
+    it("28. occurrence が 0 なら違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [entry({ occurrence: 0 })] }),
+        );
+
+        expect(violations.join("\n")).toContain("occurrence");
+    });
+
+    it("29. 件数 pin が実測と 1 件ずれれば違反", () => {
+        const violations = evaluateFileInputInventory(scan(), policy({ countPin: 2 }));
+
+        expect(violations.join("\n")).toContain("件数");
+    });
+
+    it("34. server-prop と static-text の組み合わせは整合違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({
+                fileInputs: [
+                    { file: "pages/A.svelte", occurrence: 1, syntax: "static-text", literal: "x" },
+                ],
+            }),
+            policy({ inventory: [entry({ syntax: "static-text" })] }),
+        );
+
+        expect(violations.join("\n")).toContain("server-prop");
+    });
+});
+
+describe("判定関数: 負例 (母集団と診断)", () => {
+    it("30. 走査が空振りしていれば違反", () => {
+        const violations = evaluateFileInputInventory(scan({ svelteFileCount: 0 }), policy());
+
+        expect(violations.join("\n")).toContain("空振り");
+    });
+
+    it("31/32. 母集団が空の 2 条件は別の違反として返る", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ nativeInputCount: 0, fileInputs: [] }),
+            policy({ inventory: [], countPin: 0 }),
+        );
+
+        expect(violations.filter((v) => v.includes("native input"))).toHaveLength(1);
+        expect(violations.filter((v) => v.includes("file input"))).toHaveLength(1);
+        expect(violations).toHaveLength(2);
+    });
+
+    it("33. 免除目録に無い診断は違反になる (走査器が集めた診断を判定が無視しない)", () => {
+        const violations = evaluateFileInputInventory(
+            scan({
+                diagnostics: [
+                    {
+                        file: "pages/C.svelte",
+                        reason: "unresolved-type",
+                        at: { line: 3, column: 4 },
+                        detail: "type 属性が式である",
+                    },
+                ],
+            }),
+            policy(),
+        );
+
+        expect(violations.join("\n")).toContain("unresolved-type");
+    });
+});
+
+describe("判定関数: 負例 (生 HTML の免除目録)", () => {
+    it("36. 免除目録に無い生 HTML は未登録の違反", () => {
+        const violations = evaluateFileInputInventory(scan({ rawHtml: [rawHtmlRecord()] }), policy());
+
+        expect(violations.join("\n")).toContain("生 HTML");
+    });
+
+    it("37. 実測に無い免除は残置の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
+        );
+
+        expect(violations.join("\n")).toContain("残置");
+    });
+
+    it("38. 免除済みファイルに 2 件目の {@html} が増えたら未登録の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord(1), rawHtmlRecord(2)] }),
+            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
+        );
+
+        expect(violations.join("\n")).toContain("生 HTML");
+        expect(violations.join("\n")).toContain("occurrence=2");
+    });
+
+    it("39a. 免除の rationale が 29 文字なら違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({
+                rawHtmlExemptions: [rawHtmlExemption({ rationale: "あ".repeat(29) })],
+                rawHtmlExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("30 文字");
+    });
+
+    it("39b. 免除の occurrence が 0 なら違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({
+                rawHtmlExemptions: [rawHtmlExemption({ occurrence: 0 })],
+                rawHtmlExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("occurrence");
+    });
+
+    it("39c. 免除キーの重複は違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({
+                rawHtmlExemptions: [rawHtmlExemption(), rawHtmlExemption()],
+                rawHtmlExemptionCountPin: 2,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("重複");
+    });
+
+    it("39d. 免除の件数 pin が 1 件ずれれば違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 2 }),
+        );
+
+        expect(violations.join("\n")).toContain("件数");
+    });
+});
+
+/*
+ * 未解決の形の免除目録。
+ *
+ * **設計からの逸脱**: 詳細設計は「診断に免除の概念は無い (無条件で違反)」としていたが、
+ * その前提 (実リポジトリの診断が 0 件) は実測で成り立たなかった。汎用入力 atom
+ * (`components/atoms/Input.svelte`) は `{type}` と `{...rest}` を持ち、静的には file input に
+ * なりうる形が正当に実在する。無条件違反にすると gate が実装できないため、
+ * **免除できる理由を 1 つに限った上で** deny-by-default の免除目録で扱う。
+ *
+ * 鍵は `file` + `reason` + **件数の完全一致**である。「名指し」と呼べる精度ではなく、
+ * **同一ファイル・同一理由・同数の置き換えは検出しない** (最後の負のコントロールで
+ * その境界を機械 pin している)。未登録の未解決形と免除できない理由は違反である。
+ */
+describe("判定関数: 未解決の形の免除目録", () => {
+    const diagnostic = (file = "components/atoms/Input.svelte") =>
+        ({
+            file,
+            reason: "spread-attribute" as const,
+            at: { line: 1, column: 0 },
+            detail: "spread 属性が type/accept を上書きしうる",
+        });
+
+    it("免除目録に登録済みの未解決形は違反にならない (件数まで一致)", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ diagnostics: [diagnostic()] }),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations).toEqual([]);
+    });
+
+    it("免除済みファイルに 2 件目の未解決形が増えたら件数不一致で違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ diagnostics: [diagnostic(), diagnostic()] }),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("件数");
+    });
+
+    it("実測に無い未解決形の免除は残置の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("残置");
+    });
+
+    it("同じ reason でも別ファイルの未解決形は免除に一致しない", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ diagnostics: [diagnostic("pages/Other.svelte")] }),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("pages/Other.svelte");
+    });
+
+    /*
+     * 免除できる理由は狭い union (`ExemptibleDiagnosticReason`) に限る。
+     * 型でも塞いでいるが、目録は人が書くデータなので実行時にも拒否する
+     * (型は同一 PR 内でしか効かず、`as` で抜けられる)。
+     */
+    it.each<ScanDiagnosticReason>([
+        "parse-failed",
+        "missing-accept",
+        "unresolved-accept",
+        "unresolved-native-element",
+        "unresolved-type",
+    ])("免除できない理由 (%s) は目録へ登録しても違反のまま", (reason) => {
+        const violations = evaluateFileInputInventory(
+            scan({
+                diagnostics: [
+                    {
+                        file: "components/atoms/Input.svelte",
+                        reason,
+                        at: reason === "parse-failed" ? null : { line: 1, column: 0 },
+                        detail: "合成した診断",
+                    },
+                ],
+            }),
+            policy({
+                // 型では通らない登録を実行時にも拒否することを確かめる
+                unresolvedFormExemptions: [
+                    unresolvedExemption({ reason } as Partial<UnresolvedFormExemption>),
+                ],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain(reason);
+        expect(violations.join("\n")).toContain("免除できない");
+    });
+
+    /*
+     * **保証範囲の境界 (負のコントロール)**: 鍵は `file` + `reason` + 件数であり、
+     * 同一ファイル・同一理由・同数の**置き換え**は検出しない (docblock に明記した限界)。
+     * ここを厳しくする実装へ変えたら本テストが落ちて、docblock と AGENTS.md の
+     * 記述を直す契機になる。
+     */
+    it("保証範囲の境界: 同一ファイル・同一理由・同数の置き換えは検出しない", () => {
+        const moved = {
+            file: "components/atoms/Input.svelte",
+            reason: "spread-attribute" as const,
+            at: { line: 99, column: 8 }, // 位置が変わっても件数は 1 のまま
+            detail: "別の要素へ移った spread 属性",
+        };
+
+        const violations = evaluateFileInputInventory(
+            scan({ diagnostics: [moved] }),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations).toEqual([]);
+    });
+
+    it("免除の rationale が 29 文字 / count が 0 / キー重複 / 件数 pin ずれはそれぞれ違反", () => {
+        const base = scan({ diagnostics: [diagnostic()] });
+
+        expect(
+            evaluateFileInputInventory(
+                base,
+                policy({
+                    unresolvedFormExemptions: [unresolvedExemption({ rationale: "あ".repeat(29) })],
+                    unresolvedFormExemptionCountPin: 1,
+                }),
+            ).join("\n"),
+        ).toContain("30 文字");
+
+        expect(
+            evaluateFileInputInventory(
+                base,
+                policy({
+                    unresolvedFormExemptions: [unresolvedExemption({ count: 0 })],
+                    unresolvedFormExemptionCountPin: 1,
+                }),
+            ).join("\n"),
+        ).toContain("count");
+
+        expect(
+            evaluateFileInputInventory(
+                base,
+                policy({
+                    unresolvedFormExemptions: [unresolvedExemption(), unresolvedExemption()],
+                    unresolvedFormExemptionCountPin: 2,
+                }),
+            ).join("\n"),
+        ).toContain("重複");
+
+        expect(
+            evaluateFileInputInventory(
+                base,
+                policy({
+                    unresolvedFormExemptions: [unresolvedExemption()],
+                    unresolvedFormExemptionCountPin: 2,
+                }),
+            ).join("\n"),
+        ).toContain("件数");
+    });
+});
diff --git a/tests/js/support/file-input-accept-inventory.ts b/tests/js/support/file-input-accept-inventory.ts
new file mode 100644
index 00000000..9ab2042c
--- /dev/null
+++ b/tests/js/support/file-input-accept-inventory.ts
@@ -0,0 +1,432 @@
+import type {
+    FileInputScanResult,
+    ScanDiagnostic,
+    ScanDiagnosticReason,
+} from "./file-input-scan";
+
+/**
+ * file input の `accept` 供給元目録 (deny-by-default) と、その判定関数。
+ *
+ * # 軸を 2 つに分ける
+ *
+ * | 軸 | 値 | 誰が決めるか |
+ * |---|---|---|
+ * | 実測構文 (`syntax`) | `static-text` / `expression` | **走査器が AST から実測する** |
+ * | 供給元の宣言 (`supply`) | `server-prop` / `client-owned` | **人がレビューで宣言する** (理由必須) |
+ *
+ * `syntax` は機械が確かめられる事実である。`supply` は**設計意図の宣言であって由来の証明ではない**
+ * — `server-prop` と書いてあっても、この目録はその識別子がサーバの
+ * `AcceptedSourceDocumentTypes` 由来であることを検証しない。
+ *
+ * # 保証しないもの (誇張しない)
+ *
+ * - **由来の証明はしない**。`accept={sourceDocumentAccept}` の値が単一の情報源から来ている
+ *   ことは、Feature テスト (Controller の props) と component テスト (props の使い方) が担う。
+ * - **免除は人の宣言**である。生 HTML (`{@html …}`) の免除は「そこに file input を作らない」
+ *   という宣言で、中身を解析した結果ではない。未解決の形 (`diagnostics`) の免除も同じで、
+ *   「この形は accept の供給元を持たない」という宣言である。
+ * - 走査器側の限界 (`.svelte` 以外・実行時の書き換え・識別子の追跡) はそのまま引き継ぐ。
+ *   走査対象と走査器の保証範囲の正本は `./file-input-scan.ts` の docblock。
+ *
+ * 検出力の裏取りは `tests/js/architecture/file-input-scan.test.ts` (負例・正例の両方向)。
+ */
+
+/** 供給元の宣言。**人が宣言する設計意図**であり、gate は由来を検証しない。 */
+export type AcceptSupply = "server-prop" | "client-owned";
+
+export interface FileInputAcceptEntry {
+    readonly file: string;
+    /** ファイル内の 1 始まりの序数 (正の整数)。 */
+    readonly occurrence: number;
+    /** 実測と一致していなければ違反。 */
+    readonly syntax: "static-text" | "expression";
+    readonly supply: AcceptSupply;
+    /** 30 文字以上 (supply の値に関わらず全エントリ)。 */
+    readonly rationale: string;
+}
+
+/**
+ * 現在の実測ちょうど。**新しいアップロード面を足したら 1 行足し、件数 pin も 1 増やす**。
+ *
+ * 現在 4 件すべてが `expression` である。`static-text` は 0 件だが区分値としては必要で、
+ * `accept="image/*"` と直書きする面が将来増えたときに `expression` から `static-text` へ
+ * 変わって赤くなり、供給元の宣言を見直す契機になる (0 件の区分が正しく動くことは
+ * 自己検査の合成入力が担保する)。
+ */
+export const FILE_INPUT_ACCEPT_INVENTORY: readonly FileInputAcceptEntry[] = [
+    {
+        file: "components/features/manual/SourceDocumentUpload.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "server-prop",
+        rationale:
+            "SOP の受理形式はサーバの AcceptedSourceDocumentTypes が単一の情報源で、Inertia props 経由で受け取る",
+    },
+    {
+        file: "pages/Manuals/Create.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "server-prop",
+        rationale:
+            "作成と同時の SOP アップロードも同じ単一の情報源から props で受け取る (経路ごとに直書きしない)",
+    },
+    {
+        file: "components/features/capture/CaptureFileFallback.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "client-owned",
+        rationale:
+            "撮影テイクの入力は静止画 image/* と動画 video/* の 2 択で、SOP の受理形式とは別概念のためクライアント側で決める",
+    },
+    {
+        file: "components/features/manual/TakeFileUpload.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "client-owned",
+        rationale:
+            "テイクの後付けアップロードも静止画・動画の 2 択で、サーバの SOP 受理形式とは無関係のためクライアント側で決める",
+    },
+] as const;
+
+/** 件数の pin。実測件数・目録配列長・一意キー数の 3 つと一致させる。 */
+export const FILE_INPUT_COUNT = 4;
+
+/** `{@html …}` を持つことを許すファイルの名指し目録 (deny-by-default)。 */
+export interface RawHtmlExemption {
+    readonly file: string;
+    /** ファイル内の `{@html}` の 1 始まりの序数 (正の整数)。 */
+    readonly occurrence: number;
+    /** 30 文字以上。 */
+    readonly rationale: string;
+}
+
+export const RAW_HTML_EXEMPTIONS: readonly RawHtmlExemption[] = [
+    {
+        file: "pages/Settings/Security.svelte",
+        occurrence: 1,
+        rationale:
+            "2FA の QR コードはサーバが生成した SVG をそのまま描画する箇所で、ファイル入力を作らない",
+    },
+] as const;
+
+/** 免除の件数の pin (増減のどちらでも赤くする)。 */
+export const RAW_HTML_EXEMPTION_COUNT = 1;
+
+/**
+ * 免除の登録を許す診断の理由。**現在 1 つだけ**である。
+ *
+ * `spread-attribute` だけを許すのは、汎用入力 atom が呼び出し側の属性をそのまま転送する
+ * 設計が正当に実在するためである (実測で 1 件)。それ以外の理由は免除できない:
+ *
+ * - `parse-failed`: 解析できていない状態で緑にできてしまう (走査そのものの故障)
+ * - `missing-accept`: 未解決ではなく、file input と確定した上で accept が無い明白な違反
+ * - `unresolved-accept` / `unresolved-type` / `unresolved-native-element`:
+ *   実装を直せば解消できる形であり、免除の受け皿を先回りして用意しない
+ *
+ * 理由を増やすときは、その形が本当に直せないことを示した上でこの配列を広げる
+ * (広げる操作そのものがレビューに見える)。
+ *
+ * 型と実行時の集合は**この 1 つの配列から導出する** (二重定義にすると片方だけ広げられる)。
+ * 実行時にも検査するのは、目録が人の書くデータで `as` で型を抜けた登録もありうるため。
+ */
+export const EXEMPTIBLE_DIAGNOSTIC_REASONS = ["spread-attribute"] as const satisfies readonly ScanDiagnosticReason[];
+
+export type ExemptibleDiagnosticReason = (typeof EXEMPTIBLE_DIAGNOSTIC_REASONS)[number];
+
+/**
+ * 未解決の形 (`diagnostics`) の免除目録 (deny-by-default)。
+ *
+ * **詳細設計からの逸脱**: 詳細設計は「診断に免除の概念は無い (無条件で違反)」としていたが、
+ * その前提 (実リポジトリの診断が 0 件) は実測で成り立たなかった。汎用入力 atom は
+ * `type={…}` と `{...rest}` を持ち、静的には file input になりうる形が正当に実在する。
+ * 無条件違反にすると gate そのものが実装できないため、**免除できる理由を 1 つに限った上で**
+ * 件数の完全一致つきの免除目録で扱う。未登録の未解決形は依然として違反であり、
+ * 無言で候補から外す経路は作っていない。
+ *
+ * 鍵は `file` + `reason` で、`count` は**その組の実測件数ちょうど**である
+ * (同じファイルに 2 件目が増えれば件数不一致で赤くなる)。
+ *
+ * **保証しないもの (誇張しない)**: 鍵はファイル単位なので、**同一ファイル・同一理由・
+ * 同数の置き換え** (既存の 1 件を消して同じファイルの別の場所へ 1 件足す) は検出しない。
+ * 位置を鍵に含めれば検出できるが、無関係な編集で行がずれるたびに赤くなり
+ * 「赤くなったら目録を緩める」習慣を作るため採らない。新しいアップロード面は
+ * 別ファイル・別理由・件数増のいずれかになるので、そこは検出できる。
+ * この限界は `file-input-scan.test.ts` の負のコントロールが機械で pin している
+ * (厳しくする実装へ変えたらそのテストが落ちて、本 docblock を直す契機になる)。
+ */
+export interface UnresolvedFormExemption {
+    readonly file: string;
+    readonly reason: ExemptibleDiagnosticReason;
+    /** その file + reason の実測件数ちょうど (正の整数)。 */
+    readonly count: number;
+    /** 30 文字以上。 */
+    readonly rationale: string;
+}
+
+export const UNRESOLVED_FORM_EXEMPTIONS: readonly UnresolvedFormExemption[] = [
+    {
+        file: "components/atoms/Input.svelte",
+        reason: "spread-attribute",
+        count: 1,
+        rationale:
+            "汎用入力 atom は type も残りの属性も呼び出し側から受けて転送する設計で、accept の供給元を自分では持たない",
+    },
+] as const;
+
+/** 未解決の形の免除の件数の pin (増減のどちらでも赤くする)。 */
+export const UNRESOLVED_FORM_EXEMPTION_COUNT = 1;
+
+/** 判定関数へ渡す目録一式 (引数の取り違えを型で防ぐためオブジェクトで受ける)。 */
+export interface FileInputPolicy {
+    readonly inventory: readonly FileInputAcceptEntry[];
+    readonly countPin: number;
+    readonly rawHtmlExemptions: readonly RawHtmlExemption[];
+    readonly rawHtmlExemptionCountPin: number;
+    readonly unresolvedFormExemptions: readonly UnresolvedFormExemption[];
+    readonly unresolvedFormExemptionCountPin: number;
+}
+
+const MIN_RATIONALE_LENGTH = 30;
+
+/** 免除の登録を許す理由かどうか (実行時の検査。型を抜けた登録も止める)。 */
+const isExemptibleReason = (reason: ScanDiagnosticReason): boolean =>
+    (EXEMPTIBLE_DIAGNOSTIC_REASONS as readonly ScanDiagnosticReason[]).includes(reason);
+
+const isPositiveInteger = (value: number): boolean => Number.isInteger(value) && value > 0;
+
+const keyOf = (file: string, occurrence: number): string => `${file}#${occurrence}`;
+
+/** 重複しているキーを列挙する。 */
+function duplicatedKeys(keys: readonly string[]): string[] {
+    const seen = new Set<string>();
+    const duplicates = new Set<string>();
+    for (const key of keys) {
+        if (seen.has(key)) duplicates.add(key);
+        seen.add(key);
+    }
+
+    return [...duplicates];
+}
+
+/**
+ * gate の判定本体 (純関数)。**判定はすべてこの 1 関数へ集約する** —
+ * 母集団非空や診断の扱いを gate 側の assert へ散らすと、その分岐に負例が付かず
+ * 「走査器は診断を集めたのに gate が無視する」実装ミスを自己検査できなくなる。
+ *
+ * @returns 違反の説明文の配列 (空 = 適合)
+ */
+export function evaluateFileInputInventory(
+    scan: FileInputScanResult,
+    policy: FileInputPolicy,
+): readonly string[] {
+    const violations: string[] = [];
+
+    // --- 走査が生きているか / 母集団が空でないか ---
+    if (scan.svelteFileCount === 0) {
+        violations.push("走査が空振りしている: .svelte が 1 件も見つからない (走査根を確認)");
+    }
+    if (scan.nativeInputCount === 0) {
+        violations.push("母集団が空: native input が 0 件 (走査器の要素判定が壊れている疑い)");
+    }
+    if (scan.fileInputs.length === 0) {
+        violations.push("母集団が空: file input が 0 件 (走査器の type 判定が壊れている疑い)");
+    }
+
+    // --- 未解決の形 (診断) ---
+    // 免除できない理由は突き合わせに入れず、**無条件で違反**にする
+    // (parse 失敗や accept 欠落を免除の受け皿へ通さない = fail-closed の中核)
+    const exemptibleDiagnostics: ScanDiagnostic[] = [];
+    for (const diagnostic of scan.diagnostics) {
+        if (isExemptibleReason(diagnostic.reason)) {
+            exemptibleDiagnostics.push(diagnostic);
+
+            continue;
+        }
+        const where = diagnostic.at ? ` (${diagnostic.at.line}:${diagnostic.at.column})` : "";
+        violations.push(
+            `免除できない未解決の形: ${diagnostic.file} (${diagnostic.reason})${where} — ` +
+                `${diagnostic.detail}。実装を直して解消してください`,
+        );
+    }
+
+    // 免除できる理由だけを免除目録と両方向で突き合わせる
+    const diagnosticCounts = new Map<string, number>();
+    for (const diagnostic of exemptibleDiagnostics) {
+        const key = `${diagnostic.file}#${diagnostic.reason}`;
+        diagnosticCounts.set(key, (diagnosticCounts.get(key) ?? 0) + 1);
+    }
+    const unresolvedByKey = new Map<string, UnresolvedFormExemption>();
+    for (const exemption of policy.unresolvedFormExemptions) {
+        unresolvedByKey.set(`${exemption.file}#${exemption.reason}`, exemption);
+        if (!isExemptibleReason(exemption.reason)) {
+            violations.push(
+                `免除できない理由が免除目録に登録されている: ${exemption.file} (${exemption.reason})。` +
+                    `登録できるのは ${EXEMPTIBLE_DIAGNOSTIC_REASONS.join(" / ")} だけです`,
+            );
+        }
+        if (!isPositiveInteger(exemption.count)) {
+            violations.push(
+                `未解決の形の免除の count が正の整数でない: ${exemption.file} (${exemption.reason}) count=${exemption.count}`,
+            );
+        }
+        if (exemption.rationale.length < MIN_RATIONALE_LENGTH) {
+            violations.push(
+                `未解決の形の免除の理由が 30 文字未満: ${exemption.file} (${exemption.reason})`,
+            );
+        }
+    }
+    for (const key of duplicatedKeys(
+        policy.unresolvedFormExemptions.map((e) => `${e.file}#${e.reason}`),
+    )) {
+        violations.push(`未解決の形の免除キーが重複している: ${key}`);
+    }
+    for (const [key, count] of diagnosticCounts) {
+        const exemption = unresolvedByKey.get(key);
+        const sample = exemptibleDiagnostics.find((d) => `${d.file}#${d.reason}` === key);
+        const where = sample?.at ? ` (${sample.at.line}:${sample.at.column})` : "";
+        if (!exemption) {
+            violations.push(
+                `未登録の未解決の形: ${key}${where} — ${sample?.detail ?? ""}。` +
+                    "解消するか UNRESOLVED_FORM_EXEMPTIONS へ理由付きで登録してください",
+            );
+
+            continue;
+        }
+        if (exemption.count !== count) {
+            violations.push(
+                `未解決の形の免除の件数が実測と一致しない: ${key} 実測=${count} 免除=${exemption.count}`,
+            );
+        }
+    }
+    for (const key of unresolvedByKey.keys()) {
+        if (!diagnosticCounts.has(key)) {
+            violations.push(`未解決の形の免除が残置されている (実測に無い): ${key}`);
+        }
+    }
+    if (policy.unresolvedFormExemptions.length !== policy.unresolvedFormExemptionCountPin) {
+        violations.push(
+            `未解決の形の免除の件数 pin が配列長と一致しない: pin=${policy.unresolvedFormExemptionCountPin} 配列長=${policy.unresolvedFormExemptions.length}`,
+        );
+    }
+    if (unresolvedByKey.size !== policy.unresolvedFormExemptionCountPin) {
+        violations.push(
+            `未解決の形の免除の件数 pin が一意キー数と一致しない: pin=${policy.unresolvedFormExemptionCountPin} 一意キー数=${unresolvedByKey.size}`,
+        );
+    }
+
+    // --- file input の目録を両方向で突き合わせる ---
+    const inventoryByKey = new Map<string, FileInputAcceptEntry>();
+    for (const entry of policy.inventory) {
+        inventoryByKey.set(keyOf(entry.file, entry.occurrence), entry);
+        if (!isPositiveInteger(entry.occurrence)) {
+            violations.push(
+                `目録の occurrence が正の整数でない: ${entry.file} occurrence=${entry.occurrence}`,
+            );
+        }
+        if (entry.rationale.length < MIN_RATIONALE_LENGTH) {
+            violations.push(`目録の理由が 30 文字未満: ${keyOf(entry.file, entry.occurrence)}`);
+        }
+        if (entry.supply === "server-prop" && entry.syntax !== "expression") {
+            violations.push(
+                `server-prop の宣言は syntax=expression のときだけ許す (静的テキストをサーバ由来と宣言している): ${keyOf(entry.file, entry.occurrence)}`,
+            );
+        }
+    }
+    for (const key of duplicatedKeys(policy.inventory.map((e) => keyOf(e.file, e.occurrence)))) {
+        violations.push(`目録キーが重複している: ${key}`);
+    }
+    const measuredKeys = new Set<string>();
+    for (const record of scan.fileInputs) {
+        const key = keyOf(record.file, record.occurrence);
+        measuredKeys.add(key);
+        const entry = inventoryByKey.get(key);
+        if (!entry) {
+            violations.push(
+                `未登録の file input: ${key} (実測 syntax=${record.syntax})。` +
+                    "受理形式の供給元を判断して FILE_INPUT_ACCEPT_INVENTORY へ登録してください",
+            );
+
+            continue;
+        }
+        if (entry.syntax !== record.syntax) {
+            violations.push(
+                `syntax の宣言が実測と違う: ${key} 実測=${record.syntax} 宣言=${entry.syntax}`,
+            );
+        }
+    }
+    for (const key of inventoryByKey.keys()) {
+        if (!measuredKeys.has(key)) {
+            violations.push(`目録が残置されている (実測に無い): ${key}`);
+        }
+    }
+    if (scan.fileInputs.length !== policy.countPin) {
+        violations.push(
+            `file input の件数 pin が実測と一致しない: pin=${policy.countPin} 実測=${scan.fileInputs.length}`,
+        );
+    }
+    if (policy.inventory.length !== policy.countPin) {
+        violations.push(
+            `file input の件数 pin が目録配列長と一致しない: pin=${policy.countPin} 配列長=${policy.inventory.length}`,
+        );
+    }
+    if (inventoryByKey.size !== policy.countPin) {
+        violations.push(
+            `file input の件数 pin が一意キー数と一致しない: pin=${policy.countPin} 一意キー数=${inventoryByKey.size}`,
+        );
+    }
+
+    // --- 生 HTML を免除目録と両方向で突き合わせる ---
+    const rawHtmlExemptionByKey = new Map<string, RawHtmlExemption>();
+    for (const exemption of policy.rawHtmlExemptions) {
+        rawHtmlExemptionByKey.set(keyOf(exemption.file, exemption.occurrence), exemption);
+        if (!isPositiveInteger(exemption.occurrence)) {
+            violations.push(
+                `生 HTML の免除の occurrence が正の整数でない: ${exemption.file} occurrence=${exemption.occurrence}`,
+            );
+        }
+        if (exemption.rationale.length < MIN_RATIONALE_LENGTH) {
+            violations.push(
+                `生 HTML の免除の理由が 30 文字未満: ${keyOf(exemption.file, exemption.occurrence)}`,
+            );
+        }
+    }
+    for (const key of duplicatedKeys(
+        policy.rawHtmlExemptions.map((e) => keyOf(e.file, e.occurrence)),
+    )) {
+        violations.push(`生 HTML の免除キーが重複している: ${key}`);
+    }
+    const measuredRawHtmlKeys = new Set<string>();
+    for (const record of scan.rawHtml) {
+        const key = keyOf(record.file, record.occurrence);
+        measuredRawHtmlKeys.add(key);
+        if (!rawHtmlExemptionByKey.has(key)) {
+            violations.push(
+                `未登録の生 HTML ({@html}): ${record.file} occurrence=${record.occurrence} ` +
+                    `(${record.at.line}:${record.at.column})。` +
+                    "そこに file input を作らないことを確認して RAW_HTML_EXEMPTIONS へ登録してください",
+            );
+        }
+    }
+    for (const key of rawHtmlExemptionByKey.keys()) {
+        if (!measuredRawHtmlKeys.has(key)) {
+            violations.push(`生 HTML の免除が残置されている (実測に無い): ${key}`);
+        }
+    }
+    if (scan.rawHtml.length !== policy.rawHtmlExemptionCountPin) {
+        violations.push(
+            `生 HTML の件数 pin が実測と一致しない: pin=${policy.rawHtmlExemptionCountPin} 実測=${scan.rawHtml.length}`,
+        );
+    }
+    if (policy.rawHtmlExemptions.length !== policy.rawHtmlExemptionCountPin) {
+        violations.push(
+            `生 HTML の件数 pin が免除配列長と一致しない: pin=${policy.rawHtmlExemptionCountPin} 配列長=${policy.rawHtmlExemptions.length}`,
+        );
+    }
+    if (rawHtmlExemptionByKey.size !== policy.rawHtmlExemptionCountPin) {
+        violations.push(
+            `生 HTML の件数 pin が一意キー数と一致しない: pin=${policy.rawHtmlExemptionCountPin} 一意キー数=${rawHtmlExemptionByKey.size}`,
+        );
+    }
+
+    return violations;
+}
diff --git a/tests/js/support/file-input-scan.ts b/tests/js/support/file-input-scan.ts
new file mode 100644
index 00000000..0f5fc8d0
--- /dev/null
+++ b/tests/js/support/file-input-scan.ts
@@ -0,0 +1,408 @@
+import fs from "fs/promises";
+import path from "path";
+import { parse } from "svelte/compiler";
+
+/**
+ * `.svelte` から native な file input と、その `accept` 属性の**実測**を集める走査器。
+ *
+ * # 走査対象
+ *
+ * - `scanFileInputs(root)`: `root` 配下 (再帰) の拡張子 `.svelte` のファイル全数。
+ * - `scanSources(sources)`: 与えられた合成ソース全数 (自己検査用。実ファイルに依存しない)。
+ *
+ * 母集団は「native `input` を作りうる形の全数」で、AST 上の扱いは次のとおり
+ * (svelte 5.56 で実測した形に基づく):
+ *
+ * | AST 上の形 | 扱い |
+ * |---|---|
+ * | `RegularElement` / name が `input` (大文字小文字を無視) | 母集団 (`type` 判定へ) |
+ * | `RegularElement` / name が `input` 以外 | 対象外 |
+ * | `SvelteElement` / `tag` が文字列 `Literal` で値が `input` (同上) | 母集団 |
+ * | `SvelteElement` / `tag` が文字列 `Literal` で値が `input` 以外 | 対象外 (静的に非 input と確定) |
+ * | `SvelteElement` / `tag` が `Literal` 以外、または非文字列 `Literal` | 診断 `unresolved-native-element` |
+ * | `HtmlTag` (`{@html …}`) | `rawHtml` として実測 (診断ではない。免除目録と突き合わせる) |
+ * | component (`<Foo />` / `<svelte:component>`) | 対象外 |
+ *
+ * `type` / `accept` の実測は「静的テキストだけで確定できるか」で分け、確定できない形は
+ * すべて診断にする (**未解決を無言で候補から外さない** = fail-closed)。
+ *
+ * # 保証しないもの (誇張しない)
+ *
+ * - **`.svelte` 以外**には効かない。TS から `document.createElement('input')` する経路、
+ *   Blade テンプレート、実行時に `accept` を書き換える形は見えない。
+ * - **識別子の値の由来は追跡しない**。`accept={x}` を見ても `x` がサーバ由来かは分からない
+ *   (Inertia props は実行時に注入されるため静的検査の到達範囲外)。
+ * - **`{@html …}` に渡される文字列の中身は解析しない**。生 HTML の中に file input を
+ *   書けるかどうかは分からないため、免除目録の登録は「そこに file input を作らない」という
+ *   人の宣言であり、走査器が確かめた結果ではない。
+ * - `occurrence` (序数) は**出現順**であって意味の追跡ではない。並べ替えると値がずれるが、
+ *   ずれれば赤くなる (安全側)。
+ * - 属性の重複は 2 通りに分かれる。**綴りが同じ重複**は svelte の parse が拒否するので
+ *   `parse-failed` として現れる。**大小文字違いの重複**は parse を通るので
+ *   (svelte の重複検査は大小文字を区別する) 走査器が `unresolved-type` /
+ *   `unresolved-accept` へ落とす。どちらも母集団からは外れない (fail-closed)。
+ * - `svelte/compiler` の AST 形状は major 更新で変わりうる。変われば自己検査
+ *   (`tests/js/architecture/file-input-scan.test.ts`) の合成入力が最初に落ちる
+ *   (無言で緑にはならない)。
+ *
+ * 検出力の裏取り (負例・正例の両方向) は `tests/js/architecture/file-input-scan.test.ts`。
+ */
+
+/** 走査に渡す 1 ファイル分のソース。 */
+export interface SvelteSource {
+    /** 走査根からの相対パス (POSIX 区切り)。目録の鍵になる。 */
+    readonly file: string;
+    readonly source: string;
+}
+
+/** 実測できた file input の 1 件。`occurrence` はファイル内の 1 始まりの序数。 */
+export interface FileInputRecord {
+    readonly file: string;
+    readonly occurrence: number;
+    readonly syntax: "static-text" | "expression";
+    /** `static-text` のときだけ値。`expression` は null。 */
+    readonly literal: string | null;
+}
+
+export type ScanDiagnosticReason =
+    /** ファイル単位。parse そのものが失敗した。 */
+    | "parse-failed"
+    /** `type` が式・真偽短縮・複数パートで、file かどうか確定できない。 */
+    | "unresolved-type"
+    /** 同一要素の spread 属性が `type` / `accept` を上書きしうる。 */
+    | "spread-attribute"
+    /** file input なのに `accept` が無い。 */
+    | "missing-accept"
+    /** `accept` が真偽短縮などで値を確定できない。 */
+    | "unresolved-accept"
+    /** `<svelte:element this={…}>` が実行時に input になりうる。 */
+    | "unresolved-native-element";
+
+/** ソース上の位置 (行は 1 始まり、列は 0 始まり)。 */
+export interface SourcePosition {
+    readonly line: number;
+    readonly column: number;
+}
+
+/**
+ * 生 HTML の描画 (`{@html …}`) の実測 1 件。**診断とは別の集合**である
+ * (突き合わせる免除目録が別で、鍵も別: 生 HTML は `file` + `occurrence`、
+ * 診断は `file` + `reason` + 件数)。
+ */
+export interface RawHtmlRecord {
+    readonly file: string;
+    readonly occurrence: number;
+    readonly at: SourcePosition;
+}
+
+export interface ScanDiagnostic {
+    readonly file: string;
+    readonly reason: ScanDiagnosticReason;
+    /** `parse-failed` は null (ファイル単位のため位置を持たない)。 */
+    readonly at: SourcePosition | null;
+    readonly detail: string;
+}
+
+export interface FileInputScanResult {
+    /** 走査したファイル数 (走査根が生きていることの確認用)。 */
+    readonly svelteFileCount: number;
+    /** native input 要素の全数 (母集団非空 その 1)。 */
+    readonly nativeInputCount: number;
+    /** 静的に file と確定し accept を実測できた input (母集団非空 その 2)。 */
+    readonly fileInputs: readonly FileInputRecord[];
+    /**
+     * 未解決の形。判定側の既定は**無条件で違反**で、免除目録と突き合わせるのは
+     * 免除できる理由に限られる (現在は `spread-attribute` だけ。正本は
+     * `./file-input-accept-inventory.ts` の `ExemptibleDiagnosticReason`)。
+     */
+    readonly diagnostics: readonly ScanDiagnostic[];
+    /** 生 HTML の実測。判定側で免除目録と両方向で突き合わせる。 */
+    readonly rawHtml: readonly RawHtmlRecord[];
+}
+
+/** AST ノードの最低限の形 (走査器が触る範囲だけを型で表す)。 */
+interface AstNode {
+    readonly type: string;
+    readonly start?: number;
+    readonly name?: string;
+    readonly attributes?: readonly AstNode[];
+    readonly tag?: { readonly type: string; readonly value?: unknown };
+    readonly value?: unknown;
+    readonly data?: string;
+    readonly [key: string]: unknown;
+}
+
+const isAstNode = (value: unknown): value is AstNode =>
+    typeof value === "object" && value !== null && typeof (value as { type?: unknown }).type === "string";
+
+/** バイト offset を 1 始まりの行 / 0 始まりの列へ変換する。 */
+function positionAt(source: string, offset: number): SourcePosition {
+    const before = source.slice(0, offset);
+    const lineBreaks = before.split("\n");
+
+    return { line: lineBreaks.length, column: lineBreaks[lineBreaks.length - 1].length };
+}
+
+/** ノードを再帰的に列挙する (テンプレートと式の区別をせず全走査し、type で振り分ける)。 */
+function eachNode(value: unknown, visit: (node: AstNode) => void): void {
+    if (Array.isArray(value)) {
+        for (const item of value) eachNode(item, visit);
+
+        return;
+    }
+    if (typeof value !== "object" || value === null) return;
+    if (isAstNode(value)) visit(value);
+    for (const [key, child] of Object.entries(value)) {
+        // 位置情報と親参照は走査しない (循環と無駄打ちの回避)
+        if (key === "type" || key === "parent" || key === "loc" || key === "name_loc") continue;
+        eachNode(child, visit);
+    }
+}
+
+/** 属性値を「静的テキストだけで確定できるか」で分類する。 */
+type AttributeValue =
+    | { readonly kind: "static"; readonly text: string }
+    | { readonly kind: "expression" }
+    | { readonly kind: "unresolved"; readonly detail: string };
+
+function classifyAttributeValue(value: unknown): AttributeValue {
+    // 短縮の真偽属性 (`<input type />`) は値を持たない
+    if (value === true) return { kind: "unresolved", detail: "属性が真偽短縮で値を持たない" };
+
+    const parts = Array.isArray(value) ? value : [value];
+    const nodes: AstNode[] = [];
+    for (const part of parts) {
+        if (!isAstNode(part)) {
+            return { kind: "unresolved", detail: "属性値の AST を解決できない" };
+        }
+        nodes.push(part);
+    }
+    if (nodes.every((node) => node.type === "Text")) {
+        return { kind: "static", text: nodes.map((node) => node.data ?? "").join("") };
+    }
+    if (nodes.some((node) => node.type === "ExpressionTag")) return { kind: "expression" };
+
+    return { kind: "unresolved", detail: `属性値に未知のノード (${nodes.map((n) => n.type).join(",")})` };
+}
+
+/**
+ * 名前付き属性を集める。**native HTML の属性名は ASCII 大文字小文字を区別しない**ため、
+ * 要素名・`type` の値と同じく小文字化して照合する (区別すると `TYPE="file"` が
+ * 「type 属性なし」として母集団から無言で外れる = fail-open)。
+ *
+ * 綴りが同じ重複 (`type="file" type="text"`) は svelte の parse 自体が拒否するが、
+ * **svelte の重複検査は大小文字を区別する**ため大小文字違いの重複 (`type` と `TYPE`) は
+ * parse を通る (実測)。そのため**複数件返りうる**。先頭だけ採って後続を捨てると
+ * fail-open になるので、複数件は呼び出し側が診断へ落とす。
+ */
+function attributesNamed(node: AstNode, name: string): AstNode[] {
+    return (node.attributes ?? []).filter(
+        (attr) => attr.type === "Attribute" && (attr.name ?? "").toLowerCase() === name,
+    ) as AstNode[];
+}
+
+const ELEMENT_NAME_INPUT = "input";
+
+/** 1 ファイルを走査した中間結果 (序数は付与前)。 */
+interface FileScan {
+    readonly nativeInputCount: number;
+    readonly fileInputs: readonly { readonly start: number; readonly syntax: "static-text" | "expression"; readonly literal: string | null }[];
+    readonly diagnostics: readonly ScanDiagnostic[];
+    readonly rawHtml: readonly { readonly start: number }[];
+}
+
+function scanOneSource({ file, source }: SvelteSource): FileScan {
+    let ast: { fragment: unknown };
+    try {
+        ast = parse(source, { modern: true });
+    } catch (error) {
+        return {
+            nativeInputCount: 0,
+            fileInputs: [],
+            diagnostics: [
+                {
+                    file,
+                    reason: "parse-failed",
+                    at: null,
+                    detail: error instanceof Error ? error.message : String(error),
+                },
+            ],
+            rawHtml: [],
+        };
+    }
+
+    let nativeInputCount = 0;
+    const fileInputs: { start: number; syntax: "static-text" | "expression"; literal: string | null }[] = [];
+    const diagnostics: ScanDiagnostic[] = [];
+    const rawHtml: { start: number }[] = [];
+
+    const diagnose = (reason: ScanDiagnosticReason, start: number, detail: string): void => {
+        diagnostics.push({ file, reason, at: positionAt(source, start), detail });
+    };
+
+    eachNode(ast.fragment, (node) => {
+        const start = node.start ?? 0;
+
+        if (node.type === "HtmlTag") {
+            rawHtml.push({ start });
+
+            return;
+        }
+
+        // --- 要素の側: native input を作りうる形を確定する ---
+        if (node.type === "RegularElement") {
+            if ((node.name ?? "").toLowerCase() !== ELEMENT_NAME_INPUT) return;
+        } else if (node.type === "SvelteElement") {
+            const tag = node.tag;
+            if (!tag || tag.type !== "Literal" || typeof tag.value !== "string") {
+                diagnose(
+                    "unresolved-native-element",
+                    start,
+                    "<svelte:element this={…}> の要素名を静的に確定できない (実行時に input になりうる)",
+                );
+
+                return;
+            }
+            if (tag.value.toLowerCase() !== ELEMENT_NAME_INPUT) return;
+        } else {
+            return;
+        }
+
+        nativeInputCount++;
+
+        // --- spread は type / accept を上書きしうるので、他の判定より先に落とす ---
+        if ((node.attributes ?? []).some((attr) => attr.type === "SpreadAttribute")) {
+            diagnose("spread-attribute", start, "spread 属性が type / accept を上書きしうる");
+
+            return;
+        }
+
+        // --- type の側 ---
+        const typeAttributes = attributesNamed(node, "type");
+        // 属性が無い = HTML 既定の text なので母集団外
+        if (typeAttributes.length === 0) return;
+        if (typeAttributes.length > 1) {
+            diagnose(
+                "unresolved-type",
+                start,
+                "type 属性が (大文字小文字を無視して) 複数あり、どれが効くか確定できない",
+            );
+
+            return;
+        }
+        const typeValue = classifyAttributeValue(typeAttributes[0].value);
+        if (typeValue.kind !== "static") {
+            diagnose(
+                "unresolved-type",
+                start,
+                typeValue.kind === "expression"
+                    ? "type 属性が式で、file かどうか確定できない"
+                    : typeValue.detail,
+            );
+
+            return;
+        }
+        if (typeValue.text.toLowerCase() !== "file") return;
+
+        // --- accept の側 (ここに来たものだけが母集団) ---
+        const acceptAttributes = attributesNamed(node, "accept");
+        if (acceptAttributes.length === 0) {
+            diagnose("missing-accept", start, "file input に accept 属性が無い");
+
+            return;
+        }
+        if (acceptAttributes.length > 1) {
+            diagnose(
+                "unresolved-accept",
+                start,
+                "accept 属性が (大文字小文字を無視して) 複数あり、どれが効くか確定できない",
+            );
+
+            return;
+        }
+        const acceptValue = classifyAttributeValue(acceptAttributes[0].value);
+        if (acceptValue.kind === "unresolved") {
+            diagnose("unresolved-accept", start, acceptValue.detail);
+
+            return;
+        }
+        fileInputs.push(
+            acceptValue.kind === "static"
+                ? { start, syntax: "static-text", literal: acceptValue.text }
+                : { start, syntax: "expression", literal: null },
+        );
+    });
+
+    return { nativeInputCount, fileInputs, diagnostics, rawHtml };
+}
+
+/** 合成ソース (または読み込み済みファイル) の集合を走査する。 */
+export function scanSources(sources: readonly SvelteSource[]): FileInputScanResult {
+    const fileInputs: FileInputRecord[] = [];
+    const diagnostics: ScanDiagnostic[] = [];
+    const rawHtml: RawHtmlRecord[] = [];
+    let nativeInputCount = 0;
+
+    for (const entry of sources) {
+        const scan = scanOneSource(entry);
+        nativeInputCount += scan.nativeInputCount;
+        diagnostics.push(...scan.diagnostics);
+
+        // 序数はソース上の出現順で確定する (走査順ではなく offset で並べる)
+        [...scan.fileInputs]
+            .sort((a, b) => a.start - b.start)
+            .forEach((record, index) => {
+                fileInputs.push({
+                    file: entry.file,
+                    occurrence: index + 1,
+                    syntax: record.syntax,
+                    literal: record.literal,
+                });
+            });
+        [...scan.rawHtml]
+            .sort((a, b) => a.start - b.start)
+            .forEach((record, index) => {
+                rawHtml.push({
+                    file: entry.file,
+                    occurrence: index + 1,
+                    at: positionAt(entry.source, record.start),
+                });
+            });
+    }
+
+    return {
+        svelteFileCount: sources.length,
+        nativeInputCount,
+        fileInputs,
+        diagnostics,
+        rawHtml,
+    };
+}
+
+/** `root` 配下の `.svelte` を再帰列挙する。 */
+async function listSvelteFiles(root: string): Promise<string[]> {
+    const entries = await fs.readdir(root, { recursive: true, withFileTypes: true });
+    const files: string[] = [];
+    for (const entry of entries) {
+        if (!entry.isFile()) continue;
+        if (path.extname(entry.name) !== ".svelte") continue;
+        const parent = (entry as unknown as { parentPath?: string }).parentPath ?? root;
+        files.push(path.join(parent, entry.name));
+    }
+
+    return files.sort();
+}
+
+/** 実リポジトリの走査根を読み込んで走査する (gate 用)。 */
+export async function scanFileInputs(root: string): Promise<FileInputScanResult> {
+    const files = await listSvelteFiles(root);
+    const sources: SvelteSource[] = [];
+    for (const absolute of files) {
+        sources.push({
+            file: path.relative(root, absolute).split(path.sep).join("/"),
+            source: await fs.readFile(absolute, "utf8"),
+        });
+    }
+
+    return scanSources(sources);
+}
```

---

## 検証結果 (修正後)

- `pnpm typecheck`: clean
- `pnpm lint`: clean
- `pnpm exec vitest run tests/js/architecture/file-input-scan.test.ts tests/js/architecture/file-input-accept-source-inventory.test.ts`: 62 tests passed
- 全レーン (composer test / phpstan / pint / pnpm lint / typecheck / test / build / packages 3 本) は
  この判定後に最終確認する

## 判定してほしい点

1. 大小文字違いの重複属性の穴が閉じたか (診断へ落ちること / 母集団から外れないこと)
2. 他に「正規化した後に情報を捨てている」経路が残っていないか
3. 全体判定を APPROVED / CHANGES_REQUESTED で示してほしい
