import { ENV, BIN_NAME } from "../branding.js";
import {
    existsSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    rmSync,
} from "node:fs";
import { homedir } from "node:os";
import { dirname, join } from "node:path";
import { randomBytes } from "node:crypto";
import { ExitCode, exitWith } from "../exit-codes.js";
import { atomicWriteFile, atomicWriteFileBinary } from "./atomic-write.js";
import type { BackendStore } from "./backend.js";
import {
    decryptString,
    encryptString,
    extractSalt,
    SALT_LEN,
    unwrapPlaintext,
    wrapPlaintext,
} from "./encryption.js";
import { deriveProfileHash12, type ItemKind } from "./key-derivation.js";
import {
    getGlobalMasterKeyRegistry,
    type MasterKeyRegistry,
} from "./master-key-registry.js";
import { ENV_CREDENTIAL_KEY, ENV_MASTER_PASSWORD } from "./master-password.js";
import { isPlaintextOptInAllowed } from "../runtime-state.js";

export type FileStoreMode = "encrypted" | "plaintext";

const ENC_EXT = ".enc";
const DAT_EXT = ".dat";

export class FileStore implements BackendStore {
    private readonly registry: MasterKeyRegistry;

    constructor(
        private readonly baseDir: string = join(
            homedir(),
            `.${BIN_NAME}`,
            "credentials",
        ),
        registry?: MasterKeyRegistry,
    ) {
        this.registry = registry ?? getGlobalMasterKeyRegistry();
    }

    profileDir(profileHash12: string): string {
        return join(this.baseDir, profileHash12);
    }

    private fileBase(itemKind: ItemKind, itemId: string): string {
        return `${itemKind}${itemId ? "_" + encodeURIComponent(itemId) : ""}`;
    }

    encPath(
        profileHash12: string,
        itemKind: ItemKind,
        itemId: string,
    ): string {
        return join(
            this.profileDir(profileHash12),
            this.fileBase(itemKind, itemId) + ENC_EXT,
        );
    }

    datPath(
        profileHash12: string,
        itemKind: ItemKind,
        itemId: string,
    ): string {
        return join(
            this.profileDir(profileHash12),
            this.fileBase(itemKind, itemId) + DAT_EXT,
        );
    }

    /**
     * Which on-disk format this FileStore will use for writes *right now*.
     * Plaintext is only available when the user has explicitly opted in via
     * `--allow-plaintext-credentials` or `${ENV.ALLOW_PLAINTEXT}=1`.
     */
    detectMode(): FileStoreMode {
        return shouldUseEncryptedPath() ? "encrypted" : "plaintext";
    }

