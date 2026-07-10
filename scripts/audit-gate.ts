/**
 * Supply-chain audit gate.
 *
 * 各 ecosystem (npm/Composer、pyproject.toml がある場合は PyPI も) の
 * audit JSON 出力を統合し、`docs/supply-chain/accepted-advisories.yaml` の
 * expiry / cleanup 検査と severity 判定を行う。
 *
 * - high+ で fail
 * - moderate は Summary に列挙して trackable warn
 * - accept-risk は YAML 全 entry の expiry を常時検査 (過去日 entry 1 件で fail)
 * - 解消済み accepted entry は cleanup 強制で fail
 * - severity rank で迂回受容を阻止 (accepted < advisory なら fail)
 * - 全 severity で max-days を機械強制 (low/moderate=90, high=30, critical=14、JST 基準) + approved_at 妥当性検査
 * - PyPI / npm / Composer の severity unknown は high 扱いで fail-safe
 *
 * 実行は `pnpm run audit:gate` (= scripts/audit-gate.sh) 経由が基本。
 * pip-audit JSON (第 3 引数) は pyproject.toml があるリポジトリでのみ渡される。
 * 運用ルールは docs/supply-chain/review-checklist.md を参照。
 */

import { appendFileSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import { parse as parseYaml } from "yaml";
import { z } from "zod";

// ============================================================================
// Types & schemas
// ============================================================================

const EcosystemEnum = z.enum(["npm", "composer", "pypi"]);

// ISO-8601 (YYYY-MM-DD) + round-trip 検証。`2026-99-99` のような不正日付を弾く
const Iso8601Date = z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, "ISO-8601 date YYYY-MM-DD")
    .superRefine((s, ctx) => {
        const d = new Date(`${s}T00:00:00Z`);
        if (Number.isNaN(d.getTime()) || d.toISOString().slice(0, 10) !== s) {
            ctx.addIssue({ code: "custom", message: `invalid calendar date: ${s}` });
        }
    });

// approved_at は全 severity で必須 (low/moderate も含めて max-days を機械強制するため)。
// これにより MAX_ACCEPT_DAYS 全段が常に適用される (gate bypass 不可)。
const BaseAcceptedAdvisorySchema = z.object({
    id: z.string().min(1),
    package: z.string().min(1),
    ecosystem: EcosystemEnum,
    owner: z.string().min(1),
    approved_at: Iso8601Date,
    expiry: Iso8601Date,
    rationale: z.string().min(1),
});

const ModerateAcceptedAdvisorySchema = BaseAcceptedAdvisorySchema.extend({
    severity: z.enum(["low", "moderate"]),
});

const HighAcceptedAdvisorySchema = BaseAcceptedAdvisorySchema.extend({
    severity: z.enum(["high", "critical"]),
    approved_by: z.string().min(1),
    compensating_controls: z.string().min(1),
    tracking_issue: z.string().min(1),
});

const AcceptedAdvisorySchema = z.discriminatedUnion("severity", [
    ModerateAcceptedAdvisorySchema,
    HighAcceptedAdvisorySchema,
]);

const AcceptedAdvisoriesFileSchema = z.array(AcceptedAdvisorySchema);

type AcceptedAdvisory = z.infer<typeof AcceptedAdvisorySchema>;

interface NormalizedAdvisory {
    id: string;
    packageName: string;
    ecosystem: "npm" | "composer" | "pypi";
    severity: "low" | "moderate" | "high" | "critical";
    source: "pnpm-audit" | "composer-audit" | "pip-audit";
    title?: string;
    url?: string;
}

interface GateResult {
    exitCode: 0 | 1;
    failures: string[];
    moderateWarns: NormalizedAdvisory[];
    cleanupCandidates: AcceptedAdvisory[];
}

// severity rank (順序比較用)
const SEVERITY_RANK: Record<NormalizedAdvisory["severity"], number> = {
    low: 0,
    moderate: 1,
    high: 2,
    critical: 3,
};

// 運用上限 (review-checklist §3 と一致)
const MAX_ACCEPT_DAYS: Record<NormalizedAdvisory["severity"], number> = {
    low: 90,
    moderate: 90,
    high: 30,
    critical: 14,
};

