// Node built-in crypto credential encryption (T148 / YAGNI-09).
//
// File format (binary, UXI2):
//     bytes[0..3]    : "UXI2"                (magic)
//     bytes[4]       : version (0x02)
//     bytes[5]       : cipher_id (0x01 = AES-256-GCM)
//     bytes[6..21]   : salt (16B, per-file random)
//     bytes[22..33]  : iv   (12B, per-file random)
//     bytes[34..49]  : authTag (16B, GCM tag)
//     bytes[50..]    : ciphertext (N bytes, equal to plaintext length)
//
// Compared with the previous UXI1 + libsodium design:
//   - libsodium-wrappers-sumo dropped; Node built-in `node:crypto` only.
//   - XSalsa20-Poly1305 secretbox -> AES-256-GCM.
//   - Argon2id -> scrypt (Node built-in).
//   - Per-profile `.salt` file removed; each file carries its own salt so
//     keys are derived freshly from the user's master password + file salt.

import { ENV } from "../branding.js";
import {
    createCipheriv,
    createDecipheriv,
    randomBytes,
} from "node:crypto";
import { ExitCode, exitWith } from "../exit-codes.js";

export const UXI2_MAGIC = new Uint8Array([0x55, 0x58, 0x49, 0x32]); // "UXI2"
export const UXI2_MAGIC_LEN = 4;
export const UXI2_VERSION = 2;
export const UXI2_CIPHER_AES_256_GCM = 1;
export const SALT_LEN = 16; // per-file scrypt salt
export const IV_LEN = 12; // GCM recommended IV length
export const AUTH_TAG_LEN = 16; // GCM auth tag length
export const KEY_LEN = 32; // AES-256 key length

export const UXI2_HEADER_LEN = 6; // magic(4) + version(1) + cipher_id(1)
export const UXI2_PREAMBLE_LEN =
    UXI2_HEADER_LEN + SALT_LEN + IV_LEN + AUTH_TAG_LEN; // 50
export const UXI2_MIN_LEN = UXI2_PREAMBLE_LEN; // empty plaintext is valid

/**
 * Encrypt `plaintext` with `key` (32B AES-256 key) using a caller-supplied
 * 16B salt. The returned Uint8Array is the full file payload including
 * magic, version, cipher_id, salt, IV, auth tag, and ciphertext.
 *
 * The salt is not used by encryption itself (AES-GCM does not need a salt)
 * but is embedded in the header so that the same password can re-derive the
 * key on read without consulting any sidecar file. Callers that use a
 * directly-provided master key (e.g. `${ENV.CREDENTIAL_KEY}`) may pass any
 * 16B value; it will simply be stored verbatim.
 */
export function encryptString(
    plaintext: string,
    key: Uint8Array,
    salt: Uint8Array,
): Uint8Array {
    if (key.length !== KEY_LEN) {
        console.error(
            "Error: encryptString: key must be 32 bytes "
                + `(got ${String(key.length)}).`,
        );
        exitWith(ExitCode.EncryptionKeyInvalid);
    }
    if (salt.length !== SALT_LEN) {
        console.error(
            "Error: encryptString: salt must be 16 bytes "
                + `(got ${String(salt.length)}).`,
        );
        exitWith(ExitCode.EncryptionKeyInvalid);
    }
    const iv = randomBytes(IV_LEN);
    const cipher = createCipheriv("aes-256-gcm", Buffer.from(key), iv);
    // AAD authenticates the bytes that AES-GCM does not otherwise cover:
    // magic (4) + version (1) + cipher_id (1) + salt (16). If any of these
    // are tampered with on disk (including when the caller is using a
    // direct `${ENV.CREDENTIAL_KEY}` that would otherwise ignore the salt),
    // GCM's auth tag check will fail on read and we exit 33. IV and
    // authTag themselves are implicitly covered by GCM and need no AAD.
    const aad = buildAad(salt);
    cipher.setAAD(aad);
    const message = Buffer.from(plaintext, "utf-8");
    const part1 = cipher.update(message);
    const part2 = cipher.final();
    const authTag = cipher.getAuthTag();
    const ciphertext = Buffer.concat([part1, part2]);

    const out = new Uint8Array(UXI2_PREAMBLE_LEN + ciphertext.length);
    out.set(aad.subarray(0, UXI2_HEADER_LEN + SALT_LEN), 0);
    out.set(iv, UXI2_HEADER_LEN + SALT_LEN);
    out.set(authTag, UXI2_HEADER_LEN + SALT_LEN + IV_LEN);
    out.set(ciphertext, UXI2_PREAMBLE_LEN);
    return out;
}

/**
 * Build the AAD buffer for a given salt. Layout:
 *   magic(4) + version(1) + cipher_id(1) + salt(16) = 22 bytes.
 * The salt is authenticated via AAD rather than fed into the KDF so that
 * APP_CREDENTIAL_KEY callers (which bypass the KDF entirely) still gain
 * tamper detection on the salt bytes.
 */
