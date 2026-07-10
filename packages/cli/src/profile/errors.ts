import type { ExitCodeValue } from "../exit-codes.js";
import { ExitCode } from "../exit-codes.js";

/**
 * Typed error for `resolveProfile` failures (C2 / T144).
 *
 * Previously the core helper called `console.error + exitWith` directly,
 * which coupled the pure resolution logic to the process lifecycle and
 * made it unusable outside a Commander action. The new contract is:
 *
 *   - `resolveProfile` throws a `ProfileResolutionError` on any failure
 *     mode it previously exited on (conflict, not-found, malformed).
 *   - The command-action layer (`defineReadCommand`, `defineProfileCommand`,
 *     …) catches the error, writes its `message` to stderr, and calls
 *     `exitWith(err.exitCode)`.
 *
 * The `exitCode` property is populated from the same `ExitCode` registry
 * that previously hard-coded the numeric literal, so CI contracts remain
 * identical.
 */
export class ProfileResolutionError extends Error {
    public readonly exitCode: ExitCodeValue;

    constructor(message: string, exitCode: ExitCodeValue) {
        super(message);
        this.name = "ProfileResolutionError";
        this.exitCode = exitCode;
    }

    static conflict(message: string): ProfileResolutionError {
        return new ProfileResolutionError(message, ExitCode.ProfileConflict);
    }

    static notFound(message: string): ProfileResolutionError {
        return new ProfileResolutionError(message, ExitCode.ProfileNotFound);
    }
}
