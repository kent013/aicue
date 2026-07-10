// Process-wide cache of resolved key material, keyed by profile_hash12.
// CLI commands that use the encrypted file-store must call
// `ensureMasterKey(origin, profile)` before any synchronous
// `FileStore.write/read`. Subsequent calls re-use the cached source.
//
// T148 (YAGNI-09) removed the per-profile `.salt` sidecar file: each
// encrypted file now carries its own 16B salt in its header. The registry
// therefore only caches the user-supplied key material (env-key bytes, or
// the password string) plus a small {lastSalt → lastKey} memoization so a
// single command that reads multiple credentials does not re-run scrypt
// for every file with the same salt.

import { ENV } from "../branding.js";
import { ExitCode, exitWith } from "../exit-codes.js";
import { deriveProfileHash12 } from "./key-derivation.js";
import {
    deriveKeyFromSource,
    resolveKeySource,
    type KeySource,
    type KeySourceKind,
    type ResolveKeyOptions,
} from "./master-password.js";

// Human-facing message for "no master key loaded" (F-0-05). Deliberately
// does NOT name the internal `ensureMasterKey()` API — a missing preflight
// is now wired everywhere (D-3), so any user who hits this needs to set a
// key, not call an internal function. Shared by `getSourceOrExit` and
// `deriveKey` so the two cannot drift.
const MASTER_KEY_MISSING_MESSAGE =
    "Error: a master key is required to read encrypted credentials. "
    + `Set ${ENV.CREDENTIAL_KEY} or ${ENV.MASTER_PASSWORD} `
    + "(or use --allow-plaintext-credentials for an unencrypted store).";

type CacheEntry = {
    source: KeySource;
    // Single-slot memo: most commands read one credential per profile, so a
    // 1-entry cache is enough. We store bytes so subsequent reads against
    // the same file can skip the scrypt call entirely.
    lastSalt: Uint8Array | null;
    lastKey: Uint8Array | null;
};

export class MasterKeyRegistry {
    private readonly cache = new Map<string, CacheEntry>();

    /** Eject all cached sources + keys (test helper; overwrites bytes first). */
    clear(): void {
        for (const entry of this.cache.values()) {
            wipeCacheEntry(entry);
        }
        this.cache.clear();
    }

    /**
     * Resolve + cache the key source for (origin, profile). Must be called
     * before any synchronous file-store read/write for the same profile.
     */
    async ensure(
        canonicalOrigin: string,
        profileName: string,
        opts: ResolveKeyOptions = {},
    ): Promise<KeySource> {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        const cached = this.cache.get(ph12);
        if (cached !== undefined) return cached.source;
        const source = await resolveKeySource(opts);
        this.cache.set(ph12, {
            source,
            lastSalt: null,
            lastKey: null,
        });
        return source;
    }

    /**
     * Return the cached `KeySource` for (origin, profile). `null` when
     * `ensure` has not yet been awaited — callers must decide policy.
     */
    getSource(
        canonicalOrigin: string,
        profileName: string,
    ): KeySource | null {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        const entry = this.cache.get(ph12);
        return entry ? entry.source : null;
    }

    /**
     * Synchronous get-or-die for file-store internals. Exits with 32 when
     * the source is not yet cached — always a missing
     * `await ensureMasterKey(...)` call upstream.
     */
    getSourceOrExit(
        canonicalOrigin: string,
        profileName: string,
    ): KeySource {
        const s = this.getSource(canonicalOrigin, profileName);
        if (s !== null) return s;
        console.error(MASTER_KEY_MISSING_MESSAGE);
        exitWith(ExitCode.MasterPasswordRequired);
    }

    /**
     * Derive the 32B AES-256 key for the given (origin, profile, salt).
     * Memoized: when called twice with the same salt under the same cache
     * entry, the second call returns the previously-derived bytes without
     * re-running scrypt.
     */
    deriveKey(
        canonicalOrigin: string,
        profileName: string,
        salt: Uint8Array,
    ): Uint8Array {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        const entry = this.cache.get(ph12);
        if (entry === undefined) {
            console.error(MASTER_KEY_MISSING_MESSAGE);
            exitWith(ExitCode.MasterPasswordRequired);
        }
        if (
            entry.lastSalt !== null
            && entry.lastKey !== null
            && saltsEqual(entry.lastSalt, salt)
        ) {
            return entry.lastKey;
        }
        const key = deriveKeyFromSource(entry.source, salt);
        // Scrub the previous cached key before overwriting so its bytes do
        // not linger in memory. env-key sources cannot be scrubbed here —
        // they share their buffer with `entry.source.key` and live until
        // `clear()` wipes the source itself.
        if (
            entry.lastKey !== null
            && entry.source.kind !== "env-key"
        ) {
            entry.lastKey.fill(0);
        }
        entry.lastSalt = new Uint8Array(salt);
        entry.lastKey = key;
        return key;
    }

    /** Expose cached source kinds for diagnostics / testing. */
    sourceKind(
        canonicalOrigin: string,
        profileName: string,
    ): KeySourceKind | null {
        const s = this.getSource(canonicalOrigin, profileName);
        return s === null ? null : s.kind;
    }
}

function saltsEqual(a: Uint8Array, b: Uint8Array): boolean {
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i += 1) {
        if (a[i] !== b[i]) return false;
    }
    return true;
}

function wipeCacheEntry(entry: CacheEntry): void {
    if (entry.lastKey !== null) entry.lastKey.fill(0);
    entry.lastKey = null;
    entry.lastSalt = null;
    if (entry.source.kind === "env-key") {
        entry.source.key.fill(0);
    } else {
        // Best-effort password scrub: JS strings are immutable so we cannot
        // actually overwrite the backing memory, but dropping our reference
        // lets the GC reclaim it.
        (entry.source as { password: string }).password = "";
    }
}

// Process-wide singleton — CLI commands typically pass this around via
// CredentialStore/FileStore. A fresh instance may be injected in tests.
let globalRegistry: MasterKeyRegistry | null = null;

export function getGlobalMasterKeyRegistry(): MasterKeyRegistry {
    if (globalRegistry === null) globalRegistry = new MasterKeyRegistry();
    return globalRegistry;
}

export function resetGlobalMasterKeyRegistryForTests(): void {
    if (globalRegistry !== null) globalRegistry.clear();
    globalRegistry = null;
}

/** Convenience wrapper used by CLI entry points. */
export async function ensureMasterKey(
    canonicalOrigin: string,
    profileName: string,
    opts: ResolveKeyOptions = {},
): Promise<void> {
    await getGlobalMasterKeyRegistry().ensure(
        canonicalOrigin,
        profileName,
        opts,
    );
}
