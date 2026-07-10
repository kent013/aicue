import { BIN_NAME, ENV } from "../../../branding.js";
import { apiGet, formatFailure } from "../../../api/client.js";
import { MeResponseSchema } from "../../../api/schemas.js";
import { ExitCode, exitWith } from "../../../exit-codes.js";
import { formatJson } from "../../../output/json.js";
import { readOAuthToken } from "../../../oauth/token-store.js";
import { AuthCommand } from "../../base/AuthCommand.js";
import { profileFlags, readOutputFlags } from "../../base/flags.js";

type AuthMethod = "oauth" | "api-key" | "none";

/**
 * `auth:status` — show the authentication state of a profile.
 *
 * Reports the auth method (oauth / api-key), the server-side whoami
 * (organization + scopes), and, for OAuth, the access-token expiry. The
 * `/me` call may trigger a silent refresh, so the expiry is re-read after
 * the call to reflect the rotated token.
 */
export default class AuthStatus extends AuthCommand {
    static override description =
        "Show authentication status (method, organization, scopes, token expiry).";

    // API キー profile でも状態表示できるよう ephemeral 制約を解除する。
    protected override persistentRequired = false;

    static override flags = {
        ...AuthCommand.baseFlags,
        "api-key": profileFlags["api-key"],
        json: readOutputFlags.json,
    };

    public async run(): Promise<void> {
        const { flags } = await this.parse(AuthStatus);
        this.latchCiFlag(flags.ci);

        const { ctx, store } = await this.resolveOAuthContext(flags, {
            printBanner: flags.json !== true,
        });

        const method: AuthMethod =
            ctx.apiKey !== null
                ? "api-key"
                : ctx.oauthTokenProvider !== undefined
                  ? "oauth"
                  : "none";

        if (method === "none") {
            console.error(
                `Profile "${ctx.name}" is not authenticated. `
                    + `Run \`${BIN_NAME} auth:login --profile ${ctx.name}\` or `
                    + `set an API key (--api-key / ${ENV.API_KEY}).`,
            );
            exitWith(ExitCode.GeneralError);
        }

        const result = await apiGet(ctx, "/me", MeResponseSchema);
        if (!result.ok) {
            console.error(formatFailure(result));
            exitWith(ExitCode.GeneralError);
        }
        const me = result.data.data;

        // /me may have refreshed the token (rotation); re-read for expiry.
        const latest = readOAuthToken(store, ctx.canonicalOrigin, ctx.name);
        const expiresAt =
            latest !== null ? new Date(latest.expiresAt).toISOString() : null;

        if (flags.json === true) {
            this.log(
                formatJson({
                    profile: ctx.name,
                    method,
                    accessTokenExpiresAt: expiresAt,
                    me,
                }),
            );
            return;
        }

        this.log(formatStatus(ctx.name, method, expiresAt, me));
    }
}

type MeData = {
    organizationName: string;
    organizationId: number;
    plan: string;
    session?: { id: string; scopes: string[] } | null | undefined;
    apiKey?: { name: string } | null | undefined;
};

/**
 * Format the human-readable status block (pure, exported for tests).
 */
export function formatStatus(
    profileName: string,
    method: AuthMethod,
    expiresAt: string | null,
    me: MeData,
): string {
    const lines = [
        `Profile      : ${profileName}`,
        `Auth method  : ${method}`,
        `Organization : ${me.organizationName} (id: ${String(me.organizationId)})`,
        `Plan         : ${me.plan}`,
    ];
    if (me.session != null) {
        lines.push(`Session      : ${me.session.id}`);
        lines.push(`Scopes       : ${me.session.scopes.join(", ")}`);
    }
    if (me.apiKey != null) {
        lines.push(`API Key      : ${me.apiKey.name}`);
    }
    if (expiresAt !== null) {
        const remainingMs = Date.parse(expiresAt) - Date.now();
        const remaining = Number.isNaN(remainingMs)
            ? "unknown"
            : remainingMs <= 0
              ? "expired (auto-refresh on next call)"
              : `${String(Math.floor(remainingMs / 60000))} min`;
        lines.push(`Access token : expires ${expiresAt} (${remaining})`);
    }
    return lines.join("\n");
}
