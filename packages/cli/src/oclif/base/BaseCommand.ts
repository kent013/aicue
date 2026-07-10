import { Command } from "@oclif/core";
import { CredentialStoreError } from "../../credential/errors.js";
import { ExitCode, exitWith } from "../../exit-codes.js";
import { ProfileResolutionError } from "../../profile/errors.js";
import {
    setGlobalCiFlag,
    setGlobalCiFlagForPlaintextGuard,
} from "../../runtime-state.js";
import { ciFlag } from "./flags.js";

/**
 * Root of every oclif command in the CLI.
 *
 * Responsibilities:
 *   1. Expose `--ci` as an inherited flag so every subcommand can latch
 *      the global CI mode without re-declaring it. Commander's old
 *      top-level `program.option('--ci', ...)` lived at the program
 *      level; oclif has no equivalent, so each subcommand inherits the
 *      flag via `...BaseCommand.baseFlags`.
 *   2. Provide {@link latchCiFlag} for subclasses to call once they've
 *      parsed flags so both latches (CI mode, plaintext guard) stay in
 *      sync without per-command duplication.
 *   3. Funnel typed errors from core helpers through {@link catch} so
 *      `CredentialStoreError` / `ProfileResolutionError` exit with their
 *      canonical codes and everything else maps to
 *      {@link ExitCode.GeneralError}. This replaces the try/catch wall
 *      that the old `defineProfileCommand` / `defineReadCommand` helpers
 *      carried inline.
 */
export abstract class BaseCommand extends Command {
    static override baseFlags = { ...ciFlag };

    /**
     * Latch the global `--ci` state. Subclasses call this immediately
     * after `this.parse(...)` so every downstream helper (prompt, plaintext
     * guard, playwright auto-install) sees a consistent CI boolean.
     */
    protected latchCiFlag(ci: boolean | undefined): void {
        const value = ci === true;
        setGlobalCiFlag(value);
        setGlobalCiFlagForPlaintextGuard(value);
    }

    /**
     * Translate typed errors thrown by core helpers into the CLI's
     * canonical exit-code contract. Unknown errors print their message
     * and exit {@link ExitCode.GeneralError}.
     */
    protected override async catch(
        err: Error & { exitCode?: number; oclif?: { exit?: number } },
    ): Promise<never> {
        // Re-throw oclif's own exit errors so --help / --version keep
        // working (they throw a CLIError with oclif.exit=0).
        if (err.oclif !== undefined) {
            throw err;
        }
        if (err instanceof CredentialStoreError) {
            console.error(`Error: ${err.message}`);
            exitWith(err.exitCode);
        }
        if (err instanceof ProfileResolutionError) {
            console.error(`Error: ${err.message}`);
            exitWith(err.exitCode);
        }
        const message = err.message !== "" ? err.message : "Unknown error";
        console.error(`Error: ${message}`);
        exitWith(ExitCode.GeneralError);
    }
}
