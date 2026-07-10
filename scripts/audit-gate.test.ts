/**
 * audit-gate.ts unit tests.
 *
 * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
 */
import { describe, expect, it } from "vitest";
import { writeFileSync, unlinkSync, mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import {
    AcceptedAdvisorySchema,
    daysBetween,
    evaluate,
    loadAuditJson,
    matchKey,
    normalizeComposerAudit,
    normalizePipAudit,
    normalizePnpmAudit,
    todayIsoJst,
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
            expect(() => loadAuditJson(tmp, normalizePnpmAudit)).toThrow(/JSON parse failure/);
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
