import { ENV, BIN_NAME } from "../branding.js";
import { fetchJsonValidated } from "../http/fetch-json.js";
import { VersionResponseSchema } from "../http/schemas.js";
import type { ResolvedConnectionOptions } from "../profile/context.js";

/**
 * Resolve the public PKCE client id for `${BIN_NAME} login` (T715 Phase 3).
 *
 * Resolution order (first hit wins):
 *   1. explicit `--client-id`
 *   2. `${ENV.OAUTH_CLIENT_ID}` env
 *   3. the server's `GET /version` → `cli_oauth_client_id`
 *
 * The version discovery is best-effort: a server that doesn't advertise the
 * field (or is unreachable) simply yields `null`, and the caller surfaces a
 * clear "configure the client id" error rather than crashing.
 */

export type ClientIdSource = "flag" | "env" | "version";

export type ClientIdResolution =
    | { ok: true; clientId: string; source: ClientIdSource }
    | { ok: false; reason: string };

export async function resolveCliOAuthClientId(input: {
    apiUrl: string;
    options: ResolvedConnectionOptions;
    flagClientId?: string | undefined;
    env: NodeJS.ProcessEnv;
}): Promise<ClientIdResolution> {
    if (input.flagClientId !== undefined && input.flagClientId !== "") {
        return { ok: true, clientId: input.flagClientId, source: "flag" };
    }
    const envClientId = input.env[ENV.OAUTH_CLIENT_ID];
    if (envClientId !== undefined && envClientId !== "") {
        return { ok: true, clientId: envClientId, source: "env" };
    }

    const fromVersion = await fetchClientIdFromVersion(
        input.apiUrl,
        input.options,
    );
    if (fromVersion !== null) {
        return { ok: true, clientId: fromVersion, source: "version" };
    }

    return {
        ok: false,
        reason:
            "Could not determine the CLI OAuth client id. The server does not "
            + "advertise `cli_oauth_client_id` (run `php artisan cli:client` on "
            + `the server), or pass --client-id / set ${ENV.OAUTH_CLIENT_ID}.`,
    };
}

async function fetchClientIdFromVersion(
    apiUrl: string,
    options: ResolvedConnectionOptions,
): Promise<string | null> {
    const url = `${apiUrl.replace(/\/+$/, "")}/version`;
    const result = await fetchJsonValidated(
        url,
        options,
        { Accept: "application/json" },
        VersionResponseSchema,
    );
    if (!result.ok) return null;
    const clientId = result.data.data.cli_oauth_client_id;
    return clientId !== undefined && clientId !== null && clientId !== ""
        ? clientId
        : null;
}