// ============================================================================
// Utilities
// ============================================================================

function canonicalize(s: string): string {
    return s.trim().toLowerCase();
}

/**
 * 突合キー: ecosystem + package + id
 * - 大小文字・余白の差異を吸収
 * - id 欠損時は source + url(or title) を fallback キーに
 *
 * **id 欠損 advisory の accept-risk 仕様**:
 *   id 欠損 advisory は schema 上 accept-risk 不可 (`AcceptedAdvisorySchema.id` は `z.string().min(1)`)。
 *   normalizer 側で upstream ID (GHSA-* / CVE-* / PYSEC-*) を補完できるまで gate を fail させる方針。
 */
export function matchKey(a: {
    id?: string;
    ecosystem?: string;
    packageName?: string;
    package?: string;
    source?: string;
    url?: string;
    title?: string;
}): string {
    const eco = canonicalize(a.ecosystem ?? "<missing-ecosystem>");
    const pkg = canonicalize(a.packageName ?? a.package ?? "<missing-package>");
    if (a.id && a.id.trim().length > 0) {
        return `${eco}|${pkg}|${canonicalize(a.id)}`;
    }
    const fallback = a.url ?? a.title ?? "<missing-key>";
    return `${eco}|${pkg}|fallback:${canonicalize(fallback)}`;
}

/**
 * JST (UTC+9) 基準の today を YYYY-MM-DD で返す。
 * 日本チーム運用のため accept-risk 期限は JST で評価する。
 */
export function todayIsoJst(now: Date): string {
    const jstOffsetMs = 9 * 60 * 60 * 1000;
    const jst = new Date(now.getTime() + jstOffsetMs);
    return jst.toISOString().slice(0, 10);
}

/**
 * 開始日を含めない差分日数 (exclusive start)。
 * `approved_at=2026-04-01, expiry=2026-05-01` → 30 日 (high の上限ちょうど pass)。
 * `> maxDays` で違反判定 → ちょうど maxDays は OK。
 */
export function daysBetween(fromIso: string, toIso: string): number {
    const ms = Date.parse(toIso) - Date.parse(fromIso);
    return Math.floor(ms / (24 * 60 * 60 * 1000));
}

// ============================================================================
// Loaders
// ============================================================================

export function loadAuditJson(
    path: string,
    normalizer: (json: unknown) => NormalizedAdvisory[],
): NormalizedAdvisory[] {
    const raw = readFileSync(path, "utf-8");
    let json: unknown;
    try {
        json = JSON.parse(raw);
    } catch (e) {
        throw new Error(`JSON parse failure in ${path}: ${(e as Error).message}`);
    }
    return normalizer(json);
}

export function loadAcceptedAdvisories(path: string): AcceptedAdvisory[] {
    const raw = readFileSync(path, "utf-8");
    const parsed = parseYaml(raw) ?? [];
    return AcceptedAdvisoriesFileSchema.parse(parsed);
}

// ============================================================================
// Normalizers
// ============================================================================

/**
 * pnpm audit JSON の severity 値を NormalizedAdvisory severity に変換。
 * 不明値は **`high` 扱い**で fail-safe (過小評価による gate bypass を避ける)。
 */
function normalizeNpmSeverity(s: unknown): NormalizedAdvisory["severity"] {
    const v = typeof s === "string" ? s.toLowerCase() : "";
    if (v === "low" || v === "moderate" || v === "high" || v === "critical") return v;
    return "high";
}

/**
 * pnpm audit --json の出力を正規化。
 * pnpm CLI バージョン間で `advisories` が object と array 両方ありうる。
 */
