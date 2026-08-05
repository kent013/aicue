import { mkdirSync, existsSync } from "node:fs";
import { dirname } from "node:path";
import { stringify as stringifyYaml } from "yaml";
import { atomicWriteFile } from "../util/atomic-write.js";
import { RootConfigInputSchema, type RootConfigInput } from "./schema.js";

/**
 * Write a RootConfigInput to the given path with **atomic replacement**
 * (tmp write -> fsync -> rename), so a failed/partial write never leaves a
 * truncated config behind — losing the file would drop every registered
 * profile at once.
 *
 * Note: this is atomic *replacement*, not crash durability. The parent
 * directory is not fsynced, so a power loss right after the rename may still
 * lose the update. Full durability would need a directory fsync and is out
 * of scope (see devnotes/20260805-0101-devtool-template-followup).
 *
 * Creates the parent directory if it does not exist. The input is
 * re-validated with `RootConfigInputSchema.parse` before writing so that we
 * never persist an invalid config.
 */
export function saveConfigToPath(path: string, config: RootConfigInput): void {
    const validated = RootConfigInputSchema.parse(config);
    const parent = dirname(path);
    if (!existsSync(parent)) {
        mkdirSync(parent, { recursive: true });
    }
    const yaml = stringifyYaml(validated);
    atomicWriteFile(path, yaml, 0o600);
}
