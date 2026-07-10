// Resolve the key material used by the file-store (T148 / YAGNI-09).
//
// Priority (first match wins):
//   1. APP_CREDENTIAL_KEY   — Base64(32B); used directly, scrypt skipped.
//   2. APP_MASTER_PASSWORD  — UTF-8 password, scrypt with a per-file salt.
//   3. interactive TTY      — readline prompt, scrypt with a per-file salt.
//
// Because each encrypted file carries its own salt (T148), the caller
// cannot return a ready-to-use 32B key from this module without knowing
// the target salt. Instead, `resolveKeySource` returns a `KeySource`
// describing how subsequent key derivation should happen, and the file
// store passes the per-file salt to `deriveKeyFromPassword` as needed.

import { ENV } from "../branding.js";
import { createInterface, type Interface } from "node:readline";
import { ExitCode, exitWith } from "../exit-codes.js";
import {
    decodeMasterKeyBase64,
    deriveKeyFromPassword,
    MASTER_KEY_BYTES,
} from "./key-derivation.js";

export type KeySourceKind = "env-key" | "env-password" | "prompt";

export type KeySource =
    | { kind: "env-key"; key: Uint8Array }
    | { kind: "env-password"; password: string }
    | { kind: "prompt"; password: string };

export type ResolveKeyOptions = {
    env?: NodeJS.ProcessEnv;
    isTty?: boolean;
    prompt?: (message: string) => Promise<string>;
};

export const ENV_CREDENTIAL_KEY = ENV.CREDENTIAL_KEY;
export const ENV_MASTER_PASSWORD = ENV.MASTER_PASSWORD;

/**
 * Resolve how the key should be produced. Does NOT run the KDF — callers
 * must pass the resulting `KeySource` and the per-file salt to
 * `deriveKeyFromSource` when they need a concrete 32B key. This allows
 * env-key users to skip scrypt entirely, and password users to cache the
 * password string once and re-derive keys against any number of file salts.
 */
export async function resolveKeySource(
    opts: ResolveKeyOptions = {},
): Promise<KeySource> {
    const env = opts.env ?? process.env;
    const isTty =
        opts.isTty ?? (process.stdin.isTTY === true && process.stderr.isTTY === true);

    const rawKey = env[ENV_CREDENTIAL_KEY];
    if (rawKey !== undefined && rawKey !== "") {
        const key = decodeMasterKeyBase64(rawKey);
        return { kind: "env-key", key };
    }

    const password = env[ENV_MASTER_PASSWORD];
    if (password !== undefined && password !== "") {
        return { kind: "env-password", password };
    }

    if (!isTty) {
        console.error(
            "Error: master password required for credential decryption. "
                + `Set ${ENV.CREDENTIAL_KEY} or ${ENV.MASTER_PASSWORD}, `
                + "or attach a TTY for interactive prompt.",
        );
        exitWith(ExitCode.MasterPasswordRequired);
    }

    const prompt = opts.prompt ?? defaultHiddenPrompt;
    const typed = await prompt("Master password: ");
    if (typed === "") {
        console.error("Error: empty master password is not permitted.");
        exitWith(ExitCode.MasterPasswordRequired);
    }
    return { kind: "prompt", password: typed };
}

/**
 * Produce a 32B AES-256 key for `source` using `salt` when needed.
 *
 * - env-key: returns the stored 32B key verbatim (salt is ignored).
 * - env-password / prompt: runs scrypt(password, salt, 32).
 */
export function deriveKeyFromSource(
    source: KeySource,
    salt: Uint8Array,
): Uint8Array {
    if (source.kind === "env-key") {
        if (source.key.length !== MASTER_KEY_BYTES) {
            console.error(
                "Error: internal: env-key must be 32 bytes "
                    + `(got ${String(source.key.length)}).`,
            );
            exitWith(ExitCode.EncryptionKeyInvalid);
        }
        return source.key;
    }
    return deriveKeyFromPassword(source.password, salt);
}

/**
 * readline-backed prompt that suppresses echo on stdout. Keeps the terminal
 * UX close to OpenSSH / git credential-manager: keystrokes are accepted but
 * not shown. Implemented by swapping the output stream's `_write`.
 */
async function defaultHiddenPrompt(message: string): Promise<string> {
    return await new Promise<string>((resolve) => {
        // Write the prompt to stderr (never stdout) so callers can still pipe
        // structured output on stdout without contamination.
        process.stderr.write(message);
        const rl: Interface = createInterface({
            input: process.stdin,
            output: process.stderr,
            terminal: true,
        });
        // Suppress echo by overriding `_writeToOutput` with a no-op.
        // readline calls this for each echoed character; by ignoring it we
        // get a silent prompt.
        const rlAny = rl as unknown as {
            _writeToOutput: (s: string) => void;
        };
        rlAny._writeToOutput = () => {
            /* silent */
        };
        rl.once("line", (line: string) => {
            process.stderr.write("\n");
            rl.close();
            resolve(line);
        });
    });
}
