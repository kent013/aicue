import { formatTable, type TableColumn } from "../../../output/table.js";
import { assertProfileHasApiUrl } from "../../../profile/assertions.js";
import { canonicalOrigin } from "../../../profile/canonical-origin.js";
import { ProfileCommand } from "../../base/ProfileCommand.js";

const COLUMNS: ReadonlyArray<TableColumn> = [
    { header: "NAME" },
    { header: "API_URL" },
    { header: "ENV_TAG" },
    { header: "KEY" },
    { header: "VERIFIED" },
];

export default class ProfileList extends ProfileCommand {
    static override description = "List registered profiles (summary).";

    protected override persistentRequired = false;
    protected override resolveMode: "if-needed" = "if-needed";

    public async run(): Promise<void> {
        const { flags } = await this.parse(ProfileList);
        this.latchCiFlag(flags.ci);
        const { writer, store } = await this.resolveContext(flags);
        const rows = writer.list();
        if (rows.length === 0) {
            this.log("(no profiles registered)");
            return;
        }
        const tableRows = rows.map(({ name, entry }) => {
            const url = assertProfileHasApiUrl(entry, name);
            const hasKey = store.has(canonicalOrigin(url), name, "apikey", "");
            return [
                name,
                url,
                entry.environment_tag ?? "-",
                hasKey ? "yes" : "no",
                entry.last_verified_at ?? "never",
            ];
        });
        // indent="" で既存出力（先頭インデントなし）と互換維持。
        // CJK 全角プロファイル名（例: 日本語）でも列が崩れないよう
        // formatTable の visualWidth() に整列を委譲する。
        this.log(formatTable(COLUMNS, tableRows, { indent: "" }));
    }
}
