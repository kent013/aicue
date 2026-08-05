import { apiGet } from "../../api/client.js";
import { MeResponseSchema } from "../../api/schemas.js";
import { formatJson } from "../../output/json.js";
import { ReadCommand } from "../base/ReadCommand.js";

/**
 * `whoami` — confirm the active API key resolves to an
 * organization on the configured API server.
 *
 * Output format is fixed at 4 lines of `key: value` pairs so downstream
 * `head -4 | awk` style parsers keep working. Adding a new line here is
 * a contract change — see `cli-commands.md` U-52.
 */
export default class Whoami extends ReadCommand {
    static override description =
        "Show the organization and API key for the active profile.";

    public async run(): Promise<void> {
        const { flags } = await this.parse(Whoami);
        this.latchCiFlag(flags.ci);
        const { ctx } = await this.resolveApiCtx(flags);
        const result = await apiGet(ctx, "/me", MeResponseSchema);
        if (!result.ok) this.exitOnFailure(result);
        const me = result.data.data;
        if (flags.json === true) {
            this.log(formatJson(me));
            return;
        }
        const lines: string[] = [
            `Organization: ${me.organizationName} (ID: ${String(me.organizationId)})`,
            `Plan: ${me.plan}`,
        ];
        if (me.apiKey !== null) {
            lines.push(`API Key: ${me.apiKey.tokenPrefix}`);
            lines.push(`Expires: ${me.apiKey.expiresAt ?? "(never)"}`);
        } else if (me.session != null) {
            lines.push(`Session: ${me.session.id}`);
            lines.push(`Scopes: ${me.session.scopes.join(", ")}`);
        }
        this.log(lines.join("\n"));
    }
}
