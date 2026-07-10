import { BIN_NAME } from "../branding.js";
import { join } from "node:path";
import { homedir } from "node:os";
import { z } from "zod";
import { deriveProfileHash12 } from "../credential/key-derivation.js";
import type { CredentialStore } from "../credential/store.js";

/**
 * Persistence for the CLI OAuth user-token (T715 Phase 3).
 *
 * Unlike per-site login secrets (`credential` items) and the automation
 * API key (`apikey` item), the OAuth token bundle is stored as a single
 * JSON blob under the credential store's *meta* namespace with the
 * reserved id {@link OAUTH_META_ID}. The meta namespace is deliberately
 * NOT enumerated by `listItems`, so the OAuth token never shows up in
 * `auth:list` / `profile:show` credential inventories — it is internal
 * plumbing that the API client consumes transparently.
 *
 * The same `CredentialStore` (keychain → file-encrypted → file-plaintext)
 * that protects the API key protects the token bundle, so file permissions
 * / encryption are inherited rather than re-implemented here.
 */

export const OAUTH_META_ID = "oauth:token";

/**
 * On-disk OAuth token bundle. `expiresAt` is epoch milliseconds so the
 * token provider can compare against `Date.now()` without re-parsing an
 * ISO string on every API call. `sessionId` mirrors the server-side
 * `oauth_sessions.id` so `status` / `logout` can show and reason about it
 * (it is informational only — the server re-derives the binding from the
 * access token).
 */
export const OAuthTokenBundleSchema = z
    .object({
        accessToken: z.string().min(1),
        refreshToken: z.string().min(1),
        expiresAt: z.number().int().nonnegative(),
        /** Public PKCE client id, persisted so refresh needs no rediscovery. */
        clientId: z.string().min(1),
        sessionId: z.string().nullable().default(null),
        scopes: z.array(z.string()).default([]),
    })
    .strict();

export type OAuthTokenBundle = z.infer<typeof OAuthTokenBundleSchema>;

/**
 * Read the OAuth token bundle for a profile, or `null` when none is stored
 * or the persisted blob fails the schema (treated as absent so a corrupt
 * write degrades to "please log in again" rather than a hard crash).
 */
export function readOAuthToken(
    store: CredentialStore,
    canonicalOrigin: string,
    profileName: string,
): OAuthTokenBundle | null {
    const raw = store.readMeta(canonicalOrigin, profileName, OAUTH_META_ID);
    if (raw === null) return null;
    try {
        const parsed: unknown = JSON.parse(raw);
        const result = OAuthTokenBundleSchema.safeParse(parsed);
        return result.success ? result.data : null;
    } catch {
        return null;
    }
}

export async function writeOAuthToken(
    store: CredentialStore,
    canonicalOrigin: string,
    profileName: string,
    bundle: OAuthTokenBundle,
    opts: { printBanner?: boolean } = {},
): Promise<void> {
    // F-0-02: preflight the master key before the synchronous encrypted write.
    // F-0-05: `printBanner` flows through so a caller that passes
    // `printBanner:false` (a future quiet write path) stays silent; defaults
    // to printing for today's interactive callers.
    await store.writeMetaWithPreflight(
        canonicalOrigin,
        profileName,
        OAUTH_META_ID,
        JSON.stringify(OAuthTokenBundleSchema.parse(bundle)),
        opts,
    );
}

export function deleteOAuthToken(
    store: CredentialStore,
    canonicalOrigin: string,
    profileName: string,
): void {
    store.deleteMeta(canonicalOrigin, profileName, OAUTH_META_ID);
}

/**
 * Filesystem path of the per-profile refresh mutex (`*.lock`).
 *
 * The lock file is a cross-process mutex, not a credential, so it lives
 * next to the file-store data directory regardless of which backend
 * actually holds the token (keychain has no path of its own). The path is
 * derived from the profile hash so two profiles never share a lock.
 */
export function refreshLockPath(
    canonicalOrigin: string,
    profileName: string,
    baseDir: string = join(homedir(), `.${BIN_NAME}`, "credentials"),
): string {
    const hash = deriveProfileHash12(canonicalOrigin, profileName);
    return join(baseDir, hash, "oauth-token");
}
