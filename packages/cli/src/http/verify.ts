import {
    ExitCode,
    exitWith,
    type ExitCodeValue,
} from "../exit-codes.js";
import type { ResolvedConnectionOptions } from "../profile/context.js";
import type { VerificationMetadata } from "../profile/patch-types.js";
import { MeResponseSchema } from "../api/schemas.js";
import type { EnvironmentTagSource } from "../schemas/environment-tag-source.js";
import { fetchJsonValidated } from "./fetch-json.js";
import { VersionResponseSchema } from "./schemas.js";

export type VerifyFailure =
    | { kind: "network"; status: number; reason: string }
    | { kind: "insecure-http"; apiUrl: string }
    | { kind: "api-version-mismatch"; actual: string; required: string }
    | { kind: "min-cli-version"; required: string; current: string }
    | { kind: "api-key-rejected"; status: number }
    | {
          kind: "env-tag-mismatch";
          expected: string;
          actual: string | null;
          source: string;
      }
    | { kind: "schema-violation"; endpoint: string; reason: string };

export type VerifyOutcome =
    | { ok: true; result: VerificationMetadata; drift: boolean }
    | { ok: false; failure: VerifyFailure };

/** Compare semver-like version strings; returns true if a > b. */
export function semverGt(a: string, b: string): boolean {
    const pa = a.split(".").map((x) => Number.parseInt(x, 10) || 0);
    const pb = b.split(".").map((x) => Number.parseInt(x, 10) || 0);
    const len = Math.max(pa.length, pb.length);
    for (let i = 0; i < len; i++) {
        const av = pa[i] ?? 0;
        const bv = pb[i] ?? 0;
        if (av > bv) return true;
        if (av < bv) return false;
    }
    return false;
}

function trimSlash(url: string): string {
    return url.replace(/\/+$/, "");
}

/**
 * Pure verification core. NEVER exits the process. Callers pick exit codes
 * via the wrappers `verifyOrExitGranular` / `verifyOrExitRollback`.
 */
export async function verifyConnectionCore(
    apiUrl: string,
    apiKey: string | null,
    opts: ResolvedConnectionOptions,
    expectedEnvTag: string | null,
    cliVersion: string,
): Promise<VerifyOutcome> {
    if (apiUrl.startsWith("http://") && !opts.allow_insecure) {
        return {
            ok: false,
            failure: { kind: "insecure-http", apiUrl },
        };
    }

    const vRes = await fetchJsonValidated(
        trimSlash(apiUrl) + "/version",
        opts,
        {},
        VersionResponseSchema,
    );
    if (!vRes.ok) {
        if (vRes.status === -1) {
            return {
                ok: false,
                failure: {
                    kind: "schema-violation",
                    endpoint: "/version",
                    reason: vRes.reason,
                },
            };
        }
        return {
            ok: false,
            failure: {
                kind: "network",
                status: vRes.status,
                reason: vRes.reason,
            },
        };
    }
    const v = vRes.data.data;
    if (v.api_version !== "v1") {
        return {
            ok: false,
            failure: {
                kind: "api-version-mismatch",
                actual: v.api_version,
                required: "v1",
            },
        };
    }
    if (semverGt(v.min_cli_version, cliVersion)) {
        return {
            ok: false,
            failure: {
                kind: "min-cli-version",
                required: v.min_cli_version,
                current: cliVersion,
            },
        };
    }

    let envTag = v.environment_tag;
    let envSource: EnvironmentTagSource = v.environment_tag_source;
    let drift = false;

    if (apiKey) {
        const mRes = await fetchJsonValidated(
            trimSlash(apiUrl) + "/me",
            opts,
            { Authorization: `Bearer ${apiKey}` },
            MeResponseSchema,
        );
        if (!mRes.ok) {
            if (mRes.status === 401 || mRes.status === 403) {
                return {
                    ok: false,
                    failure: { kind: "api-key-rejected", status: mRes.status },
                };
            }
            if (mRes.status === -1) {
                return {
                    ok: false,
                    failure: {
                        kind: "schema-violation",
                        endpoint: "/me",
                        reason: mRes.reason,
                    },
                };
            }
            return {
                ok: false,
                failure: {
                    kind: "network",
                    status: mRes.status,
                    reason: mRes.reason,
                },
            };
        }
        const me = mRes.data.data;
        if (
            me.environmentTag !== v.environment_tag
            || me.environmentTagSource !== v.environment_tag_source
        ) {
            drift = true;
            envTag = me.environmentTag;
            envSource = me.environmentTagSource;
        }
    }

    if (expectedEnvTag !== null && envTag !== expectedEnvTag) {
        return {
            ok: false,
            failure: {
                kind: "env-tag-mismatch",
                expected: expectedEnvTag,
                actual: envTag,
                source: envSource,
            },
        };
    }

    return {
        ok: true,
        drift,
        result: {
            environment_tag: envTag,
            environment_tag_source: envSource,
            capabilities: v.capabilities,
            instance_id: v.instance_id,
            server_version: v.server_version,
            last_verified_at: new Date().toISOString(),
        },
    };
}