export function normalizePnpmAudit(json: unknown): NormalizedAdvisory[] {
    if (!json || typeof json !== "object") return [];
    const obj = json as { advisories?: unknown };
    if (!obj.advisories) return [];

    const entries: Array<Record<string, unknown>> = Array.isArray(obj.advisories)
        ? (obj.advisories as Array<Record<string, unknown>>)
        : Object.values(obj.advisories as Record<string, unknown>) as Array<Record<string, unknown>>;

    return entries.map((e) => {
        const id = typeof e.github_advisory_id === "string"
            ? e.github_advisory_id
            : typeof e.id === "string"
                ? e.id
                : typeof e.id === "number"
                    ? String(e.id)
                    : "";
        const packageName = typeof e.module_name === "string"
            ? e.module_name
            : typeof e.name === "string"
                ? e.name
                : "";
        return {
            id,
            packageName,
            ecosystem: "npm" as const,
            severity: normalizeNpmSeverity(e.severity),
            source: "pnpm-audit" as const,
            title: typeof e.title === "string" ? e.title : undefined,
            url: typeof e.url === "string" ? e.url : undefined,
        };
    });
}

/**
 * Composer audit の severity (`low/medium/high/critical`) を NormalizedAdvisory severity に変換。
 * 不明値は **`high` 扱い**で fail-safe (過小評価による gate bypass を避ける)。
 */
function normalizeComposerSeverity(s: unknown): NormalizedAdvisory["severity"] {
    const v = typeof s === "string" ? s.toLowerCase() : "";
    if (v === "low" || v === "high" || v === "critical") return v;
    if (v === "medium" || v === "moderate") return "moderate";
    return "high";
}

/**
 * composer audit --format=json の出力を正規化。
 * 構造: { advisories: { [pkg]: [ { advisoryId, cve, link, title, severity, ... } ] } }
 */
export function normalizeComposerAudit(json: unknown): NormalizedAdvisory[] {
    if (!json || typeof json !== "object") return [];
    const obj = json as { advisories?: unknown };
    if (!obj.advisories || typeof obj.advisories !== "object") return [];

    const result: NormalizedAdvisory[] = [];
    for (const [pkg, advisoriesUnknown] of Object.entries(obj.advisories as Record<string, unknown>)) {
        const advisories = Array.isArray(advisoriesUnknown) ? advisoriesUnknown : [];
        for (const a of advisories) {
            if (!a || typeof a !== "object") continue;
            const adv = a as Record<string, unknown>;
            const id = typeof adv.advisoryId === "string"
                ? adv.advisoryId
                : typeof adv.cve === "string"
                    ? adv.cve
                    : "";
            result.push({
                id,
                packageName: pkg,
                ecosystem: "composer" as const,
                severity: normalizeComposerSeverity(adv.severity),
                source: "composer-audit" as const,
                title: typeof adv.title === "string" ? adv.title : undefined,
                url: typeof adv.link === "string" ? adv.link : undefined,
            });
        }
    }
    return result;
}

/**
 * pip-audit --format=json の出力を正規化。
 * pip-audit JSON は severity フィールドを持たないため、
 * unknown は **`high` 扱い**で fail-safe (過小評価を避けて gate を通過させない)。
 *
 * 構造: { dependencies: [ { name, version, vulns: [ { id, fix_versions, description } ] } ] }
 */
export function normalizePipAudit(json: unknown): NormalizedAdvisory[] {
    if (!json || typeof json !== "object") return [];
    const obj = json as { dependencies?: unknown };
    if (!Array.isArray(obj.dependencies)) return [];

    const result: NormalizedAdvisory[] = [];
    for (const dep of obj.dependencies as Array<Record<string, unknown>>) {
        if (!dep || typeof dep !== "object") continue;
        const name = typeof dep.name === "string" ? dep.name : "";
        const vulns = Array.isArray(dep.vulns) ? dep.vulns : [];
        for (const v of vulns as Array<Record<string, unknown>>) {
            if (!v || typeof v !== "object") continue;
            const id = typeof v.id === "string" ? v.id : "";
            result.push({
                id,
                packageName: name,
                ecosystem: "pypi" as const,
                severity: "high" as const, // fail-safe
                source: "pip-audit" as const,
                title: typeof v.description === "string" ? v.description : undefined,
            });
        }
    }
    return result;
}

// ============================================================================
// Evaluation
// ============================================================================

