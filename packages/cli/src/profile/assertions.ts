import type { ProfileEntry } from "../config/schema.js";
import { ExitCode, exitWith } from "../exit-codes.js";

/**
 * ProfileEntrySchema keeps `api_url` optional for legacy (U-14) compatibility.
 * Runtime callers that require the URL go through this helper so we never
 * use non-null assertions on a schema-optional field.
 *
 * If `api_url` is missing (only happens for hand-edited config files), we
 * exit with ConfigInconsistent (5) and guide the user to re-create the
 * profile via `profile:add`.
 */
export function assertProfileHasApiUrl(
    entry: ProfileEntry,
    name: string,
): string {
    const url = entry.api_url;
    if (!url || typeof url !== "string" || url.length === 0) {
        console.error(
            `Error: profile "${name}" is missing api_url (ConfigInconsistent). `
                + `Run \`profile:delete ${name}\` and re-create with profile:add.`,
        );
        exitWith(ExitCode.ConfigInconsistent);
    }
    return url;
}