export function mapFailureToGranular(f: VerifyFailure): ExitCodeValue {
    switch (f.kind) {
        case "network":
        case "insecure-http":
        case "api-key-rejected":
        case "schema-violation":
            return ExitCode.ConnectionFailed;
        case "env-tag-mismatch":
            return ExitCode.EnvironmentTagMismatch;
        case "api-version-mismatch":
        case "min-cli-version":
            return ExitCode.CapabilityMissing;
    }
}

export function formatFailure(f: VerifyFailure): string {
    switch (f.kind) {
        case "network":
            return `Connection failed (status ${String(f.status)}): ${f.reason}`;
        case "insecure-http":
            return (
                `Refusing to connect to http:// without --allow-insecure: `
                + f.apiUrl
            );
        case "api-version-mismatch":
            return `Server api_version=${f.actual}, required=${f.required}.`;
        case "min-cli-version":
            return (
                `Server requires CLI >= ${f.required} (current: ${f.current}).`
            );
        case "api-key-rejected":
            return `API key rejected (HTTP ${String(f.status)}).`;
        case "env-tag-mismatch":
            return (
                `environment_tag mismatch: expected="${f.expected}", `
                + `actual="${String(f.actual)}" (source: ${f.source}).`
            );
        case "schema-violation":
            return `Response schema violation at ${f.endpoint}: ${f.reason}`;
    }
}

function printDriftWarning(): void {
    console.error(
        "Warning: /version and /me report different environment_tag; "
            + "using /me as the authoritative source.",
    );
}

export async function verifyOrExitGranular(
    apiUrl: string,
    apiKey: string | null,
    opts: ResolvedConnectionOptions,
    expectedEnvTag: string | null,
    cliVersion: string,
): Promise<VerificationMetadata> {
    const o = await verifyConnectionCore(
        apiUrl,
        apiKey,
        opts,
        expectedEnvTag,
        cliVersion,
    );
    if (o.ok) {
        if (o.drift) printDriftWarning();
        return o.result;
    }
    console.error(formatFailure(o.failure));
    exitWith(mapFailureToGranular(o.failure));
}

export async function verifyOrExitRollback(
    apiUrl: string,
    apiKey: string | null,
    opts: ResolvedConnectionOptions,
    expectedEnvTag: string | null,
    cliVersion: string,
): Promise<VerificationMetadata> {
    const o = await verifyConnectionCore(
        apiUrl,
        apiKey,
        opts,
        expectedEnvTag,
        cliVersion,
    );
    if (o.ok) {
        if (o.drift) printDriftWarning();
        return o.result;
    }
    console.error(formatFailure(o.failure));
    console.error("Verification failed; rolling back (no disk changes).");
    exitWith(ExitCode.ProfileVerifyRollback);
}
