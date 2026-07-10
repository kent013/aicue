import { readFileSync, existsSync } from "node:fs";
import { parse as parseYaml } from "yaml";
import { RootConfigInputSchema, type RootConfigInput } from "./schema.js";
import { userConfigPath, projectConfigPath, type ConfigScope } from "./paths.js";
import { deepMergeWithDefaults } from "./merge.js";
import type { EffectiveConfig } from "./schema.js";

/**
 * Load YAML from an explicit path and validate as RootConfigInput.
 *
 * If the file does not exist, returns `undefined`. Loader does NOT treat
 * missing files as errors — this matches the UX intent that users may have
 * only a user-scope or only a project-scope config.
 *
 * If the file exists but is empty, returns `undefined`.
 *
 * Throws `ZodError` on schema violation (including unknown keys), and
 * `YAMLParseError` on syntax error.
 */
export function loadConfigFromPath(path: string): RootConfigInput | undefined {
    if (!existsSync(path)) return undefined;
    const raw = readFileSync(path, "utf-8");
    if (raw.trim() === "") return undefined;

    const parsed: unknown = parseYaml(raw);
    if (parsed === undefined || parsed === null) return undefined;

    assertNoForbiddenKeys(parsed);
    return RootConfigInputSchema.parse(parsed);
}

const FORBIDDEN_KEYS: ReadonlySet<string> = new Set([
    "__proto__",
    "prototype",
    "constructor",
]);

function assertNoForbiddenKeys(value: unknown, path: string = ""): void {
    if (value === null || typeof value !== "object") return;
    if (Array.isArray(value)) {
        for (let i = 0; i < value.length; i++) {
            assertNoForbiddenKeys(value[i], `${path}[${i}]`);
        }
        return;
    }
    for (const k of Object.keys(value as Record<string, unknown>)) {
        if (FORBIDDEN_KEYS.has(k)) {
            throw new Error(
                `Forbidden key in config: ${path ? path + "." : ""}${k}`,
            );
        }
        assertNoForbiddenKeys(
            (value as Record<string, unknown>)[k],
            path ? `${path}.${k}` : k,
        );
    }
}

/**
 * Load the config file for the given scope using conventional paths.
 */
export function loadScope(
    scope: ConfigScope,
    cwd?: string,
): RootConfigInput | undefined {
    const path = scope === "user" ? userConfigPath() : projectConfigPath(cwd);
    return loadConfigFromPath(path);
}

/**
 * Load both scopes and return them as an object. Either may be `undefined`.
 */
export function loadAllScopes(cwd?: string): {
    user: RootConfigInput | undefined;
    project: RootConfigInput | undefined;
} {
    return {
        user: loadScope("user"),
        project: loadScope("project", cwd),
    };
}

/**
 * Load user + project and return the merged EffectiveConfig.
 */
export function loadEffective(cwd?: string): EffectiveConfig {
    const { user, project } = loadAllScopes(cwd);
    return deepMergeWithDefaults(user, project);
}
