import type { ExitCodeValue } from "../exit-codes.js";
import { ExitCode } from "../exit-codes.js";

/**
 * Typed error for `CredentialStore` failures (C2 / T144).
 *
 * The pre-T144 implementation invoked `console.error + exitWith` from
 * deep inside `readIndex`, which made the core store untestable without
 * stubbing `process.exit`. The new contract is: the store throws a
 * `CredentialStoreError`, and the command-action layer catches it, logs
 * the human message, and translates the `exitCode` to a real
 * `process.exit` call.
 */
/**
 * Discriminator so callers can narrow *which* store failure they caught.
 *
 * `profile:delete` must swallow a corrupted credential index (it deletes the
 * whole directory instead) while letting every other store failure through.
 * Without this discriminator a future decrypt/delete failure raised from the
 * same code path would be silently swallowed too.
 */
export type CredentialStoreErrorKind = "corrupted-index" | "unknown";

export class CredentialStoreError extends Error {
    public readonly exitCode: ExitCodeValue;
    public readonly kind: CredentialStoreErrorKind;

    constructor(
        message: string,
        exitCode: ExitCodeValue,
        kind: CredentialStoreErrorKind = "unknown",
    ) {
        super(message);
        this.name = "CredentialStoreError";
        this.exitCode = exitCode;
        this.kind = kind;
    }

    static corruptedIndex(profileName: string, cause: string): CredentialStoreError {
        return new CredentialStoreError(
            `credential index is corrupted for profile "${profileName}" `
                + `(${cause}). Manual repair required.`,
            ExitCode.CredentialStoreFailure,
            "corrupted-index",
        );
    }
}
