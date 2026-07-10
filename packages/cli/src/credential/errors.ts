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
export class CredentialStoreError extends Error {
    public readonly exitCode: ExitCodeValue;

    constructor(message: string, exitCode: ExitCodeValue) {
        super(message);
        this.name = "CredentialStoreError";
        this.exitCode = exitCode;
    }

    static corruptedIndex(profileName: string, cause: string): CredentialStoreError {
        return new CredentialStoreError(
            `credential index is corrupted for profile "${profileName}" `
                + `(${cause}). Manual repair required.`,
            ExitCode.CredentialStoreFailure,
        );
    }
}
