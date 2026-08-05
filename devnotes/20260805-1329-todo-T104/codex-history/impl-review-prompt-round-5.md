ご指摘のとおりでした。実測で再現し、id 欠損検査を severity 分岐の外へ出しました。

# [Critical] 対応: id 欠損検査を独立 step へ昇格

再現結果 (修正前):

```
入力      : {"advisories":[{"error":"boom","severity":"moderate"}]}
normalized: [{"id":"","packageName":"","ecosystem":"npm","severity":"moderate","source":"pnpm-audit"}]
evaluate  : {"exitCode":0,"failures":[],"moderateWarns":[{...}],"cleanupCandidates":[]}
```

normalizer が明示 `moderate` を維持するため high/critical filter を通過せず、
moderate warn (exit 0) へ落ちていました。ご指摘のとおり「unknown severity → high」に
依存する構造が脆かったということです。

## 修正

id 欠損検査を **severity filter の外へ出し、独立した step 4 に昇格**させました。
ご提案は「ID 検査を severity filter より前へ移す」でしたが、後続の moderate warn 集計
(step 6) でも同じ advisory が warn へ流れないようにする必要があるため、
**`identifiable` 配列でフィルタを 1 回だけ行い、後続 step 全体がそれを走査する**形にしました:

```ts
// 4. id 欠損 advisory は **severity に関係なく** 無条件 fail
const identifiable: NormalizedAdvisory[] = [];
for (const adv of advisories) {
    if (adv.id.trim().length === 0) {
        failures.push(`unidentifiable advisory (missing upstream id, severity=${adv.severity}): ...`);
        continue;
    }
    identifiable.push(adv);
}

// 5. 未受容 high/critical を fail   → identifiable を走査
// 6. 未受容 moderate を warn 集計   → identifiable を走査
```

これにより「id 欠損は failure になり、かつ warn へは逃げない」が構造的に保証されます
(warn は exit 0 なので、warn へ逃がすと偽グリーンが残ります)。

## 追加した負のコントロール

ご指摘の moderate / low ケースを追加しました。各 severity について:

- 明示 severity が維持されていること (= unknown→high 防壁に依存していないことの確認)
- `exitCode=1` になること
- **`moderateWarns` が空であること** (warn へ逃がしていないこと)

## 偽赤になっていないことの確認

実運用の advisory 集合を `loadAuditJson` 経由で正規化し、**id が空のものが 0 件**であることを
実測しました (total 4 件 / empty id 0 件)。`pnpm run audit:gate` は exit 0 のままです。

# テスト結果

- scripts/audit-gate.test.ts: **46 tests passed**
- pnpm test: 114 files / **1086 tests passed**
- pnpm run audit:gate: **exit 0**
- pnpm typecheck / lint: exit 0

# 差分

