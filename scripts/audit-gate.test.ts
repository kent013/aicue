/**
 * audit-gate.ts unit tests.
 *
 * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
 */
import { describe, expect, it } from "vitest";
import { writeFileSync, unlinkSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import {
    AcceptedAdvisorySchema,
    NORMALIZERS,
    daysBetween,
    evaluate,
    loadAuditJson,
    matchKey,
    normalizeComposerAudit,
    normalizePipAudit,
    normalizePnpmAudit,
    todayIsoJst,
    type AuditSource,
    type NormalizedAdvisory,
} from "./audit-gate";

const TODAY = new Date("2026-04-30T03:00:00Z"); // JST = 12:00 = 2026-04-30

function npmAdvisory(over: Partial<NormalizedAdvisory> = {}): NormalizedAdvisory {
    return {
        id: "GHSA-x",
        packageName: "p",
        ecosystem: "npm",
        severity: "moderate",
        source: "pnpm-audit",
        ...over,
    };
}

describe("audit-gate evaluate()", () => {
    it("moderate のみで accepted なし → exit 0、warn 列挙", () => {
        const result = evaluate([npmAdvisory({ severity: "moderate" })], [], TODAY);
        expect(result.exitCode).toBe(0);
        expect(result.moderateWarns).toHaveLength(1);
        expect(result.failures).toHaveLength(0);
    });

    it("未受容 high が 1 件 → exit 1", () => {
        const result = evaluate([npmAdvisory({ id: "GHSA-y", severity: "high" })], [], TODAY);
        expect(result.exitCode).toBe(1);
        expect(result.failures.some((f) => f.includes("unaccepted high"))).toBe(true);
    });

    it("受容済み high（必須項目あり）→ exit 0", () => {
        const accepted = AcceptedAdvisorySchema.parse({
            id: "GHSA-y",
            package: "p",
            ecosystem: "npm",
            severity: "high",
            owner: "ishitoya",
            approved_at: "2026-04-30",
            expiry: "2026-05-30",
            rationale: "test",
            approved_by: "ishitoya",
            compensating_controls: "WAF rate limit",
            tracking_issue: "T-XXX",
        });
        const result = evaluate([npmAdvisory({ id: "GHSA-y", severity: "high" })], [accepted], TODAY);
        expect(result.exitCode).toBe(0);
    });

    it("受容済み high（approved_by 欠落）→ schema 検証で throw", () => {
        expect(() =>
            AcceptedAdvisorySchema.parse({
                id: "GHSA-y",
                package: "p",
                ecosystem: "npm",
                severity: "high",
                owner: "ishitoya",
                approved_at: "2026-04-30",
                expiry: "2026-05-30",
                rationale: "...",
                // approved_by 欠落
            }),
        ).toThrow();
    });

    it("受容済み moderate（approved_at 欠落）→ schema 検証で throw", () => {
        expect(() =>
            AcceptedAdvisorySchema.parse({
                id: "GHSA-x",
                package: "p",
                ecosystem: "npm",
                severity: "moderate",
                owner: "ishitoya",
                expiry: "2026-12-31",
                rationale: "...",
                // approved_at 欠落（base required）
            }),
        ).toThrow();
    });

    it("運用上限超過: moderate で 92 日 expiry → fail（low/moderate も 90日上限が機械強制）", () => {
        const accepted = AcceptedAdvisorySchema.parse({
            id: "GHSA-y",
            package: "p",
            ecosystem: "npm",
            severity: "moderate",
            owner: "ishitoya",
            approved_at: "2026-04-30",
            expiry: "2026-07-31", // 92 日（90 日上限超過）
            rationale: "test",
        });
        const result = evaluate(
            [npmAdvisory({ id: "GHSA-y", severity: "moderate" })],
            [accepted],
            TODAY,
        );
        expect(result.exitCode).toBe(1);
        expect(result.failures.some((f) => f.includes("exceeds policy"))).toBe(true);
    });

    it("expired entry（advisory 検出なし）→ exit 1", () => {
        const accepted = AcceptedAdvisorySchema.parse({
            id: "GHSA-z",
            package: "p",
            ecosystem: "npm",
            severity: "moderate",
            owner: "ishitoya",
            approved_at: "2025-11-01",
            expiry: "2026-01-01",
            rationale: "test",
        });
        const result = evaluate([], [accepted], TODAY);
        expect(result.exitCode).toBe(1);
        expect(result.failures.some((f) => f.includes("expired"))).toBe(true);
    });

    it("解消済み accepted entry → exit 1（cleanup 強制）", () => {
        const accepted = AcceptedAdvisorySchema.parse({
            id: "GHSA-z",
            package: "p",
            ecosystem: "npm",
            severity: "moderate",
            owner: "ishitoya",
            approved_at: "2026-04-30",
            expiry: "2026-05-30",
            rationale: "test",
        });
        const result = evaluate([], [accepted], TODAY);
        expect(result.exitCode).toBe(1);
        expect(result.failures.some((f) => f.includes("cleanup required"))).toBe(true);
    });

    it("severity 不正値 → schema 検証で throw", () => {
        expect(() =>
            AcceptedAdvisorySchema.parse({
                id: "GHSA-x",
                package: "p",
                ecosystem: "npm",
                severity: "foo",
                owner: "ishitoya",
                approved_at: "2026-04-30",
                expiry: "2026-06-01",
                rationale: "test",
            }),
        ).toThrow();
    });

    it("不正な日付（2026-99-99）→ schema 検証で throw", () => {
        expect(() =>
            AcceptedAdvisorySchema.parse({
                id: "GHSA-x",
                package: "p",
                ecosystem: "npm",
                severity: "moderate",
                owner: "ishitoya",
                approved_at: "2026-04-30",
                expiry: "2026-99-99",
                rationale: "test",
            }),
        ).toThrow();
    });

    it("matchKey: 大小文字・余白の差異を吸収", () => {
        const a = { id: " GHSA-X ", packageName: " Foo ", ecosystem: "NPM" };
        const b = { id: "ghsa-x", packageName: "foo", ecosystem: "npm" };
        expect(matchKey(a)).toBe(matchKey(b));
    });

    it("matchKey: id 欠損時は url/title フォールバック", () => {
        const a = { ecosystem: "pypi", packageName: "p", url: "https://osv.dev/PYSEC-1" };
        const key = matchKey(a);
        expect(key).toContain("fallback:");
        expect(key).toContain("osv.dev");
    });

    it("severity mismatch bypass を阻止: high advisory を moderate accept で迂回不可", () => {
        const acceptedAsModerate = AcceptedAdvisorySchema.parse({
            id: "GHSA-y",
            package: "p",
            ecosystem: "npm",
            severity: "moderate",
            owner: "ishitoya",
            approved_at: "2026-04-30",
            expiry: "2026-05-30",
            rationale: "test",
        });
        const result = evaluate(
            [npmAdvisory({ id: "GHSA-y", severity: "high" })],
            [acceptedAsModerate],
            TODAY,
        );
        expect(result.exitCode).toBe(1);
        expect(result.failures.some((f) => f.includes("severity mismatch"))).toBe(true);
    });

    it("運用上限超過: high で 32 日 expiry → fail", () => {
        const accepted = AcceptedAdvisorySchema.parse({
            id: "GHSA-y",
            package: "p",
            ecosystem: "npm",
            severity: "high",
            owner: "ishitoya",
            approved_at: "2026-04-30",
            expiry: "2026-06-01", // approved_at から 32 日（30 日上限超過）
            rationale: "test",
            approved_by: "ishitoya",
            compensating_controls: "WAF",
            tracking_issue: "T-XXX",
        });
        const result = evaluate([npmAdvisory({ id: "GHSA-y", severity: "high" })], [accepted], TODAY);
        expect(result.exitCode).toBe(1);
        expect(result.failures.some((f) => f.includes("exceeds policy"))).toBe(true);
    });

    it("運用上限内: high で 30 日 expiry → pass", () => {
        const accepted = AcceptedAdvisorySchema.parse({
            id: "GHSA-y",
            package: "p",
            ecosystem: "npm",
            severity: "high",
            owner: "ishitoya",
            approved_at: "2026-04-30",
            expiry: "2026-05-30", // 30 日（ちょうど上限）
            rationale: "test",
            approved_by: "ishitoya",
            compensating_controls: "WAF",
            tracking_issue: "T-XXX",
        });
        const result = evaluate([npmAdvisory({ id: "GHSA-y", severity: "high" })], [accepted], TODAY);
        expect(result.exitCode).toBe(0);
    });

    it("expiry === today は有効（その日いっぱいまで）", () => {
        const accepted = AcceptedAdvisorySchema.parse({
            id: "GHSA-y",
            package: "p",
            ecosystem: "npm",
            severity: "moderate",
            owner: "ishitoya",
            approved_at: "2026-04-30",
            expiry: "2026-04-30",
            rationale: "test",
        });
        const result = evaluate(
            [npmAdvisory({ id: "GHSA-y", severity: "moderate" })],
            [accepted],
            TODAY,
        );
        expect(result.exitCode).toBe(0);
    });
});

describe("audit-gate severity unknown fail-safe", () => {
    it("normalizePnpmAudit: severity unknown → high 扱い", () => {
        const json = {
            advisories: { "1": { id: "GHSA-x", severity: "info", module_name: "p" } },
        };
        const out = normalizePnpmAudit(json);
        expect(out[0].severity).toBe("high");
    });

    it("normalizeComposerAudit: severity unknown → high 扱い", () => {
        const json = {
            advisories: {
                "vendor/p": [{ advisoryId: "x", severity: "wat", title: "..." }],
            },
        };
        const out = normalizeComposerAudit(json);
        expect(out[0].severity).toBe("high");
    });
});

describe("audit-gate normalize*()", () => {
    it("normalizePnpmAudit: object 形式（pnpm 独自）", () => {
        const json = {
            advisories: { "1234": { id: "GHSA-x", severity: "moderate", module_name: "pkg-a", title: "..." } },
        };
        const out = normalizePnpmAudit(json);
        expect(out).toHaveLength(1);
        expect(out[0].packageName).toBe("pkg-a");
        expect(out[0].severity).toBe("moderate");
    });

    it("normalizePnpmAudit: array 形式（npm 互換）", () => {
        const json = {
            advisories: [{ github_advisory_id: "GHSA-x", severity: "high", module_name: "pkg-b" }],
        };
        const out = normalizePnpmAudit(json);
        expect(out).toHaveLength(1);
        expect(out[0].severity).toBe("high");
    });

    it("normalizeComposerAudit: medium → moderate 変換", () => {
        const json = {
            advisories: {
                "vendor/pkg": [{ advisoryId: "PKSA-x", cve: "CVE-2026-XXX", severity: "medium", title: "..." }],
            },
        };
        const out = normalizeComposerAudit(json);
        expect(out[0].severity).toBe("moderate");
        expect(out[0].packageName).toBe("vendor/pkg");
    });

    it("normalizePipAudit: severity unknown → high 扱い (fail-safe)", () => {
        const json = {
            dependencies: [
                { name: "openpyxl", version: "3.1.5", vulns: [{ id: "PYSEC-1", description: "..." }] },
            ],
        };
        const out = normalizePipAudit(json);
        expect(out).toHaveLength(1);
        expect(out[0].severity).toBe("high");
        expect(out[0].packageName).toBe("openpyxl");
    });
});

describe("audit-gate JSON parse failure", () => {
    it("invalid JSON は throw", () => {
        const dir = mkdtempSync(join(tmpdir(), "audit-gate-test-"));
        const tmp = join(dir, "invalid-json.json");
        writeFileSync(tmp, "{ not valid json");
        try {
            expect(() => loadAuditJson(tmp, "pnpm-audit")).toThrow(/JSON parse failure/);
        } finally {
            unlinkSync(tmp);
        }
    });
});

describe("audit-gate utility helpers", () => {
    it("daysBetween: 開始日を含めない差分日数", () => {
        expect(daysBetween("2026-04-01", "2026-05-01")).toBe(30);
        expect(daysBetween("2026-04-01", "2026-04-01")).toBe(0);
    });

    it("todayIsoJst: JST 換算で日付を返す", () => {
        // UTC 2026-04-29 22:00 = JST 2026-04-30 07:00 → "2026-04-30"
        expect(todayIsoJst(new Date("2026-04-29T22:00:00Z"))).toBe("2026-04-30");
        // UTC 2026-04-30 14:00 = JST 2026-04-30 23:00 → "2026-04-30"
        expect(todayIsoJst(new Date("2026-04-30T14:00:00Z"))).toBe("2026-04-30");
        // UTC 2026-04-30 15:00 = JST 2026-05-01 00:00 → "2026-05-01"
        expect(todayIsoJst(new Date("2026-04-30T15:00:00Z"))).toBe("2026-05-01");
    });
});

// ============================================================================
// 施策 4A: shape 検証 (取得異常を「advisory 0 件 = 緑」へ黙って落とさないこと)
//
// **loadAuditJson 経由**でテストする。assertAuditSourceShape を単体で呼ぶだけだと、
// 実装者が export しただけで loadAuditJson から呼び忘れても検出できない (配線の空振り)。
// ============================================================================

describe("loadAuditJson の shape 検証 (fail-closed)", () => {
    /** オブジェクトを JSON 化して loadAuditJson を通す (配線込みで正規化結果を得る)。 */
    function loadFrom(source: AuditSource, json: unknown): NormalizedAdvisory[] {
        return load(source, JSON.stringify(json));
    }

    /** 一時ファイルへ内容を書いて loadAuditJson を呼ぶ (配線まで含めて検証する)。 */
    function load(source: AuditSource, contents: string): NormalizedAdvisory[] {
        const dir = mkdtempSync(join(tmpdir(), "audit-gate-shape-"));
        const tmp = join(dir, "audit.json");
        writeFileSync(tmp, contents);
        try {
            return loadAuditJson(tmp, source);
        } finally {
            rmSync(dir, { recursive: true, force: true });
        }
    }

    it("不正 JSON は throw する", () => {
        expect(() => load("pnpm-audit", "not json")).toThrow(/JSON parse failure/);
    });

    it("pnpm: ネットワークエラー形 {error:{...}} は throw する (shape 黙殺の穴が塞がった証明)", () => {
        // error シグナル検査が先に発火する (advisories 欠落でもあるので、どちらで止めても fail-closed)。
        expect(() => load("pnpm-audit", JSON.stringify({ error: { code: "ENETUNREACH" } })))
            .toThrow(/non-empty 'error' field/);
    });

    it("pnpm: error シグナル無しで advisories が欠落していても throw する", () => {
        expect(() => load("pnpm-audit", JSON.stringify({ metadata: { totalDependencies: 0 } })))
            .toThrow(/missing 'advisories'/);
    });

    it("composer: 空配列 {advisories: []} は throw しない (composer の正当な 0 件表現)", () => {
        // 実測: composer audit --format=json は advisory 0 件のとき
        // `{"advisories":[],"abandoned":[],"filter":[]}` を出す (PHP の空配列由来)。
        // ここを弾くと「全部解消した正常状態」が恒久的に赤くなる (偽赤)。
        expect(load("composer-audit", JSON.stringify({ advisories: [] }))).toEqual([]);
    });

    it("composer: 非空配列は throw する (黙って 0 件へ落ちる偽グリーン経路)", () => {
        // composer は非 0 件を必ず package キーの object で出す。非空配列は schema 不一致であり、
        // normalizeComposerAudit の Object.entries が index キーで走査して黙って 0 件になる。
        expect(() => load("composer-audit", JSON.stringify({ advisories: [{ advisoryId: "X" }] })))
            .toThrow(/must be an object when non-empty/);
    });

    it("pnpm: {advisories: []} は throw しない (pnpm は array 形も正当)", () => {
        expect(load("pnpm-audit", JSON.stringify({ advisories: [] }))).toEqual([]);
    });

    it("pnpm / composer: {advisories: {}} は throw しない (真の 0 件は緑)", () => {
        expect(load("pnpm-audit", JSON.stringify({ advisories: {} }))).toEqual([]);
        expect(load("composer-audit", JSON.stringify({ advisories: {} }))).toEqual([]);
    });

    it("top-level 配列は throw する", () => {
        for (const source of ["pnpm-audit", "composer-audit", "pip-audit"] as const) {
            expect(() => load(source, "[]")).toThrow(/expected a JSON object at top level/);
        }
    });

    it("composer: advisories の値が array でないと throw する (内部 schema 不整合)", () => {
        expect(() =>
            load("composer-audit", JSON.stringify({ advisories: { "vendor/pkg": { error: "unavailable" } } })),
        ).toThrow(/advisories\["vendor\/pkg"\] must be an array/);
    });

    it("pnpm: primitive / null の entry は throw する", () => {
        expect(() => load("pnpm-audit", JSON.stringify({ advisories: [null] })))
            .toThrow(/advisories\[0\] must be an object/);
        expect(() => load("pnpm-audit", JSON.stringify({ advisories: ["x"] })))
            .toThrow(/advisories\[0\] must be an object/);
    });

    it("pip: {dependencies: []} は throw しない", () => {
        expect(load("pip-audit", JSON.stringify({ dependencies: [] }))).toEqual([]);
    });

    it("pip: name 欠落の dependency は throw する", () => {
        expect(() => load("pip-audit", JSON.stringify({ dependencies: [{}] })))
            .toThrow(/dependencies\[0\]\.name must be a string/);
    });

    it("pip: 空 vulns は正当な 0 件として通す", () => {
        expect(load("pip-audit", JSON.stringify({ dependencies: [{ name: "x", vulns: [] }] }))).toEqual([]);
    });

    it("pip: dependencies 欠落は throw する", () => {
        expect(() => load("pip-audit", "{}")).toThrow(/missing 'dependencies' array/);
    });

    it("error-bearing output は空コンテナでも throw する (impl-review R1 [Critical])", () => {
        // 「有効 JSON だが取得失敗を示す形」が正当な 0 件として通ると偽グリーンになる。
        expect(() => load("pnpm-audit", JSON.stringify({ advisories: {}, error: { code: "ENETUNREACH" } })))
            .toThrow(/non-empty 'error' field/);
        expect(() => load("composer-audit", JSON.stringify({ advisories: [], error: "registry unreachable" })))
            .toThrow(/non-empty 'error' field/);
        expect(() => load("pip-audit", JSON.stringify({ dependencies: [], errors: ["boom"] })))
            .toThrow(/non-empty 'errors' field/);
    });

    it("空の error フィールドは通す (偽赤にしない)", () => {
        expect(load("pnpm-audit", JSON.stringify({ advisories: {}, error: null }))).toEqual([]);
        expect(load("composer-audit", JSON.stringify({ advisories: [], error: {} }))).toEqual([]);
        expect(load("pip-audit", JSON.stringify({ dependencies: [], errors: [] }))).toEqual([]);
    });

    it("入れ子の error-bearing entry は 0 件へ落ちず high として gate を落とす (fail-closed の実証)", () => {
        // impl-review R2 [Critical] の検証。`{"advisories":{"pkg":[{"error":"..."}]}}` のような
        // **要素レベルで壊れた** 入力は shape 検査を通過するが、偽グリーンにはならない:
        // normalizer の severity unknown → high fail-safe により high advisory になり、
        // かつ id 欠損 advisory は **evaluate() の同定不能チェック** により accept-risk 不可なので
        // 必ず fail する。
        // (注: AcceptedAdvisorySchema の id = min(1) は空文字しか弾かない。
        //  `id: "fallback:<missing-key>"` と書けば schema 自体は通るため、
        //  受容不能性を保証しているのは schema ではなく evaluate() 側の検査である。
        //  impl-review R3/R4 で判明。下の 2 テストがその経路を固定している。)
        //
        // shape 層で advisory 要素の必須フィールドまで検証しない理由:
        // 判定 (severity 決定・受容可否) は audit-gate.ts の判定層の責務であり、
        // shape 層へ持ち込むと判定ロジックの二重管理になる (責務境界を壊す)。
        // ここでは「その分担でも fail-closed が成立する」ことをテストで固定する。
        const cases: Array<[string, NormalizedAdvisory[]]> = [
            ["composer", loadFrom("composer-audit", { advisories: { "vendor/pkg": [{ error: "unavailable" }] } })],
            ["pnpm", loadFrom("pnpm-audit", { advisories: [{ error: "boom" }] })],
            ["pip", loadFrom("pip-audit", { dependencies: [{ name: "x", vulns: [{ error: "boom" }] }] })],
        ];

        for (const [label, advisories] of cases) {
            expect(advisories, `${label}: 0 件へ落ちてはならない`).toHaveLength(1);
            expect(advisories[0].severity, `${label}: unknown severity は high fail-safe`).toBe("high");
            expect(advisories[0].id, `${label}: id は欠損 (= accept-risk 不可)`).toBe("");

            const result = evaluate(advisories, [], TODAY);
            expect(result.exitCode, `${label}: gate は fail しなければならない`).toBe(1);
            expect(result.failures.length).toBeGreaterThan(0);
        }
    });

    it("id 欠損 advisory は accept-risk で黙らせられない (空 id は schema が弾く)", () => {
        expect(() =>
            AcceptedAdvisorySchema.parse({
                id: "",
                package: "vendor/pkg",
                ecosystem: "composer",
                severity: "high",
                owner: "o",
                approved_at: "2026-08-01",
                expiry: "2026-08-20",
                rationale: "r",
                approved_by: "o",
                compensating_controls: "c",
                tracking_issue: "t",
            }),
        ).toThrow();
    });

    it("id 欠損 advisory は fallback キーを直接書いた accept-risk でも黙らせられない", () => {
        // impl-review R3 [Critical] の回帰テスト。
        // matchKey は id が空の advisory を `<eco>|<pkg>|fallback:<missing-key>` へ落とすが、
        // accept-risk 側は `id: "fallback:<missing-key>"` と**書くだけで同じキーを合成できる**。
        // 修正前はこれで exitCode=0 になり、壊れた audit 出力を受容で黙らせられた。
        const advisories = loadFrom("composer-audit", {
            advisories: { "vendor/pkg": [{ error: "unavailable" }] },
        });
        const accepted = AcceptedAdvisorySchema.parse({
            id: "fallback:<missing-key>",
            package: "vendor/pkg",
            ecosystem: "composer",
            severity: "high",
            owner: "o",
            approved_at: "2026-04-15",
            expiry: "2026-05-10",
            rationale: "r",
            approved_by: "o",
            compensating_controls: "c",
            tracking_issue: "t",
        });

        // 照合キーは一致してしまう (= schema だけでは防げないことの明示)
        expect(matchKey(advisories[0])).toBe(matchKey({ ...accepted, packageName: accepted.package }));

        // それでも gate は落ちなければならない
        const result = evaluate(advisories, [accepted], TODAY);
        expect(result.exitCode).toBe(1);
        expect(result.failures.some((f) => f.includes("unidentifiable advisory"))).toBe(true);
    });

    it("id 欠損は severity が moderate / low でも exitCode=1 になる (同定不能性そのもので落とす)", () => {
        // impl-review R4 [Critical] の回帰テスト。
        // id 欠損検査を severity filter の内側に置くと、**明示 severity を持つ壊れた entry** が
        // step をすり抜けて moderate warn (exit 0) に落ちる。実測で確認済み。
        // 「unknown severity → high」という別の防壁に依存させないため、severity に関係なく落とす。
        for (const severity of ["moderate", "low"] as const) {
            const advisories = loadFrom("pnpm-audit", {
                advisories: [{ error: "boom", severity }],
            });

            expect(advisories).toHaveLength(1);
            expect(advisories[0].id).toBe("");
            expect(advisories[0].severity, `明示 severity (${severity}) は維持される`).toBe(severity);

            const result = evaluate(advisories, [], TODAY);
            expect(result.exitCode, `severity=${severity} でも fail すること`).toBe(1);
            expect(result.failures.some((f) => f.includes("unidentifiable advisory"))).toBe(true);
            // warn へ逃がさない (warn は exit 0 なので偽グリーンになる)
            expect(result.moderateWarns).toHaveLength(0);
        }
    });

    it("NORMALIZERS が全 AuditSource を網羅している (誤配線を型と assertion で固定)", () => {
        const sources: AuditSource[] = ["pnpm-audit", "composer-audit", "pip-audit"];
        expect(Object.keys(NORMALIZERS).sort()).toEqual([...sources].sort());
    });
});
