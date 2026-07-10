import { ENV, BIN_NAME } from "../branding.js";
import type { ProfileEntry, RootConfigInput } from "../config/schema.js";
import { assertProfileHasApiUrl } from "./assertions.js";
import { canonicalOrigin } from "./canonical-origin.js";
import {
    defaultConnectionOptions,
    type ResolvedBy,
    type ResolvedConnectionOptions,
    type ResolvedProfileContext,
} from "./context.js";
import { ephemeralProfileName } from "./ephemeral.js";
import { ProfileResolutionError } from "./errors.js";

export type ResolveArgs = {
    profile?: string;
    apiUrl?: string;
    apiKey?: string;
};

/**
 * Optional hook to load an API key from the credential store for named
 * profiles. Kept as an injected dependency to avoid a direct profile ->
 * credential dependency (useful for tests too).
 */
export type ApiKeyLoader = (
    profileName: string,
    canonicalOrigin: string,
) => Promise<string | null>;

function mergeOptionsFromEntry(
    entry: ProfileEntry,
): ResolvedConnectionOptions {
    const defaults = defaultConnectionOptions();
    return {
        ca_bundle: entry.ca_bundle ?? defaults.ca_bundle,
        http_proxy: entry.http_proxy ?? defaults.http_proxy,
        https_proxy: entry.https_proxy ?? defaults.https_proxy,
        allow_insecure: entry.allow_insecure ?? defaults.allow_insecure,
        timeout_ms: entry.timeout_ms ?? defaults.timeout_ms,
        retry_max: entry.retry_max ?? defaults.retry_max,
        retry_backoff_ms: entry.retry_backoff_ms ?? defaults.retry_backoff_ms,
    };
}

function makeEphemeralContext(
    apiUrl: string,
    argv: ResolveArgs,
    env: NodeJS.ProcessEnv,
    source: "argv-api-url" | `env-${(typeof ENV)["API_URL"]}`,
): ResolvedProfileContext {
    const origin = canonicalOrigin(apiUrl);
    return {
        kind: "ephemeral",
        name: ephemeralProfileName(apiUrl),
        canonicalOrigin: origin,
        apiUrl,
        options: defaultConnectionOptions(),
        apiKey: argv.apiKey ?? env[ENV.API_KEY] ?? null,
        expectedEnvTag: null,
        resolvedBy: source,
    };
}

async function makeNamedContext(
    name: string,
    entry: ProfileEntry,
    argv: ResolveArgs,
    env: NodeJS.ProcessEnv,
    source: ResolvedBy,
    apiKeyLoader: ApiKeyLoader | undefined,
): Promise<ResolvedProfileContext> {
    const url = assertProfileHasApiUrl(entry, name);
    const origin = canonicalOrigin(url);
    const resolvedKey =
        argv.apiKey
        ?? env[ENV.API_KEY]
        ?? (apiKeyLoader ? await apiKeyLoader(name, origin) : null);
    return {
        kind: "named",
        name,
        canonicalOrigin: origin,
        apiUrl: url,
        options: mergeOptionsFromEntry(entry),
        apiKey: resolvedKey,
        expectedEnvTag: entry.expected_environment_tag ?? null,
        resolvedBy: source,
    };
}

/**
 * Resolve the active profile context from argv, env, and config files.
 *
 * Resolution order:
 *   1. --profile and --api-url together → exit 10
 *   2. APP_PROFILE + APP_API_URL pointing at different origins → exit 10
 *   3. --api-url (argv) or APP_API_URL → ephemeral context
 *   4. --profile (argv)
 *   5. APP_PROFILE
 *   6. project.profile (project-scope config)
 *   7. user.default_profile
 *   8. fallback "production" (builtin)
 */
export async function resolveProfile(
    argv: ResolveArgs,
    env: NodeJS.ProcessEnv,
    user: RootConfigInput | undefined,
    project: RootConfigInput | undefined,
    apiKeyLoader?: ApiKeyLoader,
): Promise<ResolvedProfileContext> {
    if (argv.profile && argv.apiUrl) {
        throw ProfileResolutionError.conflict(
            "--profile and --api-url cannot be used together.",
        );
    }

    const envProfile = env[ENV.PROFILE];
    const envApiUrl = env[ENV.API_URL];
    if (envProfile && envApiUrl) {
        const profileEntry = user?.profiles?.[envProfile];
        if (profileEntry?.api_url) {
            try {
                if (
                    canonicalOrigin(profileEntry.api_url)
                    !== canonicalOrigin(envApiUrl)
                ) {
                    throw ProfileResolutionError.conflict(
                        `${ENV.PROFILE} (${envProfile}) and ${ENV.API_URL} `
                            + `point at different origins (profile api_url=`
                            + `${profileEntry.api_url}, env=${envApiUrl}).`,
                    );
                }
            } catch (e) {
                if (e instanceof ProfileResolutionError) throw e;
                throw ProfileResolutionError.conflict(
                    `invalid api_url while comparing ${ENV.PROFILE} and `
                        + `${ENV.API_URL}: ${(e as Error).message}`,
                );
            }
        }
    }

    const apiUrlSource: "argv-api-url" | `env-${(typeof ENV)["API_URL"]}` | null = argv.apiUrl
        ? "argv-api-url"
        : envApiUrl
          ? `env-${ENV.API_URL}`
          : null;
    if (apiUrlSource) {
        const apiUrl = argv.apiUrl ?? envApiUrl;
        if (!apiUrl) {
            // unreachable by construction: apiUrlSource is only non-null
            // when one of the two inputs is a non-empty string.
            throw ProfileResolutionError.conflict(
                "internal error: api url source resolved without a value",
            );
        }
        return makeEphemeralContext(apiUrl, argv, env, apiUrlSource);
    }

    const profileSource: ResolvedBy = argv.profile
        ? "argv-profile"
        : envProfile
          ? `env-${ENV.PROFILE}`
          : project?.profile
            ? "project-profile"
            : user?.default_profile
              ? "user-default-profile"
              : "builtin-production";

    const profileName = argv.profile
        ?? envProfile
        ?? project?.profile
        ?? user?.default_profile
        ?? "production";

    const entry = user?.profiles?.[profileName];
    if (!entry) {
        throw ProfileResolutionError.notFound(
            `profile "${profileName}" not found in user config. `
                + `Run \`${BIN_NAME} profile:add ${profileName} --api-url <url>\` first.`,
        );
    }

    return await makeNamedContext(
        profileName,
        entry,
        argv,
        env,
        profileSource,
        apiKeyLoader,
    );
}
