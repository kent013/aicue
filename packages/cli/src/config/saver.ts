import { writeFileSync, mkdirSync, existsSync } from "node:fs";
import { dirname } from "node:path";
import { stringify as stringifyYaml } from "yaml";
import { RootConfigInputSchema, type RootConfigInput } from "./schema.js";

/**
 * Atomically write a RootConfigInput to the given path.
 * Creates the parent directory if it does not exist.
 *
 * The input is re-validated with `RootConfigInputSchema.parse` before writing
 * so that we never persist an invalid config.
 */
export function saveConfigToPath(path: string, config: RootConfigInput): void {
    const validated = RootConfigInputSchema.parse(config);
    const parent = dirname(path);
    if (!existsSync(parent)) {
        mkdirSync(parent, { recursive: true });
    }
    const yaml = stringifyYaml(validated);
    writeFileSync(path, yaml, { encoding: "utf-8", mode: 0o600 });
}
