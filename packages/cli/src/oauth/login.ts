import type { Dispatcher } from "undici";
import type { CredentialStore } from "../credential/store.js";
import { startLoopbackServer } from "./loopback-server.js";
import {
    codeChallengeS256,
    generateCodeVerifier,
    generateState,
} from "./pkce.js";
import { exchangeAuthorizationCode } from "./token-client.js";
import { writeOAuthToken, type OAuthTokenBundle } from "./token-store.js";

/**
 * Orchestrates the CLI OAuth login flow — Authorization Code + PKCE with a
 * loopback redirect (T715 Phase 3, RFC 8252).
 *
 * 1. generate code_verifier / S256 challenge / state
 * 2. start a loopback server on `127.0.0.1` (ephemeral port)
 * 3. open `/oauth/authorize` in the browser (delegated to
 *    `openAuthorizationUrl`; the command falls back to printing the URL)
 * 4. receive `code` + `state` on the callback (state mismatch aborts)
 * 5. `POST /oauth/token` (with code_verifier) and persist the token bundle
 *
 * The caller resolves `clientId` (from `/version`, `--client-id`, or
 * the `ENV.OAUTH_CLIENT_ID` env var) — see `resolveCliOAuthClientId`.
 */

export type LoginResult = {
    bundle: OAuthTokenBundle;
};

export type LoginWithPkceOptions = {
    store: CredentialStore;
    canonicalOrigin: string;
    profileName: string;
    baseUrl: string;
    clientId: string;
    /** Open the authorize URL (browser launch or URL print). */
    openAuthorizationUrl: (url: string) => Promise<void>;
    /** Requested scopes; defaults to the CLI standard vocabulary. */
    scopes?: readonly string[];
    timeoutMs?: number;
    dispatcher?: Dispatcher;
};

/**
 * Scopes the CLI requests by default.
 *
 * **This is not a mirror of the server enum `App\Enums\OAuth\CliOAuthScope`** —
 * that one is "every scope the server recognises", this one is "the permissions
 * the CLI asks for by default". Different concepts. The relation between them is
 * **subset** (only values inside the server value range may appear here), pinned
 * by `tests/js/architecture/enum-ts-sync.test.ts` with `relation: "subset"`.
 * Writing a scope the server does not register turns that gate red.
 * **The server growing a new scope does not oblige the CLI to request it**
 * (least privilege).
 *
 * `cli:use` is required by the server's actor resolver; `read` / `write` map to
 * the REST ability vocabulary and `session.revoke` covers CLI sign-out.
 * T261: `evaluations:run` and `pages:bulk` were removed — the server
 * (`McpPassportServiceProvider`) never registered them, so requesting them was
 * either rejected or silently dropped.
 */
export const DEFAULT_CLI_SCOPES = [
    "cli:use",
    "read",
    "write",
    "session.revoke",
] as const;

function trimSlash(url: string): string {
    return url.replace(/\/+$/, "");
}

export async function loginWithPkce(
    options: LoginWithPkceOptions,
): Promise<LoginResult> {
    const codeVerifier = generateCodeVerifier();
    const state = generateState();
    const scopes = options.scopes ?? DEFAULT_CLI_SCOPES;

    const loopbackOptions: { expectedState: string; timeoutMs?: number } = {
        expectedState: state,
    };
    if (options.timeoutMs !== undefined) {
        loopbackOptions.timeoutMs = options.timeoutMs;
    }
    const loopback = await startLoopbackServer(loopbackOptions);

    try {
        const authorizeUrl =
            `${trimSlash(options.baseUrl)}/oauth/authorize?`
            + new URLSearchParams({
                client_id: options.clientId,
                redirect_uri: loopback.redirectUri,
                response_type: "code",
                scope: scopes.join(" "),
                state,
                code_challenge: codeChallengeS256(codeVerifier),
                code_challenge_method: "S256",
            }).toString();

        // Await the callback together with the URL-open so a fast (or, in
        // tests, synchronous) redirect can never settle the callback during
        // the gap between two sequential awaits (which would surface as a
        // transient unhandled rejection).
        const [{ code }] = await Promise.all([
            loopback.waitForCallback,
            options.openAuthorizationUrl(authorizeUrl),
        ]);

        const exchangeOptions: Parameters<
            typeof exchangeAuthorizationCode
        >[0] = {
            baseUrl: options.baseUrl,
            clientId: options.clientId,
            code,
            codeVerifier,
            redirectUri: loopback.redirectUri,
        };
        if (options.dispatcher !== undefined) {
            exchangeOptions.dispatcher = options.dispatcher;
        }
        const tokens = await exchangeAuthorizationCode(exchangeOptions);

        const bundle: OAuthTokenBundle = {
            accessToken: tokens.accessToken,
            refreshToken: tokens.refreshToken,
            expiresAt: tokens.expiresAt,
            clientId: options.clientId,
            sessionId: null,
            // Prefer the server-reported granted scopes; fall back to the
            // requested set when the token endpoint omits `scope`.
            scopes: tokens.grantedScopes ?? [...scopes],
        };
        await writeOAuthToken(
            options.store,
            options.canonicalOrigin,
            options.profileName,
            bundle,
        );

        return { bundle };
    } finally {
        loopback.close();
    }
}
