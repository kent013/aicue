import { ENV, BIN_NAME } from "../branding.js";
import { createRequire } from "node:module";
import { ExitCode, exitWith } from "../exit-codes.js";
import type { BackendStore } from "./backend.js";
import { deriveKeychainKey, type ItemKind } from "./key-derivation.js";

const requireFromHere = createRequire(import.meta.url);

const SERVICE = `${BIN_NAME}`;

type EntryCtor = new (service: string, username: string) => {
    getPassword: () => string | null;
    setPassword: (password: string) => void;
    deletePassword: () => boolean;
};

let cachedEntryCtor: EntryCtor | null | undefined = undefined;

function loadEntryCtor(): EntryCtor | null {
    if (cachedEntryCtor !== undefined) return cachedEntryCtor;
    try {
        // Dynamic import via require-like wrapper so this module still
        // loads on platforms without a keyring prebuilt binary.
        const mod = requireFromHere("@napi-rs/keyring") as {
            Entry?: EntryCtor;
        };
        cachedEntryCtor = mod.Entry ?? null;
    } catch {
        cachedEntryCtor = null;
    }
    return cachedEntryCtor;
}

function isNotFoundError(e: unknown): boolean {
    const m = String((e as Error)?.message ?? "").toLowerCase();
    return (
        m.includes("not found")
        || m.includes("no such")
        || m.includes("no matching")
        || m.includes("no entry")
    );
}

export class KeychainStore implements BackendStore {
    private readonly ctor: EntryCtor | null;

    constructor(ctor: EntryCtor | null = loadEntryCtor()) {
        this.ctor = ctor;
    }

    isAvailable(): boolean {
        // Test-only escape hatch. The read-only probe below returns
        // `true` on GitHub Actions Linux runners because `@napi-rs/keyring`'s
        // fallback store answers the probe but rejects the real write with
        // `QuotaExceeded`. Setting this flag in CI/test harnesses forces the
        // file backend consistently. Never read in production code paths.
        if (process.env[ENV.DISABLE_KEYCHAIN] === "1") return false;
        if (this.ctor === null) return false;
        try {
            const e = new this.ctor(SERVICE, "_uxi_probe_");
            // Probe read — both "value found" and "not found" confirm the
            // backend is reachable. Any other error means unavailable.
            e.getPassword();
            return true;
        } catch (e) {
            return isNotFoundError(e);
        }
    }

    write(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
        value: string,
    ): void {
        if (this.ctor === null) {
            console.error("Error: keychain backend not available.");
            exitWith(ExitCode.CredentialStoreFailure);
        }
        const key = deriveKeychainKey(
            canonicalOrigin,
            profileName,
            itemKind,
            itemId,
        );
        try {
            new this.ctor(SERVICE, key).setPassword(value);
        } catch (e) {
            console.error(
                `Error: keychain write failed for ${profileName}/${itemKind}/`
                    + `${itemId}: ${(e as Error).message}`,
            );
            exitWith(ExitCode.CredentialStoreFailure);
        }
        const got = new this.ctor(SERVICE, key).getPassword();
        if (got !== value) {
            console.error(
                "Error: keychain read-after-write verify failed for "
                    + `${profileName}/${itemKind}/${itemId}.`,
            );
            exitWith(ExitCode.CredentialStoreFailure);
        }
    }

    read(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
    ): string | null {
        if (this.ctor === null) return null;
        const key = deriveKeychainKey(
            canonicalOrigin,
            profileName,
            itemKind,
            itemId,
        );
        try {
            return new this.ctor(SERVICE, key).getPassword();
        } catch (e) {
            if (isNotFoundError(e)) return null;
            console.error(
                `Error: keychain read failed for ${profileName}/${itemKind}/`
                    + `${itemId}: ${(e as Error).message}`,
            );
            exitWith(ExitCode.CredentialStoreFailure);
        }
    }

    delete(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
    ): void {
        if (this.ctor === null) return;
        const key = deriveKeychainKey(
            canonicalOrigin,
            profileName,
            itemKind,
            itemId,
        );
        try {
            new this.ctor(SERVICE, key).deletePassword();
        } catch (e) {
            if (isNotFoundError(e)) return;
            console.error(
                `Error: keychain delete failed for ${profileName}/${itemKind}/`
                    + `${itemId}: ${(e as Error).message}`,
            );
            exitWith(ExitCode.CredentialStoreFailure);
        }
    }
}
