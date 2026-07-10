import { Flags } from "@oclif/core";
import { ENV, DISPLAY_NAME } from "../../../branding.js";
import { ExitCode, exitWith } from "../../../exit-codes.js";
import { buildDispatcher } from "../../../http/client.js";
import { resolveCliOAuthClientId } from "../../../oauth/client-id.js";
import { loginWithPkce } from "../../../oauth/login.js";
import { openInBrowser } from "../../../oauth/browser.js";
import { AuthCommand } from "../../base/AuthCommand.js";

/**
 * `auth:login` — browser-based OAuth sign-in.
 *
 * Authorization Code + PKCE over a loopback redirect:
 *   1. start a `127.0.0.1` callback server on an ephemeral port
 *   2. open `/oauth/authorize` in the browser (`--no-browser` prints the URL)
 *   3. complete web login + org selection + scope consent
 *   4. exchange the code and store the token bundle in the credential store
 *      (same backend as the API key); thereafter the token auto-refreshes
 *
 * 自動化用途は引き続き API キー (`--api-key` / `${ENV.API_KEY}` /
 * `profile:set-key`) を使う。
 */
export default class AuthLogin extends AuthCommand {
    static override description =
        `Sign in to ${DISPLAY_NAME} with OAuth (browser + PKCE).`;

    static override examples = [
        "<%= config.bin %> auth:login --profile staging",
        "<%= config.bin %> auth:login --profile staging --no-browser",
    ];

    static override flags = {
        ...AuthCommand.baseFlags,
        "no-browser": Flags.boolean({
            description: "print the authorize URL instead of opening a browser",
        }),
        "client-id": Flags.string({
            description:
                "OAuth client id (overrides server discovery / "
                + `${ENV.OAUTH_CLIENT_ID})`,
        }),
    };

    public async run(): Promise<void> {
        const { flags } = await this.parse(AuthLogin);
        this.latchCiFlag(flags.ci);

        const { ctx, store } = await this.resolveOAuthContext(flags);

        const resolution = await resolveCliOAuthClientId({
            apiUrl: ctx.apiUrl,
            options: ctx.options,
            flagClientId: flags["client-id"],
            env: process.env,
        });
        if (!resolution.ok) {
            console.error(`Error: ${resolution.reason}`);
            exitWith(ExitCode.GeneralError);
        }

        this.log(
            `Signing in to ${ctx.apiUrl} (profile "${ctx.name}") ...`,
        );

        const noBrowser = flags["no-browser"] === true;
        // Build a dispatcher from the profile's connection options so the
        // token exchange honours --allow-insecure / ca_bundle / proxy, the
        // same as every other API call (self-signed CA / corporate proxy
        // environments must not have `login` silently bypass them).
        const dispatcher = buildDispatcher(ctx.options);
        let result;
        try {
            result = await loginWithPkce({
                store,
                canonicalOrigin: ctx.canonicalOrigin,
                profileName: ctx.name,
                baseUrl: ctx.apiUrl,
                clientId: resolution.clientId,
                dispatcher,
                openAuthorizationUrl: async (url): Promise<void> => {
                    if (noBrowser) {
                        this.log(
                            "\nOpen this URL in your browser to sign in:\n",
                        );
                        this.log(`  ${url}\n`);
                        return;
                    }
                    const opened = await openInBrowser(url);
                    if (opened) {
                        this.log(
                            "Opened your browser. Complete login and consent ...",
                        );
                    } else {
                        this.log(
                            "\nCould not open a browser automatically. "
                                + "Open this URL manually:\n",
                        );
                        this.log(`  ${url}\n`);
                    }
                },
            });
        } finally {
            try {
                await dispatcher.close();
            } catch {
                /* already closed */
            }
        }

        const expiresAt = new Date(result.bundle.expiresAt).toISOString();
        this.log(
            `Login succeeded for profile "${ctx.name}". `
                + `Access token expires at ${expiresAt} (auto-refresh enabled).`,
        );
    }
}
