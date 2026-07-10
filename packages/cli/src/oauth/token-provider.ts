import { BIN_NAME } from "../branding.js";
import type { Dispatcher } from "undici";
import type { CredentialStore } from "../credential/store.js";
import { buildDispatcher } from "../http/client.js";
import type { ResolvedConnectionOptions } from "../profile/context.js";
import { withRefreshLock } from "./refresh-lock.js";
import { OAuthTokenError, refreshAccessToken } from "./token-client.js";
import {
    readOAuthToken,
    refreshLockPath,
    writeOAuthToken,
    type OAuthTokenBundle,
} from "./token-store.js";

/**
 * Dynamic OAuth access-token supplier for the API v1 client (T715 Phase 3).
 *
 * Produces a `() => Promise<string>` that the HTTP client calls before each
 * request. On every call it:
 *   1. reads the latest stored bundle (follows another process's rotation),
 *   2. refreshes (rotation) if `expires_at - 60 s` has passed,
 *      under a file lock + re-read so concurrent CLIs don't clobber each
 *      other's refresh token,
 *   3. returns the access token.
 *
 * A failed refresh whose OAuth error is `invalid_grant` (rotated-out
 * refresh token / revoked session / membership change) is normalised to
 * {@link CliReauthenticationRequiredError} so the CLI can tell the user to
 * run `${BIN_NAME} login` again instead of leaking a raw OAuth error.
 */

export class CliReauthenticationRequiredError extends Error {
    public constructor(message: string) {
        super(message);
        this.name = "CliReauthenticationRequiredError";
    }
}

const REFRESH_MARGIN_MS = 60_000;

export type OAuthTokenProviderOptions = {
    store: CredentialStore;
    canonicalOrigin: string;
    profileName: string;
    baseUrl: string;
    /**
     * Connection options (proxy / CA / insecure) for the refresh request.
     * When omitted a dispatcher is not built and undici's defaults apply
     * (used by tests that intercept the token endpoint directly).
     */
    connectionOptions?: ResolvedConnectionOptions;
    /** Explicit dispatcher override (tests). Takes precedence over options. */
    dispatcher?: Dispatcher;
    /** Override the refresh-lock base path (tests). */
    lockBasePath?: string;
};

function isFresh(bundle: OAuthTokenBundle, now: number): boolean {
    return now < bundle.expiresAt - REFRESH_MARGIN_MS;
}

export function createOAuthTokenProvider(
    options: OAuthTokenProviderOptions,
): () => Promise<string> {
    const lockBasePath =
        options.lockBasePath
        ?? refreshLockPath(options.canonicalOrigin, options.profileName);

    const readEntry = (): OAuthTokenBundle => {
        const bundle = readOAuthToken(
            options.store,
            options.canonicalOrigin,
            options.profileName,
        );
        if (bundle === null) {
            throw new CliReauthenticationRequiredError(
                `Profile "${options.profileName}" is not signed in with OAuth. `
                    + `Run \`${BIN_NAME} auth:login\`.`,
            );
        }
        return bundle;
    };

    return async (): Promise<string> => {
        const entry = readEntry();
        if (isFresh(entry, Date.now())) {
            return entry.accessToken;
        }

        return withRefreshLock(lockBasePath, async (): Promise<string> => {
            // Another process may have refreshed while we waited for the
            // lock: re-read and re-check before spending a refresh token.
            const latest = readEntry();
            if (isFresh(latest, Date.now())) {
                return latest.accessToken;
            }

            // Build a dispatcher from connection options so the refresh
            // honours proxy / CA / insecure settings; close it afterwards
            // to avoid leaking sockets. An explicit dispatcher (tests) is
            // used as-is and left for the caller to manage.
            const ownDispatcher =
                options.dispatcher === undefined
                && options.connectionOptions !== undefined
                    ? buildDispatcher(options.connectionOptions)
                    : null;
            const dispatcher = options.dispatcher ?? ownDispatcher ?? undefined;

            let refreshed;
            try {
                const refreshOptions: Parameters<
                    typeof refreshAccessToken
                >[0] = {
                    baseUrl: options.baseUrl,
                    clientId: latest.clientId,
                    refreshToken: latest.refreshToken,
                };
                if (dispatcher !== undefined) {
                    refreshOptions.dispatcher = dispatcher;
                }
                refreshed = await refreshAccessToken(refreshOptions);
            } catch (error) {
                if (
                    error instanceof OAuthTokenError
                    && error.oauthError === "invalid_grant"
                ) {
                    throw new CliReauthenticationRequiredError(
                        "The session is no longer valid (revoked, expired, or "
                            + `membership changed). Run \`${BIN_NAME} auth:login\` to sign `
                            + "in again.",
                    );
                }
                throw error;
            } finally {
                if (ownDispatcher !== null) {
                    try {
                        await ownDispatcher.close();
                    } catch {
                        /* already closed */
                    }
                }
            }

            const nextBundle: OAuthTokenBundle = {
                accessToken: refreshed.accessToken,
                refreshToken: refreshed.refreshToken,
                expiresAt: refreshed.expiresAt,
                clientId: latest.clientId,
                sessionId: latest.sessionId,
                scopes: latest.scopes,
            };
            await writeOAuthToken(
                options.store,
                options.canonicalOrigin,
                options.profileName,
                nextBundle,
            );

            return refreshed.accessToken;
        });
    };
}
