import { Flags } from "@oclif/core";
import { runDoctor } from "../../doctor/runner.js";
import { ExitCode, exitWith } from "../../exit-codes.js";
import { BaseCommand } from "../base/BaseCommand.js";

export default class Doctor extends BaseCommand {
    static override description =
        "Run environment self-diagnosis (Node/Playwright/credential backend)";
    static override flags = {
        json: Flags.boolean({
            description: "output machine-readable JSON",
            default: false,
        }),
    };

    public async run(): Promise<void> {
        const { flags } = await this.parse(Doctor);
        this.latchCiFlag(flags.ci);
        const result = await runDoctor({ json: flags.json === true });
        if (result.exitCode !== 0) {
            exitWith(ExitCode.GeneralError);
        }
    }
}