export function evaluate(
    advisories: NormalizedAdvisory[],
    accepted: AcceptedAdvisory[],
    now: Date = new Date(),
): GateResult {
    const failures: string[] = [];
    const moderateWarns: NormalizedAdvisory[] = [];
    const cleanupCandidates: AcceptedAdvisory[] = [];
    const today = todayIsoJst(now);

    const advisoryByKey = new Map(advisories.map((a) => [matchKey(a), a]));
    const acceptedByKey = new Map(accepted.map((e) => [matchKey({ ...e, packageName: e.package }), e]));

    // 1. expiry 切れ entry を YAML 全件で検査 (今日 > expiry なら fail、同日は OK)
    for (const entry of accepted) {
        if (entry.expiry < today) {
            failures.push(
                `expired accept-risk entry: ${matchKey({ ...entry, packageName: entry.package })} ` +
                    `(expiry=${entry.expiry}, today=${today})`,
            );
        }
    }

    // 2. 解消済み accepted entry を fail (cleanup 強制)
    for (const entry of accepted) {
        const key = matchKey({ ...entry, packageName: entry.package });
        if (!advisoryByKey.has(key)) {
            cleanupCandidates.push(entry);
            failures.push(
                `accepted entry no longer matches any advisory (cleanup required): ${key}`,
            );
        }
    }

    // 3. accept-risk schema の整合性検査 (全 severity 共通)
    for (const entry of accepted) {
        const key = matchKey({ ...entry, packageName: entry.package });
        const adv = advisoryByKey.get(key);
        if (!adv) continue;

        // 3a. severity rank (advisory の severity より低い accepted は迂回受容)
        if (SEVERITY_RANK[entry.severity] < SEVERITY_RANK[adv.severity]) {
            failures.push(
                `severity mismatch: advisory ${key} is ${adv.severity} but accept-risk entry registers severity=${entry.severity}. ` +
                    `accepted severity must be >= advisory severity.`,
            );
        }

        // 3b. approved_at と expiry の妥当性 + 運用上限 (全 severity に対して機械強制)
        if (entry.approved_at > today) {
            failures.push(
                `accept-risk approved_at is in the future: ${key} (approved_at=${entry.approved_at})`,
            );
        }
        if (entry.expiry < entry.approved_at) {
            failures.push(
                `accept-risk expiry is before approved_at: ${key} (expiry=${entry.expiry}, approved_at=${entry.approved_at})`,
            );
        }
        const span = daysBetween(entry.approved_at, entry.expiry);
        const maxDays = MAX_ACCEPT_DAYS[entry.severity];
        if (span > maxDays) {
            failures.push(
                `accept-risk window exceeds policy: ${key} severity=${entry.severity} ` +
                    `span=${span}d (max=${maxDays}d for ${entry.severity}). ` +
                    `For 0day emergency, span must be <= 7d (see review-checklist §5).`,
            );
        }
    }

    // 4. 未受容 high/critical を fail
    for (const adv of advisories) {
        if (adv.severity !== "high" && adv.severity !== "critical") continue;
        const acceptedEntry = acceptedByKey.get(matchKey(adv));
        if (!acceptedEntry) {
            failures.push(`unaccepted ${adv.severity}: ${matchKey(adv)} (${adv.title ?? ""})`);
        }
    }

    // 5. 未受容 moderate を warn 集計
    for (const adv of advisories) {
        if (adv.severity !== "moderate") continue;
        const acceptedEntry = acceptedByKey.get(matchKey(adv));
        if (!acceptedEntry) {
            moderateWarns.push(adv);
        }
    }

    return {
        exitCode: failures.length > 0 ? 1 : 0,
        failures,
        moderateWarns,
        cleanupCandidates,
    };
}

// ============================================================================
// Summary
// ============================================================================

function severityCount(advisories: NormalizedAdvisory[]): Record<NormalizedAdvisory["severity"], number> {
    const c = { low: 0, moderate: 0, high: 0, critical: 0 };
    for (const a of advisories) c[a.severity]++;
    return c;
}

