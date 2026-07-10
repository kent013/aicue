import { fetch as undiciFetch, type Dispatcher } from "undici";

/**
 * `/oauth/token` client (T715 Phase 3).
 *
 * Supports the two grants the CLI uses: authorization_code (PKCE) and
 * refresh_token. This is intentionally separate from the API v1 client
 * (`api/client.ts`): the token endpoint is `application/x-www-form-urlencoded`
 * and is unauthenticated (public PKCE client), so it shares none of the
 * Bearer / envelope / Zod machinery of the data-plane client.
 */

export type TokenResponse = {
    accessToken: string;
    refreshToken: string;
    /** epoch ms, derived from `expires_in` (seconds). */
    expiresAt: number;
    /**
     * Space-delimited granted scopes parsed into a list when the server
     * returns the RFC 6749 §5.1 `scope` field. `null` when omitted (the
     * caller then keeps the requested scopes as the best estimate).
     */
    grantedScopes: string[] | null;
};

export class OAuthTokenError extends Error {
    public constructor(
        message: string,
        public readonly status: number,
        public readonly oauthError?: string,
    ) {
        super(message);
        this.name = "OAuthTokenError";
    }
}

type TokenEndpointBody = {
    access_token?: unknown;
    refresh_token?: unknown;
    expires_in?: unknown;
    scope?: unknown;
    error?: unknown;
    error_description?: unknown;
};

async function postTokenEndpoint(
    baseUrl: string,
    params: Record<string, string>,
    dispatcher?: Dispatcher,
): Promise<TokenResponse> {
    const url = `${baseUrl.replace(/\/+$/, "")}/oauth/token`;
    const init: {
        method: string;
        headers: Record<string, string>;
        body: string;
        dispatcher?: Dispatcher;
    } = {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            Accept: "application/json",
        },
        body: new URLSearchParams(params).toString(),
    };
    if (dispatcher !== undefined) init.dispatcher = dispatcher;
    const response = await undiciFetch(url, init);

    const body = (await response
        .json()
        .catch(() => ({}))) as TokenEndpointBody;

    if (!response.ok) {
        const oauthError =
            typeof body.error === "string" ? body.error : undefined;
        const description =
            typeof body.error_description === "string"
                ? `: ${body.error_description}`
                : "";
        throw new OAuthTokenError(
            `Token endpoint returned HTTP ${String(response.status)}`
                + (oauthError !== undefined ? ` (${oauthError})` : "")
                + description,
            response.status,
            oauthError,
        );
    }

    if (
        typeof body.access_token !== "string"
        || typeof body.refresh_token !== "string"
    ) {
        throw new OAuthTokenError(
            "Token endpoint response is missing access_token / refresh_token.",
            response.status,
        );
    }
    const expiresIn =
        typeof body.expires_in === "number" ? body.expires_in : 3600;
    const grantedScopes =
        typeof body.scope === "string" && body.scope.trim() !== ""
            ? body.scope.trim().split(/\s+/)
            : null;

    return {
        accessToken: body.access_token,
        refreshToken: body.refresh_token,
        expiresAt: Date.now() + expiresIn * 1000,
        grantedScopes,
    };
}

export async function exchangeAuthorizationCode(options: {
    baseUrl: string;
    clientId: string;
    code: string;
    codeVerifier: string;
    redirectUri: string;
    dispatcher?: Dispatcher;
}): Promise<TokenResponse> {
    return postTokenEndpoint(
        options.baseUrl,
        {
            grant_type: "authorization_code",
            client_id: options.clientId,
            code: options.code,
            code_verifier: options.codeVerifier,
            redirect_uri: options.redirectUri,
        },
        options.dispatcher,
    );
}

export async function refreshAccessToken(options: {
    baseUrl: string;
    clientId: string;
    refreshToken: string;
    dispatcher?: Dispatcher;
}): Promise<TokenResponse> {
    return postTokenEndpoint(
        options.baseUrl,
        {
            grant_type: "refresh_token",
            client_id: options.clientId,
            refresh_token: options.refreshToken,
        },
        options.dispatcher,
    );
}
