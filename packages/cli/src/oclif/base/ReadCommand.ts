import { ENV, BIN_NAME } from "../../branding.js";
import {
    type ApiCallFailure,
    formatFailure,
} from "../../api/client.js";
import type { CredentialStore } from "../../credential/store.js";
import { ExitCode, exitWith } from "../../exit-codes.js";
import { type ResolvedProfileContext } from "../../profile/context.js";
import { BaseCommand } from "./BaseCommand.js";
import { profileFlags, readOutputFlags } from "./flags.js";
import { resolveProfileBundle } from "./profile-context.js";

export type ReadFlags = {
    profile?: string | undefined;
    "api-url"?: string | undefined;
    "api-key"?: string | undefined;
    "allow-plaintext-credentials"?: boolean | undefined;
    json?: boolean | undefined;
    quiet?: boolean | undefined;
    ci?: boolean | undefined;
};

/**
 * Base class for every read/write API command (`whoami`, list-series,
 * create/update/delete, `run`, `page:target`, `report`). Mirrors the
 * legacy `defineReadCommand` wrapper: always resolves the profile
 * context, requires an API key, and exposes `--json` / `--quiet`.
 *
 * Subclasses declare `static flags = { ...ReadCommand.baseFlags, ...local }`
 * plus any `static args`, then call {@link resolveApiCtx} to get a
 * guaranteed non-null {@link ResolvedProfileContext} before hitting the
 * API. Failures from `apiGet` / `apiPost` / … can be fed to
 * {@link ReadCommand.exitOnFailure} which hands out the shared
 * `KeyNotFound` / `GeneralError` split.
 */
export abstract class ReadCommand extends BaseCommand {
    static override baseFlags = {
        ...BaseCommand.baseFlags,
        ...profileFlags,
        ...readOutputFlags,
    };

    protected async resolveApiCtx(
        flags: ReadFlags,
    ): Promise<{ ctx: ResolvedProfileContext; store: CredentialStore }> {
        const { ctx, store } = await resolveProfileBundle(
            {
                profile: flags.profile,
                apiUrl: flags["api-url"],
                apiKey: flags["api-key"],
                allowPlaintextCredentials: flags["allow-plaintext-credentials"],
            },
            {
                resolveMode: "always",
                persistentRequired: false,
                // Banner is for humans; --json / --quiet opt out so
                // downstream pipes (jq, ndjson) only see data.
                printBanner: flags.quiet !== true && flags.json !== true,
            },
        );
        if (ctx === null) {
            // Unreachable: resolveMode="always" guarantees non-null.
            exitWith(ExitCode.GeneralError);
        }
        // T715 Phase 3: an OAuth user-token (from `${BIN_NAME} login`) is an
        // equally valid credential, so only reject when neither an API key
        // nor an OAuth token provider is available.
        if (
            (ctx.apiKey === undefined || ctx.apiKey === null)
            && ctx.oauthTokenProvider === undefined
        ) {
            console.error(
                "Error: no credential for this profile. Run "
                    + `\`${BIN_NAME} login\`, `
                    + `\`${BIN_NAME} profile:set-key ${ctx.name}\`, set `
                    + `${ENV.API_KEY}, or pass --api-key.`,
            );
            exitWith(ExitCode.GeneralError);
        }
        return { ctx, store };
    }

    /**
     * Standard exit-code policy for an API failure. `not-found` maps to
     * {@link ExitCode.KeyNotFound} so scripts can distinguish "resource
     * id absent" from generic errors, matching the number
     * `config:get` already uses for "key absent".
     */
    protected failureExitCode(failure: ApiCallFailure): number {
        if (failure.kind === "not-found") return ExitCode.KeyNotFound;
        return ExitCode.GeneralError;
    }

    /**
     * Pretty-print an API failure to stderr and exit with the matching
     * code. Centralised so command bodies don't hand-roll the wording.
     */
    protected exitOnFailure(failure: ApiCallFailure): never {
        console.error(formatFailure(failure));
        exitWith(this.failureExitCode(failure) as 1 | 4);
    }
}
