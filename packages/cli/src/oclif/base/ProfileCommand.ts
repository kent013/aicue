import type { CredentialStore } from "../../credential/store.js";
import type { ResolvedProfileContext } from "../../profile/context.js";
import type { ProfileWriter } from "../../profile/writer.js";
import { BaseCommand } from "./BaseCommand.js";
import { profileFlags } from "./flags.js";
import {
    resolveProfileBundle,
    type ProfileResolveBundle,
} from "./profile-context.js";

/**
 * Common flag bag shared by ProfileCommand subclasses. Subclasses that
 * add local flags compose them with this object (see the @example below).
 */
export type ProfileFlags = {
    profile?: string | undefined;
    "api-url"?: string | undefined;
    "api-key"?: string | undefined;
    "allow-plaintext-credentials"?: boolean | undefined;
    ci?: boolean | undefined;
};

export type ResolveMode = "always" | "if-needed";

export type ResolvedProfile = {
    ctx: ResolvedProfileContext | null;
    writer: ProfileWriter;
    store: CredentialStore;
};

/**
 * Base class for every command that needs the profile-resolution stack —
 * `profile:*`, `auth:*`, `capture:*`, `scan`, and write commands that
 * target a specific origin. Mirrors the behaviour of the legacy
 * `defineProfileCommand` wrapper but expressed as a class so each
 * concrete command owns its own flag declarations, args, and business
 * logic (no action-callback indirection).
 *
 * Subclasses:
 *   - Declare `static flags = { ...ProfileCommand.baseFlags, ...local }`
 *     so the shared options (`--profile`, `--api-url`, …) come along for
 *     free.
 *   - Call {@link resolveContext} inside `run()` to get `{ ctx, writer, store }`.
 *   - Inline the action body from the legacy `register*Command` function.
 *
 * @example
 * ```ts
 * export default class ProfileUse extends ProfileCommand {
 *   static description = "Set default_profile";
 *   static args = { name: Args.string({ required: true }) };
 *   static flags = { ...ProfileCommand.baseFlags };
 *   protected persistentRequired = false;
 *   protected resolveMode: ResolveMode = "if-needed";
 *   async run() {
 *     const { args, flags } = await this.parse(ProfileUse);
 *     this.latchCiFlag(flags.ci);
 *     const { writer } = await this.resolveContext(flags);
 *     assertProfileName(args.name);
 *     if (!writer.get(args.name)) exitWith(ExitCode.ProfileNotFound);
 *     writer.useDefaultProfile(args.name);
 *     this.log(`default_profile = ${args.name}`);
 *   }
 * }
 * ```
 */
export abstract class ProfileCommand extends BaseCommand {
    static override baseFlags = { ...BaseCommand.baseFlags, ...profileFlags };

    /**
     * Whether the command can only be run against a persistent profile.
     * Defaults to `false`; write-side commands override to `true` so the
     * wrapper fails fast (`exit 17`) when the user passes `--api-url`
     * instead of naming a persistent profile.
     */
    protected persistentRequired: boolean = false;

    /**
     * Whether profile resolution must run even when the command wasn't
     * given `--profile` / `--api-url` (e.g. `profile:show` falling back
     * to the default profile). "always" means the wrapper will call
     * resolveProfile; "if-needed" skips resolution for commands that act
     * purely on the local profile config (like `profile:use`).
     */
    protected resolveMode: ResolveMode = "if-needed";

    /**
     * Thin wrapper over {@link resolveProfileBundle} that reads instance
     * fields (`persistentRequired`, `resolveMode`) and flags into the
     * shared helper. See the helper's JSDoc for failure modes.
     *
     * Commands that accept a positional profile name (e.g. `profile:show
     * [name]`) should merge the positional into `flags.profile` before
     * calling this — the wrapper does not special-case positional args.
     */
    protected async resolveContext(
        flags: ProfileFlags,
        options: { printBanner?: boolean } = {},
    ): Promise<ResolvedProfile> {
        const bundle: ProfileResolveBundle = await resolveProfileBundle(
            {
                profile: flags.profile,
                apiUrl: flags["api-url"],
                apiKey: flags["api-key"],
                allowPlaintextCredentials: flags["allow-plaintext-credentials"],
            },
            {
                resolveMode: this.resolveMode,
                persistentRequired: this.persistentRequired,
                ...(options.printBanner !== undefined
                    ? { printBanner: options.printBanner }
                    : {}),
            },
        );
        return bundle;
    }
}
