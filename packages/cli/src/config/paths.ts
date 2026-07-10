import { BIN_NAME } from "../branding.js";
import { homedir } from "node:os";
import { join } from "node:path";

export type ConfigScope = "user" | "project";
export type ReadScope = ConfigScope | "effective";

export function userConfigPath(): string {
    return join(homedir(), `.${BIN_NAME}`, "config.yaml");
}

export function projectConfigPath(cwd: string = process.cwd()): string {
    return join(cwd, `.${BIN_NAME}`, "config.yaml");
}

export function configPathFor(scope: ConfigScope, cwd?: string): string {
    return scope === "user" ? userConfigPath() : projectConfigPath(cwd);
}
