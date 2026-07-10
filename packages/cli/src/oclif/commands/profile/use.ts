import { Args } from "@oclif/core";
import { ExitCode, exitWith } from "../../../exit-codes.js";
import { assertProfileName } from "../../../profile/name.js";
import { ProfileCommand } from "../../base/ProfileCommand.js";

export default class ProfileUse extends ProfileCommand {
    static override description =
        "Set default_profile (the only command that can change it).";
    static override args = {
        name: Args.string({ description: "profile name", required: true }),
    };

    protected override persistentRequired = false;
    protected override resolveMode: "if-needed" = "if-needed";

    public async run(): Promise<void> {
        const { args, flags } = await this.parse(ProfileUse);
        this.latchCiFlag(flags.ci);
        const { writer } = await this.resolveContext(flags);
        assertProfileName(args.name);
        if (!writer.get(args.name)) exitWith(ExitCode.ProfileNotFound);
        writer.useDefaultProfile(args.name);
        this.log(`default_profile = ${args.name}`);
    }
}