    write(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
        value: string,
    ): void {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        // Routing policy (single source of truth = shouldUseEncryptedPath):
        //   encrypted path if any encryption env is set, OR if plaintext
        //   opt-in is NOT granted (encrypted is the secure default).
        //   plaintext path only when the user explicitly opts in AND no
        //   encryption env is present. This keeps the two formats disjoint.
        const useEncrypted = shouldUseEncryptedPath();
        mkdirSync(this.profileDir(ph12), { recursive: true, mode: 0o700 });

        if (useEncrypted) {
            // Fresh salt per file so the KDF output is unpredictable even
            // when the same master password is used across profiles.
            const salt = new Uint8Array(randomBytes(SALT_LEN));
            // Will also exit(32) on a missing `ensureMasterKey()` call.
            const key = this.registry.deriveKey(
                canonicalOrigin,
                profileName,
                salt,
            );
            const blob = encryptString(value, key, salt);
            const path = this.encPath(ph12, itemKind, itemId);
            mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
            atomicWriteFileBinary(path, blob, 0o600);
            const twin = this.datPath(ph12, itemKind, itemId);
            if (existsSync(twin)) rmSync(twin);
        } else {
            const path = this.datPath(ph12, itemKind, itemId);
            mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
            atomicWriteFile(path, wrapPlaintext(value), 0o600);
            const twin = this.encPath(ph12, itemKind, itemId);
            if (existsSync(twin)) rmSync(twin);
        }

        const got = this.read(canonicalOrigin, profileName, itemKind, itemId);
        if (got !== value) {
            console.error(
                "Error: file-store read-after-write verify failed "
                    + `for ${profileName}/${itemKind}/${itemId}.`,
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
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        const encPath = this.encPath(ph12, itemKind, itemId);
        const datPath = this.datPath(ph12, itemKind, itemId);

        if (existsSync(encPath)) {
            const blob = new Uint8Array(readFileSync(encPath));
            const salt = extractSalt(blob);
            const key = this.registry.deriveKey(
                canonicalOrigin,
                profileName,
                salt,
            );
            return decryptString(blob, key);
        }
        if (existsSync(datPath)) {
            if (!isPlaintextAllowed()) {
                console.error(
                    `Error: plaintext credential found at ${datPath} but `
                        + "opt-in is not granted. "
                        + `Set ${ENV.ALLOW_PLAINTEXT}=1 or pass `
                        + "--allow-plaintext-credentials to read it.",
                );
                exitWith(ExitCode.CredentialStoreFailure);
            }
            const raw = readFileSync(datPath, "utf-8");
            try {
                return unwrapPlaintext(raw);
            } catch (e) {
                console.error(
                    `Error reading plaintext credential at ${datPath}: `
                        + `${(e as Error).message}`,
                );
                exitWith(ExitCode.CredentialStoreFailure);
            }
        }
        return null;
    }

    delete(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
    ): void {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        for (const path of [
            this.encPath(ph12, itemKind, itemId),
            this.datPath(ph12, itemKind, itemId),
        ]) {
            if (existsSync(path)) rmSync(path);
        }
    }

    clearProfile(canonicalOrigin: string, profileName: string): void {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        const dir = this.profileDir(ph12);
        if (existsSync(dir)) rmSync(dir, { recursive: true, force: true });
    }

    listItemsOnDisk(
        canonicalOrigin: string,
        profileName: string,
    ): ReadonlyArray<{ kind: ItemKind; id: string }> {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        const dir = this.profileDir(ph12);
        if (!existsSync(dir)) return [];
        const seen = new Set<string>();
        const out: Array<{ kind: ItemKind; id: string }> = [];
        for (const fname of readdirSync(dir)) {
            const parsed = parseFilename(fname);
            if (parsed === null) continue;
            const dedupKey = `${parsed.kind}::${parsed.id}`;
            if (seen.has(dedupKey)) continue;
            seen.add(dedupKey);
            out.push(parsed);
        }
        return out;
    }
}

function hasEncryptionEnv(): boolean {
    const rawKey = process.env[ENV_CREDENTIAL_KEY];
    if (rawKey !== undefined && rawKey !== "") return true;
    const password = process.env[ENV_MASTER_PASSWORD];
    if (password !== undefined && password !== "") return true;
    return false;
}

function isPlaintextAllowed(): boolean {
    return isPlaintextOptInAllowed();
}

/**
 * Single source of truth for "does this write need the encrypted path (and
 * therefore a loaded master key)?" (F-0-02). Used by `FileStore.write`,
 * `FileStore.detectMode` AND `CredentialStore.prepareForWrite` so the
 * preflight decision can never drift from the actual write routing.
 */
export function shouldUseEncryptedPath(): boolean {
    // encrypted when encryption env present; otherwise encrypted unless the
    // user explicitly opted into plaintext (encrypted is the secure default).
    return hasEncryptionEnv() || !isPlaintextAllowed();
}

function parseFilename(
    fname: string,
): { kind: ItemKind; id: string } | null {
    let body: string;
    if (fname.endsWith(ENC_EXT)) {
        body = fname.slice(0, -ENC_EXT.length);
    } else if (fname.endsWith(DAT_EXT)) {
        body = fname.slice(0, -DAT_EXT.length);
    } else {
        return null;
    }
    const underscore = body.indexOf("_");
    const kindStr = underscore === -1 ? body : body.slice(0, underscore);
    const idStr =
        underscore === -1 ? "" : decodeURIComponent(body.slice(underscore + 1));
    if (
        kindStr !== "apikey"
        && kindStr !== "credential"
        && kindStr !== "meta"
    ) {
        return null;
    }
    return { kind: kindStr, id: idStr };
}
