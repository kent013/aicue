import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const pkg = JSON.parse(
    readFileSync(join(here, "../package.json"), "utf-8"),
) as {
    name: string;
    version: string;
    description?: string;
};

/**
 * CLI package version. Single source of truth for:
 *   - the `version` oclif command,
 *   - every verify / verify-rollback path that sends `min_cli_version`
 *     to the server.
 *
 * Read from `packages/cli/package.json` at module load (repeat lookups
 * are free). The module URL resolution works both in `dist/` and
 * `src/` layouts because `../package.json` is one level up from either.
 */
export function getCliVersion(): string {
    return pkg.version;
}
