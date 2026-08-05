import { Args, Flags } from "@oclif/core";
import { BIN_NAME } from "../../../branding.js";
import { confirmPrompt } from "../../../credential/prompt.js";
import { ExitCode, exitWith } from "../../../exit-codes.js";
import {
    executeProfileDeletion,
    planProfileDeletion,
} from "../../../profile/delete.js";
import { assertProfileName } from "../../../profile/name.js";
import { ProfileCommand } from "../../base/ProfileCommand.js";

/**
 * `${BIN_NAME} profile:delete <name>` — remove a profile and destroy the
 * credentials stored for it (API key, OAuth token, per-site credentials).
 *
 * Uses `resolveMode: "if-needed"` because deletion acts purely on the local
 * config + credential store; no server round-trip and no resolution of an
 * existing profile context is required (same shape as `profile:use`).
 */
export default class ProfileDelete extends ProfileCommand {
    static override description =
        "Delete a profile and destroy its stored credentials.";
    static override args = {
        name: Args.string({ description: "profile name", required: true }),
    };
    static override flags = {
        "clear-default": Flags.boolean({
            description:
                "allow deleting the profile that default_profile points at",
        }),
        yes: Flags.boolean({ description: "skip confirmations (CI mode)" }),
    };

    protected override persistentRequired = false;
    protected override resolveMode: "if-needed" = "if-needed";

    public async run(): Promise<void> {
        const { args, flags } = await this.parse(ProfileDelete);
        this.latchCiFlag(flags.ci);
        // 名前検証は **resolveContext より前**。config / credential の初期化が
        // 失敗しうる状態でも、不正な名前は必ず exit 13 で落ちる
        // (設計書 §実装順序 の 1 番目)。
        const name = args.name;
        assertProfileName(name);
        const { writer, store } = await this.resolveContext(flags);

        // 事前検証は **確認プロンプトより前**。ここで
        // profile 不在 (11) / default 競合 (10) が確定する。
        // 後ろに置くと非 TTY 環境で「本当は 10 なのに確認が取れず 1」になる。
        const plan = planProfileDeletion(writer, name, {
            clearDefault: flags["clear-default"] === true,
        });

        if (flags.yes !== true) {
            const ok = await confirmPrompt(
                `Delete profile "${name}" and destroy its stored `
                    + "credentials? This cannot be undone.",
            );
            if (!ok) {
                console.error(
                    "Aborted (pass --yes to skip this confirmation).",
                );
                exitWith(ExitCode.GeneralError);
            }
        }

        const result = executeProfileDeletion({ writer, store }, plan);

        this.log(`Profile "${name}" deleted.`);
        if (!result.wasDefault) return;
        if (result.nextDefault !== null) {
            this.log(`default_profile = ${result.nextDefault}`);
            return;
        }
        if (result.remaining.length === 0) {
            this.log(
                "default_profile is now unset and no profiles remain. "
                    + `Run \`${BIN_NAME} profile:add <name> --api-url <url>\`.`,
            );
            return;
        }
        this.log(
            "default_profile is now unset. Pick one with "
                + `\`${BIN_NAME} profile:use <name>\`: `
                + result.remaining.join(", "),
        );
    }
}