diff --git a/scripts/audit-gate.test.ts b/scripts/audit-gate.test.ts
index d551118..1d09866 100644
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
@@ -360,3 +362,223 @@ describe("audit-gate utility helpers", () => {
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
+    it("id 欠損 advisory は accept-risk で黙らせられない (空 id は schema が弾く)", () => {
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
+    it("id 欠損 advisory は fallback キーを直接書いた accept-risk でも黙らせられない", () => {
+        // impl-review R3 [Critical] の回帰テスト。
+        // matchKey は id が空の advisory を `<eco>|<pkg>|fallback:<missing-key>` へ落とすが、
+        // accept-risk 側は `id: "fallback:<missing-key>"` と**書くだけで同じキーを合成できる**。
+        // 修正前はこれで exitCode=0 になり、壊れた audit 出力を受容で黙らせられた。
+        const advisories = loadFrom("composer-audit", {
+            advisories: { "vendor/pkg": [{ error: "unavailable" }] },
+        });
+        const accepted = AcceptedAdvisorySchema.parse({
+            id: "fallback:<missing-key>",
+            package: "vendor/pkg",
+            ecosystem: "composer",
+            severity: "high",
+            owner: "o",
+            approved_at: "2026-04-15",
+            expiry: "2026-05-10",
+            rationale: "r",
+            approved_by: "o",
+            compensating_controls: "c",
+            tracking_issue: "t",
+        });
+
+        // 照合キーは一致してしまう (= schema だけでは防げないことの明示)
+        expect(matchKey(advisories[0])).toBe(matchKey({ ...accepted, packageName: accepted.package }));
+
+        // それでも gate は落ちなければならない
+        const result = evaluate(advisories, [accepted], TODAY);
+        expect(result.exitCode).toBe(1);
+        expect(result.failures.some((f) => f.includes("unidentifiable advisory"))).toBe(true);
+    });
+
+    it("id 欠損は severity が moderate / low でも exitCode=1 になる (同定不能性そのもので落とす)", () => {
+        // impl-review R4 [Critical] の回帰テスト。
+        // id 欠損検査を severity filter の内側に置くと、**明示 severity を持つ壊れた entry** が
+        // step をすり抜けて moderate warn (exit 0) に落ちる。実測で確認済み。
+        // 「unknown severity → high」という別の防壁に依存させないため、severity に関係なく落とす。
+        for (const severity of ["moderate", "low"] as const) {
+            const advisories = loadFrom("pnpm-audit", {
+                advisories: [{ error: "boom", severity }],
+            });
+
+            expect(advisories).toHaveLength(1);
+            expect(advisories[0].id).toBe("");
+            expect(advisories[0].severity, `明示 severity (${severity}) は維持される`).toBe(severity);
+
+            const result = evaluate(advisories, [], TODAY);
+            expect(result.exitCode, `severity=${severity} でも fail すること`).toBe(1);
+            expect(result.failures.some((f) => f.includes("unidentifiable advisory"))).toBe(true);
+            // warn へ逃がさない (warn は exit 0 なので偽グリーンになる)
+            expect(result.moderateWarns).toHaveLength(0);
+        }
+    });
+
+    it("NORMALIZERS が全 AuditSource を網羅している (誤配線を型と assertion で固定)", () => {
+        const sources: AuditSource[] = ["pnpm-audit", "composer-audit", "pip-audit"];
+        expect(Object.keys(NORMALIZERS).sort()).toEqual([...sources].sort());
+    });
+});
diff --git a/scripts/audit-gate.ts b/scripts/audit-gate.ts
index efb77d0..063028a 100644
--- a/scripts/audit-gate.ts
+++ b/scripts/audit-gate.ts
@@ -165,10 +165,144 @@ export function daysBetween(fromIso: string, toIso: string): number {
 // Loaders
 // ============================================================================
 
-export function loadAuditJson(
-    path: string,
-    normalizer: (json: unknown) => NormalizedAdvisory[],
-): NormalizedAdvisory[] {
+/** audit 入力 1 件分の由来。エラーメッセージと shape 期待値を決める。 */
+export type AuditSource = "pnpm-audit" | "composer-audit" | "pip-audit";
+
+/**
+ * audit JSON が **その ecosystem の期待 schema を持つ**ことを検証する (純関数)。
+ *
+ * 目的は「valid JSON だが中身が違う」を 0 件へ黙って落とさないこと。
+ * 例: ネットワークエラーで `{"error":{...}}` が返ると、normalizer は
+ * `if (!obj.advisories) return []` により **advisory 0 件 = 緑** に落ちる。
+ * blocking gate ではこれが偽グリーンになるため、ここで fail-closed に止める。
+ *
+ * 検証するのは **normalizer が走査に使う最小構造** まで。未知フィールドは許容し、
+ * 空コンテナ (`{}` / `[]` / 空 `vulns`) は **正当な 0 件** として通す。
+ *
+ * top-level だけを見る設計にしないのは、内部が壊れた JSON も 0 件へ落ちるため。例:
+ *   `{"advisories":{"vendor/pkg":{"error":"unavailable"}}}`
+ *   → normalizeComposerAudit は `Array.isArray(advisoriesUnknown)` が false のとき
+ *     `[]` を使うので **黙って 0 件**になる。取得異常を「安全」と読み替えてしまう。
+ *
+ * @throws Error 期待 schema を満たさない場合
+ */
+export function assertAuditSourceShape(source: AuditSource, json: unknown): void {
+    if (!json || typeof json !== "object" || Array.isArray(json)) {
+        throw new Error(`${source}: expected a JSON object at top level`);
+    }
+    const obj = json as Record<string, unknown>;
+    const keys = Object.keys(obj).join(", ");
+
+    // **既知の失敗シグナルはコンテナが空でも拒否する** (impl-review R1 [Critical])。
+    // コンテナの型だけを見ると `{"advisories":{},"error":{"code":"ENETUNREACH"}}` /
+    // `{"dependencies":[],"error":...}` のような「有効 JSON だが取得失敗を示す形」が
+    // **正当な 0 件**として通ってしまう。空コンテナの許容 (真の 0 件を緑にする) と
+    // error-bearing output の拒否は必ずセットにする。
+    // 空の error (null / {} / []) は「エラー無し」を明示しただけなので通す (偽赤にしない)。
+    for (const field of ["error", "errors"]) {
+        const signal = obj[field];
+        if (signal === undefined || signal === null) continue;
+        const isEmpty =
+            (Array.isArray(signal) && signal.length === 0) ||
+            (typeof signal === "object" && !Array.isArray(signal) && Object.keys(signal).length === 0);
+        if (!isEmpty) {
+            throw new Error(
+                `${source}: output carries a non-empty '${field}' field — treating this as an acquisition ` +
+                    `failure, not as 'no advisories' (got keys: ${keys})`,
+            );
+        }
+    }
+
+    // **source ごとに期待コンテナの型を変える**。
+    // 共通条件 (`typeof === "object"`) にすると composer で `{"advisories": []}` が通り、
+    // normalizeComposerAudit の `typeof obj.advisories !== "object"` も配列を弾かないため
+    // Object.entries([]) = [] で **advisory 0 件 = 緑** に落ちる (偽グリーン)。
+    switch (source) {
+        case "pnpm-audit": {
+            // pnpm/npm audit は形式によって object (キー = advisory id) と array の両方を返す。
+            // normalizePnpmAudit が両対応しているので、ここも両方を受理する。
+            const c = obj.advisories;
+            if (c === undefined || c === null || typeof c !== "object") {
+                throw new Error(`pnpm-audit: missing 'advisories' object or array (got keys: ${keys})`);
+            }
+            // normalizePnpmAudit は各 entry を `Record<string, unknown>` として読む。
+            // primitive / null の entry は黙って id="" package="" の advisory になるため弾く。
+            const entries = Array.isArray(c) ? c : Object.values(c as Record<string, unknown>);
+            for (const [i, e] of entries.entries()) {
+                if (!e || typeof e !== "object" || Array.isArray(e)) {
+                    throw new Error(`pnpm-audit: advisories[${i}] must be an object (got ${typeof e})`);
+                }
+            }
+            return;
+        }
+        case "composer-audit": {
+            // composer audit は findings があるとき `{"advisories": {"<package>": [...]}}` の object。
+            //
+            // ただし **0 件のときだけ `[]` を出す** — PHP の空配列が json_encode で `[]` に
+            // なるためで、これは composer の正当な「advisory なし」表現である (実測)。
+            // 設計は当初「配列なら一律 fail」としていたが、それでは advisory を全て解消した
+            // 正常状態が恒久的に赤くなる (偽赤)。設計意図は「非 0 件の中身が黙って 0 件へ
+            // 落ちる経路を塞ぐ」ことなので、**空配列だけを許容し、非空配列は拒否**する。
+            // 非空配列は composer が出さない形であり、normalizeComposerAudit の
+            // Object.entries([...]) が index キーで走査して黙って 0 件になる偽グリーン経路。
+            const c = obj.advisories;
+            if (c === undefined || c === null || typeof c !== "object") {
+                throw new Error(`composer-audit: missing 'advisories' object (got keys: ${keys})`);
+            }
+            if (Array.isArray(c)) {
+                if (c.length > 0) {
+                    throw new Error(
+                        `composer-audit: 'advisories' must be an object when non-empty (got a ${c.length}-element array)`,
+                    );
+                }
+                return; // 空配列 = composer の正当な 0 件表現
+            }
+            // normalizeComposerAudit は package ごとの値が array でなければ **黙って空扱い** にする。
+            // 空の object {} (= 0 件) は正当だが、値が array でないのは schema 不一致なので弾く。
+            for (const [pkg, v] of Object.entries(c as Record<string, unknown>)) {
+                if (!Array.isArray(v)) {
+                    throw new Error(`composer-audit: advisories["${pkg}"] must be an array (got ${typeof v})`);
+                }
+            }
+            return;
+        }
+        case "pip-audit": {
+            if (!Array.isArray(obj.dependencies)) {
+                throw new Error(`pip-audit: missing 'dependencies' array (got keys: ${keys})`);
+            }
+            // normalizePipAudit は name / vulns を読む。空 vulns は正当な 0 件。
+            for (const [i, d] of obj.dependencies.entries()) {
+                if (!d || typeof d !== "object" || Array.isArray(d)) {
+                    throw new Error(`pip-audit: dependencies[${i}] must be an object`);
+                }
+                const dep = d as Record<string, unknown>;
+                if (typeof dep.name !== "string") {
+                    throw new Error(`pip-audit: dependencies[${i}].name must be a string`);
+                }
+                if (!Array.isArray(dep.vulns)) {
+                    throw new Error(`pip-audit: dependencies[${i}].vulns must be an array`);
+                }
+            }
+            return;
+        }
+    }
+}
+
+/**
+ * source => normalizer の対応表。
+ *
+ * pnpm と composer はどちらも object 形式の `advisories` を持ちうるため、
+ * shape 検査だけでは normalizer の取り違えを常に検出できない。
+ * `source` と `normalizer` を別々の引数で渡す限り、誤った組み合わせが型として書けてしまう。
+ * そこで source から normalizer を **内部で選択** し、誤配線そのものを表現不能にする。
+ */
+export const NORMALIZERS: Record<AuditSource, (json: unknown) => NormalizedAdvisory[]> = {
+    "pnpm-audit": normalizePnpmAudit,
+    "composer-audit": normalizeComposerAudit,
+    "pip-audit": normalizePipAudit,
+};
+
+export function loadAuditJson(path: string, source: AuditSource): NormalizedAdvisory[] {
     const raw = readFileSync(path, "utf-8");
     let json: unknown;
     try {
@@ -176,7 +310,8 @@ export function loadAuditJson(
     } catch (e) {
         throw new Error(`JSON parse failure in ${path}: ${(e as Error).message}`);
     }
-    return normalizer(json);
+    assertAuditSourceShape(source, json); // ← 配線点。ここを消すと unit テストが落ちる
+    return NORMALIZERS[source](json);
 }
 
 export function loadAcceptedAdvisories(path: string): AcceptedAdvisory[] {
@@ -389,8 +524,39 @@ export function evaluate(
         }
     }
 
-    // 4. 未受容 high/critical を fail
+    // 4. id 欠損 advisory は **severity に関係なく** 無条件 fail
+    //    (impl-review R3/R4 [Critical])。
+    //
+    // 背景 1 (R3): id が空の advisory は matchKey の fallback 経路で
+    // `<eco>|<pkg>|fallback:<missing-key>` というキーになる。ところが accept-risk 側は
+    // `id: "fallback:<missing-key>"` という**文字列を書くだけで同じキーを合成できる**
+    // (matchKey は id が非空ならそれをそのまま使うため)。実測で exitCode=0 になり、
+    // 「取得が壊れて中身を読めなかった advisory」を受容で黙らせられることを確認した。
+    //
+    // 背景 2 (R4): この検査を severity filter の**内側**に置くと、
+    // `{"advisories":[{"error":"boom","severity":"moderate"}]}` のように
+    // **明示 severity を持つ壊れた entry** が step 4 を素通りし、moderate warn (exit 0) に落ちる。
+    // 実測で確認済み。取得結果の破損は severity policy とは別軸の異常なので、
+    // 「unknown severity → high」という別の防壁に依存させず、同定不能性そのもので落とす。
+    //
+    // 根拠: upstream ID を持たない advisory は**同定不能**であり、同定できないものを
+    // 「このリスクは評価済み」と宣言することは原理的にできない。よって受容を許さない。
+    // 正しい対処は normalizer 側で upstream ID (GHSA-* / CVE-* / PYSEC-*) を補完すること。
+    const identifiable: NormalizedAdvisory[] = [];
     for (const adv of advisories) {
+        if (adv.id.trim().length === 0) {
+            failures.push(
+                `unidentifiable advisory (missing upstream id, severity=${adv.severity}): ${matchKey(adv)} ` +
+                    `(${adv.title ?? ""}). accept-risk cannot silence an advisory that has no id — ` +
+                    `this usually means the audit output was malformed or truncated.`,
+            );
+            continue;
+        }
+        identifiable.push(adv);
+    }
+
+    // 5. 未受容 high/critical を fail
+    for (const adv of identifiable) {
         if (adv.severity !== "high" && adv.severity !== "critical") continue;
         const acceptedEntry = acceptedByKey.get(matchKey(adv));
         if (!acceptedEntry) {
@@ -398,8 +564,8 @@ export function evaluate(
         }
     }
 
-    // 5. 未受容 moderate を warn 集計
-    for (const adv of advisories) {
+    // 6. 未受容 moderate を warn 集計
+    for (const adv of identifiable) {
         if (adv.severity !== "moderate") continue;
         const acceptedEntry = acceptedByKey.get(matchKey(adv));
         if (!acceptedEntry) {
@@ -490,9 +656,9 @@ async function main(): Promise<void> {
     let accepted: AcceptedAdvisory[];
     try {
         advisories = [
-            ...loadAuditJson(pnpmPath, normalizePnpmAudit),
-            ...loadAuditJson(composerPath, normalizeComposerAudit),
-            ...(pipPath ? loadAuditJson(pipPath, normalizePipAudit) : []),
+            ...loadAuditJson(pnpmPath, "pnpm-audit"),
+            ...loadAuditJson(composerPath, "composer-audit"),
+            ...(pipPath ? loadAuditJson(pipPath, "pip-audit") : []),
         ];
         accepted = loadAcceptedAdvisories(acceptedPath);
     } catch (e) {
