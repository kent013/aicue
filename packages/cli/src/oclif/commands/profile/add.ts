import { Args, Flags } from "@oclif/core";
import { getCliVersion } from "../../../index.js";
import { confirmPrompt } from "../../../credential/prompt.js";
import { ExitCode, exitWith } from "../../../exit-codes.js";
import { verifyOrExitGranular } from "../../../http/verify.js";
import { canonicalOrigin } from "../../../profile/canonical-origin.js";
import { defaultConnectionOptions } from "../../../profile/context.js";
import { endpointFingerprint } from "../../../profile/fingerprint.js";
import { assertProfileName } from "../../../profile/name.js";
import { profileFlags } from "../../base/flags.js";
import { ProfileCommand } from "../../base/ProfileCommand.js";

/**
 * `profile:add <name>` — register a new profile and verify
 * its connection end-to-end (`/version` + optional `/me`).
 *
 * Uses `resolveMode: "if-needed"` because add creates the profile; it
 * does not consume an existing one. The verify round-trip is mandatory
 * so the stored `api_url` / `environment_tag` / `verified_at` metadata
 * reflects what the server actually returned, not what the user typed.
 */
export default class ProfileAdd extends ProfileCommand {
    static override description =
        "Register a new profile and verify connection (/version + optional /me).";
    static override args = {
        name: Args.string({ description: "profile name", required: true }),
    };
    static override flags = {
        "expected-env": Flags.string({
            description: "pin expected environment_tag",
        }),
        "allow-insecure": Flags.boolean({ description: "permit http:// URL" }),
        // F-0-02: declared explicitly so the plaintext opt-in is unambiguous
        // on `profile:add` (no longer relying on baseFlags inheritance).
        "allow-plaintext-credentials":
            profileFlags["allow-plaintext-credentials"],
        yes: Flags.boolean({ description: "skip confirmations (CI mode)" }),
        force: Flags.boolean({
            description: "bypass environment_tag mismatch",
        }),
    };

    protected override persistentRequired = false;
    protected override resolveMode: "if-needed" = "if-needed";

    public async run(): Promise<void> {
        const { args, flags } = await this.parse(ProfileAdd);
        this.latchCiFlag(flags.ci);
        const { writer, store } = await this.resolveContext(flags);
        const name = args.name;
        assertProfileName(name);
        const apiUrl = flags["api-url"];
        if (apiUrl === undefined) {
            console.error("Error: profile:add requires --api-url <url>.");
            exitWith(ExitCode.GeneralError);
        }
        if (writer.get(name)) {
            console.error(
                `Error: profile "${name}" already exists. `
                    + `Run \`profile:delete ${name}\` first to recreate.`,
            );
            exitWith(ExitCode.ProfileAlreadyExists);
        }
        const connOpts = {
            ...defaultConnectionOptions(),
            allow_insecure: flags["allow-insecure"] === true,
        };
        const result = await verifyOrExitGranular(
            apiUrl,
            flags["api-key"] ?? null,
            connOpts,
            flags["expected-env"] ?? null,
            getCliVersion(),
        );

        const init: {
            api_url: string;
            expected_environment_tag?: string | null;
            allow_insecure?: boolean;
        } = { api_url: apiUrl };
        if (flags["expected-env"] !== undefined) {
            init.expected_environment_tag = flags["expected-env"];
        }
        if (flags["allow-insecure"] === true) {
            init.allow_insecure = true;
        }
        writer.addProfile(name, init);

        try {
            if (
                flags["expected-env"] === undefined
                && result.environment_tag_source === "explicit"
                && result.environment_tag !== null
            ) {
                writer.updateExpectedEnv(name, result.environment_tag);
            } else if (
                flags["expected-env"] === undefined
                && flags.yes !== true
                && process.stdin.isTTY
            ) {
                const ok = await confirmPrompt(
                    `Server reports environment_tag=`
                        + `"${String(result.environment_tag)}" `
                        + `(source: ${result.environment_tag_source}). `
                        + "Save without expected_environment_tag pinning?",
                );
                if (!ok) {
                    writer.deleteProfile(name);
                    exitWith(ExitCode.GeneralError);
                }
            }
            writer.persistVerificationMeta(name, result);

            if (flags["api-key"] !== undefined) {
                await store.writeWithPreflight(
                    canonicalOrigin(apiUrl),
                    name,
                    "apikey",
                    "",
                    flags["api-key"],
                );
            }
            this.log(
                `Profile "${name}" added (endpoint: `
                    + `${endpointFingerprint(canonicalOrigin(apiUrl))}).`,
            );
        } catch (e) {
            try {
                writer.deleteProfile(name);
            } catch {
                /* best-effort rollback */
            }
            throw e;
        }
    }
}
