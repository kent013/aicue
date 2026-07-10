import { getCliVersion } from "../../index.js";
import { BaseCommand } from "../base/BaseCommand.js";

export default class VersionCommand extends BaseCommand {
    static override description = "Show CLI version";
    static override strict = true;

    public async run(): Promise<void> {
        const { flags } = await this.parse(VersionCommand);
        this.latchCiFlag(flags.ci);
        this.log(getCliVersion());
    }
}