function buildAad(salt: Uint8Array): Buffer {
    const aad = Buffer.alloc(UXI2_HEADER_LEN + SALT_LEN);
    aad.set(UXI2_MAGIC, 0);
    aad[4] = UXI2_VERSION;
    aad[5] = UXI2_CIPHER_AES_256_GCM;
    aad.set(salt, UXI2_HEADER_LEN);
    return aad;
}

/**
 * Extract the 16B salt from a UXI2 blob. Used by the file-store to
 * re-derive the key before calling `decryptString`. Exits with
 * `DecryptionFailed` (33) on malformed input.
 */
export function extractSalt(blob: Uint8Array): Uint8Array {
    validatePreamble(blob);
    return blob.slice(UXI2_HEADER_LEN, UXI2_HEADER_LEN + SALT_LEN);
}

/**
 * Decrypt a UXI2 blob. On any validation / auth-tag failure the process
 * exits with `DecryptionFailed` (33). Error messages never embed paths or
 * credential bytes.
 */
export function decryptString(blob: Uint8Array, key: Uint8Array): string {
    if (key.length !== KEY_LEN) {
        console.error(
            "Error: decryptString: key must be 32 bytes "
                + `(got ${String(key.length)}).`,
        );
        exitWith(ExitCode.EncryptionKeyInvalid);
    }
    validatePreamble(blob);
    const salt = blob.subarray(UXI2_HEADER_LEN, UXI2_HEADER_LEN + SALT_LEN);
    const iv = blob.subarray(
        UXI2_HEADER_LEN + SALT_LEN,
        UXI2_HEADER_LEN + SALT_LEN + IV_LEN,
    );
    const authTag = blob.subarray(
        UXI2_HEADER_LEN + SALT_LEN + IV_LEN,
        UXI2_PREAMBLE_LEN,
    );
    const ciphertext = blob.subarray(UXI2_PREAMBLE_LEN);
    let plain: Buffer;
    try {
        const decipher = createDecipheriv(
            "aes-256-gcm",
            Buffer.from(key),
            Buffer.from(iv),
        );
        // AAD must match what was set on encrypt (magic+version+cipher+salt).
        // Any single-byte tamper in those fields causes `.final()` to throw,
        // which we catch below and report as a decryption failure.
        decipher.setAAD(buildAad(salt));
        decipher.setAuthTag(Buffer.from(authTag));
        const part1 = decipher.update(Buffer.from(ciphertext));
        const part2 = decipher.final();
        plain = Buffer.concat([part1, part2]);
    } catch {
        console.error(
            "Error: credential decryption failed "
                + "(auth tag verification failed — tampered file or wrong key).",
        );
        exitWith(ExitCode.DecryptionFailed);
    }
    return plain.toString("utf-8");
}

function validatePreamble(blob: Uint8Array): void {
    if (blob.length < UXI2_MIN_LEN) {
        console.error(
            "Error: credential decryption failed (truncated payload).",
        );
        exitWith(ExitCode.DecryptionFailed);
    }
    for (let i = 0; i < UXI2_MAGIC_LEN; i += 1) {
        if (blob[i] !== UXI2_MAGIC[i]) {
            console.error(
                "Error: credential decryption failed "
                    + "(unknown format — expected UXI2 binary header). "
                    + "Run `auth:delete` and re-register with `auth:set` / "
                    + "`auth:import`.",
            );
            exitWith(ExitCode.DecryptionFailed);
        }
    }
    const version = blob[4];
    const cipherId = blob[5];
    if (version !== UXI2_VERSION) {
        console.error(
            `Error: credential decryption failed (unsupported version ${String(version)}).`,
        );
        exitWith(ExitCode.DecryptionFailed);
    }
    if (cipherId !== UXI2_CIPHER_AES_256_GCM) {
        console.error(
            `Error: credential decryption failed (unsupported cipher ${String(cipherId)}).`,
        );
        exitWith(ExitCode.DecryptionFailed);
    }
}

// === Backwards-compatible string wrapper helpers for plaintext opt-in ===
// T114 stored plaintext with the "uxi0:" prefix and read it back via
// `unwrap`. The plaintext path is still supported under
// `--allow-plaintext-credentials`, so we keep these helpers untouched here
// for file-store.ts to use.

export const PLAINTEXT_MAGIC = "uxi0:";

export function wrapPlaintext(s: string): string {
    return PLAINTEXT_MAGIC + s;
}

export function unwrapPlaintext(raw: string): string {
    if (!raw.startsWith(PLAINTEXT_MAGIC)) {
        throw new Error(
            "Corrupted plaintext credential: missing 'uxi0:' prefix",
        );
    }
    return raw.slice(PLAINTEXT_MAGIC.length);
}
