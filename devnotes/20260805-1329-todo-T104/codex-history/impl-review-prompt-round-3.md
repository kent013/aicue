Round 2 の [Critical] について、**実測に基づき反論**します。あわせて指摘が示唆した
「この不変条件が未テストである」点は正しいので、回帰テストを追加しました。

# [Critical] 入れ子の error-bearing entry — 反論

指摘の前提「normalizer が欠落フィールドを空値へ落とすなら、未受容 high として検出されず
偽グリーンになります」を検証したところ、**事実と異なりました**。
提示された 3 ケースすべてで `evaluate()` は **exitCode=1** を返します (gate は fail する)。

実測結果 (tsx で normalizer → evaluate を直接実行):

| 入力 | 正規化結果 | gate |
|---|---|---|
| `{"advisories":{"vendor/pkg":[{"error":"unavailable"}]}}` | `{id:"", packageName:"vendor/pkg", severity:"high"}` | **exitCode=1** `unaccepted high: composer|vendor/pkg|fallback:<missing-key>` |
| `{"advisories":[{"error":"boom"}]}` | `{id:"", packageName:"", severity:"high"}` | **exitCode=1** `unaccepted high: npm||fallback:<missing-key>` |
| `{"dependencies":[{"name":"x","vulns":[{"error":"boom"}]}]}` | `{id:"", packageName:"x", severity:"high"}` | **exitCode=1** `unaccepted high: pypi|x|fallback:<missing-key>` |

理由は既存の 2 段 fail-safe です (本バッチで追加したものではなく、元からある設計):

1. `normalizeNpmSeverity` / `normalizeComposerSeverity` は **unknown severity → `high`** を返す
   (`return "high";` が既定)。pip-audit は severity を持たないため常に `high` 固定。
   → 壊れた entry は 0 件に落ちるのではなく、必ず **high advisory** として現れる。
2. id 欠損 advisory は `AcceptedAdvisorySchema.id = z.string().min(1)` により
   **accept-risk 登録が構造的に不可能**。`matchKey` の fallback キーも
   `fallback:<missing-key>` になり受容済み entry と一致しない。
   → 黙らせる逃げ道が無く、必ず未受容 high として fail する。

これは audit-gate.ts の既存 doc コメントにも明文化されている方針です:
「id 欠損 advisory は schema 上 accept-risk 不可。normalizer 側で upstream ID を
補完できるまで gate を fail させる方針」。

## shape 層に要素検証を足さない理由

判定 (severity 決定・受容可否) は **判定層の責務**であり、shape 層へ持ち込むと
判定ロジックの二重管理になります。設計が定めた責務境界
「shell = 有効な出力が得られたか / TypeScript shape = JSON 妥当性と schema /
TypeScript 判定 = severity と受容可否」を壊すことになるため、追加しません。

## ただし指摘は「テストが無い」点で正しい

この fail-closed 性は **load-bearing なのに 1 本もテストが無い**状態でした。
将来 unknown-severity の既定を `low` に変える、あるいは id 欠損を許容する変更が入れば
本当に偽グリーンになります。そこで回帰テストを 2 本追加しました:

1. 3 ecosystem すべてで「入れ子 error → 1 件の high (id 空) → exitCode=1」を固定
2. id 欠損 advisory が accept-risk schema を通らないことを固定 (逃げ道が無いことの裏付け)

この判断が妥当か、あるいは実測の読み違いがあればご指摘ください。

# [Suggestion] global-test-lock.sh の race の TODO 化

**対応しました**。ただし `docs/TODO.md` への直接登録は `app-todo-add` スキルの契約上
概念設計・詳細設計の存在が前提のため、設計なしの追記は規約違反になります。
代わりに `devnotes/20260805-1329-todo-T104/known-issue-global-test-lock-race.md` に
再現条件・根本原因 (`set -euo pipefail` が `-n` による race 許容を到達前に潰している)・
影響範囲 (失敗モードは偽赤であって偽グリーンではない)・修正案 (`|| pgid=""` で
代入から ps の失敗を切り離す)・T104 で直さない理由を記録してコミットし、
最終報告で TODO 化を明示的に推奨します。

