import { ENV, BIN_NAME } from "../branding.js";
import { ExitCode, exitWith } from "../exit-codes.js";

export type ResolvedConnectionOptions = {
    ca_bundle: string | null;
    http_proxy: string | null;
    https_proxy: string | null;
    allow_insecure: boolean;
    timeout_ms: number;
    retry_max: number;
    retry_backoff_ms: number;
};

export type ResolvedBy =
    | "argv-profile"
    | "argv-api-url"
    | `env-${(typeof ENV)["PROFILE"]}`
    | `env-${(typeof ENV)["API_URL"]}`
    | "project-profile"
    | "user-default-profile"
    | "builtin-production";

export type ResolvedProfileContext = {
    kind: "named" | "ephemeral";
    name: string;
    canonicalOrigin: string;
    apiUrl: string;
    options: ResolvedConnectionOptions;
    apiKey: string | null;
    expectedEnvTag: string | null;
    resolvedBy: ResolvedBy;
    /**
     * T715 Phase 3: dynamic OAuth user-token supplier. When set (and no
     * API key is present), the API client injects `Bearer <access token>`
     * resolved per-request, refreshing under a file lock as needed. API
     * key takes precedence so existing `--api-key` / `${ENV.API_KEY}` /
     * stored-key users see no behaviour change.
     */
    oauthTokenProvider?: (() => Promise<string>) | undefined;
};

export function defaultConnectionOptions(): ResolvedConnectionOptions {
    return {
        ca_bundle: null,
        http_proxy: null,
        https_proxy: null,
        allow_insecure: false,
        timeout_ms: 30000,
        retry_max: 2,
        retry_backoff_ms: 1000,
    };
}

export function assertPersistentProfileRequired(
    ctx: ResolvedProfileContext,
): void {
    if (ctx.kind === "ephemeral") {
        console.error(
            "Error: this command requires a persistent profile "
                + `(current: ephemeral, resolved by ${ctx.resolvedBy}). `
                + `Run \`${BIN_NAME} profile:add <name> --api-url <url>\` first.`,
        );
        exitWith(ExitCode.EphemeralRestricted);
    }
}
