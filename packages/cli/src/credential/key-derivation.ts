import { ENV, BIN_NAME } from "../branding.js";
import { createHash, scryptSync } from "node:crypto";
import { ExitCode, exitWith } from "../exit-codes.js";

export type ItemKind = "apikey" | "credential" | "meta";

export const MASTER_KEY_BYTES = 32;
export const SALT_BYTES = 16;

// scrypt parameters (Node built-in `crypto.scryptSync`). These are in the
// "interactive" range — fast enough for a CLI prompt, slow enough to push
// back against brute-force of the on-disk payload. Max memory is bumped
// from the default 32 MiB to 64 MiB so N=16384 does not trip ENOMEM.
export const SCRYPT_N = 1 << 14; // 16384
export const SCRYPT_R = 8;
export const SCRYPT_P = 1;
export const SCRYPT_MAXMEM = 64 * 1024 * 1024;

export function deriveKeychainKey(
    canonicalOrigin: string,
    profileName: string,
    itemKind: ItemKind,
    itemId: string = "",
): string {
    const sep = "\0";
    const input = `${BIN_NAME}`
        + sep
        + canonicalOrigin
        + sep
        + profileName
        + sep
        + itemKind
        + sep
        + itemId;
    return createHash("sha256").update(input).digest("hex");
}

export function deriveProfileHash12(
    canonicalOrigin: string,
    profileName: string,
): string {
    return createHash("sha256")
        .update(canonicalOrigin + "\0" + profileName)
        .digest("hex")
        .slice(0, 12);
}

/**
 * Decode a Base64-encoded 32-byte master key (APP_CREDENTIAL_KEY).
 *
 * Exits with `EncryptionKeyInvalid` (31) if the input is not valid Base64 or
 * does not decode to exactly 32 bytes. Never echoes the decoded bytes.
 */
export function decodeMasterKeyBase64(b64: string): Uint8Array {
    const trimmed = b64.trim();
    if (trimmed.length === 0) {
        console.error(
            `Error: ${ENV.CREDENTIAL_KEY} is empty. `
                + "Expected Base64-encoded 32 bytes.",
        );
        exitWith(ExitCode.EncryptionKeyInvalid);
    }
    const buf = Buffer.from(trimmed, "base64");
    // `Buffer.from(_, "base64")` truncates silently on malformed input.
    // Re-encode and compare (ignoring padding + whitespace) to detect
    // corruption.
    const reencoded = buf.toString("base64");
    if (normalizeB64(reencoded) !== normalizeB64(trimmed)) {
        console.error(
            `Error: ${ENV.CREDENTIAL_KEY} is not valid Base64.`,
        );
        exitWith(ExitCode.EncryptionKeyInvalid);
    }
    if (buf.length !== MASTER_KEY_BYTES) {
        console.error(
            `Error: ${ENV.CREDENTIAL_KEY} must decode to exactly `
                + `${String(MASTER_KEY_BYTES)} bytes `
                + `(got ${String(buf.length)}).`,
        );
        exitWith(ExitCode.EncryptionKeyInvalid);
    }
    return new Uint8Array(buf);
}

function normalizeB64(s: string): string {
    return s.replace(/=+$/u, "").replace(/\s+/gu, "");
}

/**
 * scrypt-based KDF: password + 16B salt → 32B key. Uses Node's built-in
 * `scryptSync` with interactive-grade parameters (N=16384, r=8, p=1).
 *
 * Exits with `EncryptionKeyInvalid` (31) if salt length is wrong — this
 * only fires on programmer error since the file-store generates its own
 * 16B salts.
 */
export function deriveKeyFromPassword(
    password: string,
    salt: Uint8Array,
): Uint8Array {
    if (salt.length !== SALT_BYTES) {
        console.error(
            "Error: deriveKeyFromPassword: salt must be 16 bytes "
                + `(got ${String(salt.length)}).`,
        );
        exitWith(ExitCode.EncryptionKeyInvalid);
    }
    const derived = scryptSync(
        Buffer.from(password, "utf-8"),
        Buffer.from(salt),
        MASTER_KEY_BYTES,
        { N: SCRYPT_N, r: SCRYPT_R, p: SCRYPT_P, maxmem: SCRYPT_MAXMEM },
    );
    return new Uint8Array(derived);
}
