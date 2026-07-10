import { ENV } from "../../branding.js";
import { loadAllScopes } from "../../config/loader.js";
import { CredentialStore } from "../../credential/store.js";
import { ExitCode, exitWith } from "../../exit-codes.js";
import {
    assertPersistentProfileRequired,
    type ResolvedProfileContext,
} from "../../profile/context.js";
import { resolveProfile, type ApiKeyLoader } from "../../profile/resolve.js";
import { FileProfileWriter, type ProfileWriter } from "../../profile/writer.js";
import { setGlobalAllowPlaintextFlag } from "../../runtime-state.js";
import { createOAuthTokenProvider } from "../../oauth/token-provider.js";
import { readOAuthToken } from "../../oauth/token-store.js";

export type ProfileResolveInput = {
    profile?: string | undefined;
    apiUrl?: string | undefined;
    apiKey?: string | undefined;
    allowPlaintextCredentials?: boolean | undefined;
};

export type ProfileResolveOptions = {
    /** `"always"` always calls `resolveProfile`; `"if-needed"` skips
     *  resolution when the command acts purely on local profile config. */
    resolveMode: "always" | "if-needed";
    /** When true, `--api-url` / `${ENV.API_URL}` is refused with exit 17. */
    persistentRequired: boolean;
    /** Default `true`. Caller can suppress the backend banner for
     *  machine-readable modes (`--json` / `--quiet`). */
    printBanner?: boolean;
};

export type ProfileResolveBundle = {
    ctx: ResolvedProfileContext | null;
    writer: ProfileWriter;
    store: CredentialStore;
};

/**
 * Shared profile-resolution helper used by both {@link ProfileCommand}
 * and {@link ReadCommand}. Mirrors the behaviour of the legacy
 * `defineProfileCommand` / `defineReadCommand` wrappers without forcing
 * the two base classes to collapse into one — each still owns its flag
 * surface and failure wording.
 *
 * Responsibilities:
 *   - propagate `--allow-plaintext-credentials` to the runtime latch,
 *   - construct a `CredentialStore` and optionally print the backend banner,
 *   - refuse the `--profile` × `--api-url` conflict (exit 10),
 *   - run `resolveProfile` when `resolveMode === "always"`,
 *   - gate ephemeral intent when `persistentRequired && resolveMode ===
 *     "if-needed"` (exit 17).
 *
 * **Not handled here**: `--json`/`--quiet` interpretation, API-key
 * requirement. Those are caller-specific contracts; keeping them in the
 * caller avoids cross-wiring the two command-type contracts through this
 * helper.
 */
export async function resolveProfileBundle(
    input: ProfileResolveInput,
    options: ProfileResolveOptions,
): Promise<ProfileResolveBundle> {
    if (input.allowPlaintextCredentials === true) {
        setGlobalAllowPlaintextFlag(true);
    }
    const store = new CredentialStore();
    // F-0-05: the backend banner is no longer printed eagerly here. It is
    // emitted lazily by `CredentialStore.prepareForAccess` at the moment a
    // credential is first actually read or written, so read-only / local-only
    // commands never see the UNAVAILABLE noise.
    const writer = new FileProfileWriter();

    if (input.profile !== undefined && input.apiUrl !== undefined) {
        console.error(
            "Error: --profile and --api-url cannot be used together.",
        );
        exitWith(ExitCode.ProfileConflict);
    }

    let ctx: ResolvedProfileContext | null = null;
    const hasEphemeralIntent =
        input.apiUrl !== undefined
        || process.env[ENV.API_URL] !== undefined;

    if (options.resolveMode === "always") {
        const { user, project } = loadAllScopes();
        const printBanner = options.printBanner !== false;
        const loader: ApiKeyLoader = (name, origin) =>
            loadApiKey(store, origin, name, printBanner);
        const argv: { profile?: string; apiUrl?: string; apiKey?: string } = {};
        if (input.profile !== undefined) argv.profile = input.profile;
        if (input.apiUrl !== undefined) argv.apiUrl = input.apiUrl;
        if (input.apiKey !== undefined) argv.apiKey = input.apiKey;
        ctx = await resolveProfile(argv, process.env, user, project, loader);
        attachOAuthTokenProvider(ctx, store);
        if (options.persistentRequired) {
            assertPersistentProfileRequired(ctx);
        }
    } else if (options.persistentRequired && hasEphemeralIntent) {
        console.error(
            "Error: this command requires a persistent profile; "
                + `remove --api-url or unset ${ENV.API_URL}.`,
        );
        exitWith(ExitCode.EphemeralRestricted);
    }

    return { ctx, writer, store };
}

/**
 * Credential-read facade for named profiles (F-0-05). Runs the shared
 * `prepareForAccess` ordering (lazy backend warning → master-key preflight)
 * before the synchronous `read`, so an encrypted-store read no longer crashes
 * with an internal "master key not loaded" error when the preflight was never
 * wired (the old eager-banner path read without a preflight).
 */
async function loadApiKey(
    store: CredentialStore,
    canonicalOrigin: string,
    profileName: string,
    printBanner: boolean,
): Promise<string | null> {
    await store.prepareForAccess(canonicalOrigin, profileName, { printBanner });
    return store.read(canonicalOrigin, profileName, "apikey", "");
}

/**
 * Attach the OAuth user-token provider to a resolved context (T715 Phase 3).
 *
 * Only fires when the profile has no API key (key wins, so existing users
 * are unaffected) and an OAuth token bundle is actually stored. The
 * provider reads the latest bundle and refreshes it under a file lock on
 * demand, so the API client can inject `Bearer <access token>` without the
 * command knowing about OAuth at all.
 */
function attachOAuthTokenProvider(
    ctx: ResolvedProfileContext,
    store: CredentialStore,
): void {
    if (ctx.apiKey !== null) return;
    const bundle = readOAuthToken(store, ctx.canonicalOrigin, ctx.name);
    if (bundle === null) return;
    ctx.oauthTokenProvider = createOAuthTokenProvider({
        store,
        canonicalOrigin: ctx.canonicalOrigin,
        profileName: ctx.name,
        baseUrl: ctx.apiUrl,
        connectionOptions: ctx.options,
    });
}