# テスト結果

- scripts/audit-gate.test.ts: **44 tests passed** (Round 2 時点 41 → 追加 2 本 + 分割 1 本)
- pnpm run audit:gate: exit 0 のまま

# 追加したテストの差分

diff --git a/scripts/audit-gate.test.ts b/scripts/audit-gate.test.ts
index d551118..9faa48b 100644
--- a/scripts/audit-gate.test.ts
+++ b/scripts/audit-gate.test.ts
@@ -4,11 +4,12 @@
  * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
  */
 import { describe, expect, it } from "vitest";
-import { writeFileSync, unlinkSync, mkdtempSync } from "node:fs";
+import { writeFileSync, unlinkSync, mkdtempSync, rmSync } from "node:fs";
 import { tmpdir } from "node:os";
 import { join } from "node:path";
 import {
     AcceptedAdvisorySchema,
+    NORMALIZERS,
     daysBetween,
     evaluate,
     loadAuditJson,
@@ -17,6 +18,7 @@ import {
     normalizePipAudit,
     normalizePnpmAudit,
     todayIsoJst,
+    type AuditSource,
     type NormalizedAdvisory,
 } from "./audit-gate";
 
@@ -338,7 +340,7 @@ describe("audit-gate JSON parse failure", () => {
         const tmp = join(dir, "invalid-json.json");
         writeFileSync(tmp, "{ not valid json");
         try {
-            expect(() => loadAuditJson(tmp, normalizePnpmAudit)).toThrow(/JSON parse failure/);
+            expect(() => loadAuditJson(tmp, "pnpm-audit")).toThrow(/JSON parse failure/);
         } finally {
             unlinkSync(tmp);
         }
@@ -360,3 +362,171 @@ describe("audit-gate utility helpers", () => {
         expect(todayIsoJst(new Date("2026-04-30T15:00:00Z"))).toBe("2026-05-01");
     });
 });
+
+// ============================================================================
+// 施策 4A: shape 検証 (取得異常を「advisory 0 件 = 緑」へ黙って落とさないこと)
+//
+// **loadAuditJson 経由**でテストする。assertAuditSourceShape を単体で呼ぶだけだと、
+// 実装者が export しただけで loadAuditJson から呼び忘れても検出できない (配線の空振り)。
+// ============================================================================
+
+describe("loadAuditJson の shape 検証 (fail-closed)", () => {
+    /** オブジェクトを JSON 化して loadAuditJson を通す (配線込みで正規化結果を得る)。 */
+    function loadFrom(source: AuditSource, json: unknown): NormalizedAdvisory[] {
+        return load(source, JSON.stringify(json));
+    }
+
+    /** 一時ファイルへ内容を書いて loadAuditJson を呼ぶ (配線まで含めて検証する)。 */
+    function load(source: AuditSource, contents: string): NormalizedAdvisory[] {
+        const dir = mkdtempSync(join(tmpdir(), "audit-gate-shape-"));
+        const tmp = join(dir, "audit.json");
+        writeFileSync(tmp, contents);
+        try {
+            return loadAuditJson(tmp, source);
+        } finally {
+            rmSync(dir, { recursive: true, force: true });
+        }
+    }
+
+    it("不正 JSON は throw する", () => {
+        expect(() => load("pnpm-audit", "not json")).toThrow(/JSON parse failure/);
+    });
+
+    it("pnpm: ネットワークエラー形 {error:{...}} は throw する (shape 黙殺の穴が塞がった証明)", () => {
+        // error シグナル検査が先に発火する (advisories 欠落でもあるので、どちらで止めても fail-closed)。
+        expect(() => load("pnpm-audit", JSON.stringify({ error: { code: "ENETUNREACH" } })))
+            .toThrow(/non-empty 'error' field/);
+    });
+
+    it("pnpm: error シグナル無しで advisories が欠落していても throw する", () => {
+        expect(() => load("pnpm-audit", JSON.stringify({ metadata: { totalDependencies: 0 } })))
+            .toThrow(/missing 'advisories'/);
+    });
+
+    it("composer: 空配列 {advisories: []} は throw しない (composer の正当な 0 件表現)", () => {
+        // 実測: composer audit --format=json は advisory 0 件のとき
+        // `{"advisories":[],"abandoned":[],"filter":[]}` を出す (PHP の空配列由来)。
+        // ここを弾くと「全部解消した正常状態」が恒久的に赤くなる (偽赤)。
+        expect(load("composer-audit", JSON.stringify({ advisories: [] }))).toEqual([]);
+    });
+
+    it("composer: 非空配列は throw する (黙って 0 件へ落ちる偽グリーン経路)", () => {
+        // composer は非 0 件を必ず package キーの object で出す。非空配列は schema 不一致であり、
+        // normalizeComposerAudit の Object.entries が index キーで走査して黙って 0 件になる。
+        expect(() => load("composer-audit", JSON.stringify({ advisories: [{ advisoryId: "X" }] })))
+            .toThrow(/must be an object when non-empty/);
+    });
+
+    it("pnpm: {advisories: []} は throw しない (pnpm は array 形も正当)", () => {
+        expect(load("pnpm-audit", JSON.stringify({ advisories: [] }))).toEqual([]);
+    });
+
+    it("pnpm / composer: {advisories: {}} は throw しない (真の 0 件は緑)", () => {
+        expect(load("pnpm-audit", JSON.stringify({ advisories: {} }))).toEqual([]);
+        expect(load("composer-audit", JSON.stringify({ advisories: {} }))).toEqual([]);
+    });
+
+    it("top-level 配列は throw する", () => {
+        for (const source of ["pnpm-audit", "composer-audit", "pip-audit"] as const) {
+            expect(() => load(source, "[]")).toThrow(/expected a JSON object at top level/);
+        }
+    });
+
+    it("composer: advisories の値が array でないと throw する (内部 schema 不整合)", () => {
+        expect(() =>
+            load("composer-audit", JSON.stringify({ advisories: { "vendor/pkg": { error: "unavailable" } } })),
+        ).toThrow(/advisories\["vendor\/pkg"\] must be an array/);
+    });
+
+    it("pnpm: primitive / null の entry は throw する", () => {
+        expect(() => load("pnpm-audit", JSON.stringify({ advisories: [null] })))
+            .toThrow(/advisories\[0\] must be an object/);
+        expect(() => load("pnpm-audit", JSON.stringify({ advisories: ["x"] })))
+            .toThrow(/advisories\[0\] must be an object/);
+    });
+
+    it("pip: {dependencies: []} は throw しない", () => {
+        expect(load("pip-audit", JSON.stringify({ dependencies: [] }))).toEqual([]);
+    });
+
+    it("pip: name 欠落の dependency は throw する", () => {
+        expect(() => load("pip-audit", JSON.stringify({ dependencies: [{}] })))
+            .toThrow(/dependencies\[0\]\.name must be a string/);
+    });
+
+    it("pip: 空 vulns は正当な 0 件として通す", () => {
+        expect(load("pip-audit", JSON.stringify({ dependencies: [{ name: "x", vulns: [] }] }))).toEqual([]);
+    });
+
+    it("pip: dependencies 欠落は throw する", () => {
+        expect(() => load("pip-audit", "{}")).toThrow(/missing 'dependencies' array/);
+    });
+
+    it("error-bearing output は空コンテナでも throw する (impl-review R1 [Critical])", () => {
+        // 「有効 JSON だが取得失敗を示す形」が正当な 0 件として通ると偽グリーンになる。
+        expect(() => load("pnpm-audit", JSON.stringify({ advisories: {}, error: { code: "ENETUNREACH" } })))
+            .toThrow(/non-empty 'error' field/);
+        expect(() => load("composer-audit", JSON.stringify({ advisories: [], error: "registry unreachable" })))
+            .toThrow(/non-empty 'error' field/);
+        expect(() => load("pip-audit", JSON.stringify({ dependencies: [], errors: ["boom"] })))
+            .toThrow(/non-empty 'errors' field/);
+    });
+
+    it("空の error フィールドは通す (偽赤にしない)", () => {
+        expect(load("pnpm-audit", JSON.stringify({ advisories: {}, error: null }))).toEqual([]);
+        expect(load("composer-audit", JSON.stringify({ advisories: [], error: {} }))).toEqual([]);
+        expect(load("pip-audit", JSON.stringify({ dependencies: [], errors: [] }))).toEqual([]);
+    });
+
+    it("入れ子の error-bearing entry は 0 件へ落ちず high として gate を落とす (fail-closed の実証)", () => {
+        // impl-review R2 [Critical] の検証。`{"advisories":{"pkg":[{"error":"..."}]}}` のような
+        // **要素レベルで壊れた** 入力は shape 検査を通過するが、偽グリーンにはならない:
+        // normalizer の severity unknown → high fail-safe により high advisory になり、
+        // かつ id 欠損 advisory は AcceptedAdvisorySchema (id = min(1)) で accept-risk 不可なので
+        // 必ず未受容 high として fail する。
+        //
+        // shape 層で advisory 要素の必須フィールドまで検証しない理由:
+        // 判定 (severity 決定・受容可否) は audit-gate.ts の判定層の責務であり、
+        // shape 層へ持ち込むと判定ロジックの二重管理になる (責務境界を壊す)。
+        // ここでは「その分担でも fail-closed が成立する」ことをテストで固定する。
+        const cases: Array<[string, NormalizedAdvisory[]]> = [
+            ["composer", loadFrom("composer-audit", { advisories: { "vendor/pkg": [{ error: "unavailable" }] } })],
+            ["pnpm", loadFrom("pnpm-audit", { advisories: [{ error: "boom" }] })],
+            ["pip", loadFrom("pip-audit", { dependencies: [{ name: "x", vulns: [{ error: "boom" }] }] })],
+        ];
+
+        for (const [label, advisories] of cases) {
+            expect(advisories, `${label}: 0 件へ落ちてはならない`).toHaveLength(1);
+            expect(advisories[0].severity, `${label}: unknown severity は high fail-safe`).toBe("high");
+            expect(advisories[0].id, `${label}: id は欠損 (= accept-risk 不可)`).toBe("");
+
+            const result = evaluate(advisories, [], TODAY);
+            expect(result.exitCode, `${label}: gate は fail しなければならない`).toBe(1);
+            expect(result.failures.length).toBeGreaterThan(0);
+        }
+    });
+
+    it("id 欠損 advisory は accept-risk で黙らせられない (schema が id を必須にしている)", () => {
+        // 上のテストの「逃げ道が無いこと」の裏付け。
+        expect(() =>
+            AcceptedAdvisorySchema.parse({
+                id: "",
+                package: "vendor/pkg",
+                ecosystem: "composer",
+                severity: "high",
+                owner: "o",
+                approved_at: "2026-08-01",
+                expiry: "2026-08-20",
+                rationale: "r",
+                approved_by: "o",
+                compensating_controls: "c",
+                tracking_issue: "t",
+            }),
+        ).toThrow();
+    });
+
+    it("NORMALIZERS が全 AuditSource を網羅している (誤配線を型と assertion で固定)", () => {
+        const sources: AuditSource[] = ["pnpm-audit", "composer-audit", "pip-audit"];
+        expect(Object.keys(NORMALIZERS).sort()).toEqual([...sources].sort());
+    });
+});
