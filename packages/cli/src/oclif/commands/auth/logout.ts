import { apiDeleteJson, formatFailure } from "../../../api/client.js";
import { SessionRevokedResponseSchema } from "../../../api/schemas.js";
import { createOAuthTokenProvider } from "../../../oauth/token-provider.js";
import { deleteOAuthToken, readOAuthToken } from "../../../oauth/token-store.js";
import type { ResolvedProfileContext } from "../../../profile/context.js";
import { AuthCommand } from "../../base/AuthCommand.js";

/**
 * `auth:logout` — revoke the server-side OAuth session and delete the
 * local token.
 *
 * - OAuth profile: `DELETE /api/v1/me/session` then remove the stored
 *   bundle. The local token is removed even when the server is unreachable
 *   (with a warning) so a stale token never lingers.
 * - API key profile: nothing to revoke here (revoke the key from the web /
 *   admin); reports that and exits cleanly.
 */
export default class AuthLogout extends AuthCommand {
    static override description =
        "Sign out: revoke the server session and remove the local OAuth token.";

    static override flags = {
        ...AuthCommand.baseFlags,
    };

    public async run(): Promise<void> {
        const { flags } = await this.parse(AuthLogout);
        this.latchCiFlag(flags.ci);

        const { ctx, store } = await this.resolveOAuthContext(flags);

        const bundle = readOAuthToken(store, ctx.canonicalOrigin, ctx.name);
        if (bundle === null) {
            this.log(
                `Profile "${ctx.name}" has no OAuth token. Nothing to do `
                    + "(automation API keys are revoked from the web/admin).",
            );
            return;
        }

        // `/me/session` is OAuth-only. Force the OAuth bearer for the
        // revoke even if this profile also has an API key configured (the
        // default precedence is API key first), otherwise the server would
        // reject the API key on this endpoint and the session would be left
        // un-revoked while the local token is removed below.
        const oauthCtx: ResolvedProfileContext = {
            ...ctx,
            apiKey: null,
            oauthTokenProvider: createOAuthTokenProvider({
                store,
                canonicalOrigin: ctx.canonicalOrigin,
                profileName: ctx.name,
                baseUrl: ctx.apiUrl,
                connectionOptions: ctx.options,
            }),
        };
        const result = await apiDeleteJson(
            oauthCtx,
            "/me/session",
            SessionRevokedResponseSchema,
        );
        if (result.ok) {
            this.log(
                `Server session revoked (session: ${result.data.data.sessionId}).`,
            );
        } else {
            console.error(
                `Warning: could not revoke the server session. `
                    + `${formatFailure(result)} `
                    + "Removing the local token anyway.",
            );
        }

        deleteOAuthToken(store, ctx.canonicalOrigin, ctx.name);
        this.log(`Removed local OAuth token for profile "${ctx.name}".`);
    }
}