export function writeSummary(result: GateResult, allAdvisories: NormalizedAdvisory[]): string {
    const lines: string[] = [];
    lines.push("## Supply-chain audit gate");
    lines.push("");
    const counts = severityCount(allAdvisories);
    lines.push(`Total advisories: ${allAdvisories.length} (low=${counts.low}, moderate=${counts.moderate}, high=${counts.high}, critical=${counts.critical})`);
    lines.push("");

    if (result.failures.length > 0) {
        lines.push(`### ❌ Failures (${result.failures.length})`);
        lines.push("");
        for (const f of result.failures) {
            lines.push(`- ${f}`);
        }
        lines.push("");
    } else {
        lines.push("### ✓ Gate passed");
        lines.push("");
    }

    if (result.moderateWarns.length > 0) {
        lines.push(`### ⚠ Moderate advisories (${result.moderateWarns.length})`);
        lines.push("");
        lines.push("Consider adding to `docs/supply-chain/accepted-advisories.yaml` with rationale, or upgrading the package.");
        lines.push("");
        for (const a of result.moderateWarns) {
            lines.push(`- [${a.ecosystem}] ${a.packageName}: ${a.id}${a.title ? ` — ${a.title}` : ""}`);
        }
        lines.push("");
    }

    if (result.cleanupCandidates.length > 0) {
        lines.push(`### 🧹 Cleanup candidates (${result.cleanupCandidates.length})`);
        lines.push("");
        lines.push("These accepted entries no longer match any current advisory. Remove them from `accepted-advisories.yaml`.");
        lines.push("");
        for (const e of result.cleanupCandidates) {
            lines.push(`- [${e.ecosystem}] ${e.package}: ${e.id}`);
        }
        lines.push("");
    }

    return lines.join("\n");
}

// ============================================================================
// CLI entry
// ============================================================================

async function main(): Promise<void> {
    // pip.json は pyproject.toml があるリポジトリでのみ渡される (省略可)
    const [pnpmPath, composerPath, pipPath] = process.argv.slice(2);

    if (!pnpmPath || !composerPath) {
        console.error("usage: tsx scripts/audit-gate.ts <pnpm.json> <composer.json> [pip.json]");
        console.error("env: ACCEPTED_ADVISORIES_PATH (default: docs/supply-chain/accepted-advisories.yaml)");
        process.exit(1);
    }

    const acceptedPath = process.env.ACCEPTED_ADVISORIES_PATH ?? "docs/supply-chain/accepted-advisories.yaml";

    let advisories: NormalizedAdvisory[];
    let accepted: AcceptedAdvisory[];
    try {
        advisories = [
            ...loadAuditJson(pnpmPath, normalizePnpmAudit),
            ...loadAuditJson(composerPath, normalizeComposerAudit),
            ...(pipPath ? loadAuditJson(pipPath, normalizePipAudit) : []),
        ];
        accepted = loadAcceptedAdvisories(acceptedPath);
    } catch (e) {
        console.error(`audit-gate: input load failed: ${(e as Error).message}`);
        process.exit(1);
    }

    const result = evaluate(advisories, accepted);

    const summary = writeSummary(result, advisories);
    if (process.env.GITHUB_STEP_SUMMARY) {
        appendFileSync(process.env.GITHUB_STEP_SUMMARY, summary);
    } else {
        console.log(summary);
    }

    if (result.failures.length > 0) {
        console.error("\n=== FAILURES ===");
        for (const f of result.failures) console.error(`  - ${f}`);
    }

    process.exit(result.exitCode);
}

/**
 * CLI として直接実行された場合のみ main() を呼ぶ。
 * vitest 等から import された場合は main() が走らない (process.exit でテストが落ちないように)
 */
export function isCliEntryPoint(
    importMetaUrl: string = import.meta.url,
    entry: string | undefined = process.argv[1],
): boolean {
    if (!entry) return false;
    return importMetaUrl === pathToFileURL(resolve(entry)).href;
}

if (isCliEntryPoint()) {
    void main();
}

// テスト用 export
export { canonicalize };
export { AcceptedAdvisorySchema, AcceptedAdvisoriesFileSchema };
export type { NormalizedAdvisory, AcceptedAdvisory, GateResult };
