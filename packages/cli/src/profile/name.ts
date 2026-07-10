import { ExitCode, exitWith } from "../exit-codes.js";

const PROFILE_NAME_RE = /^[a-z0-9][a-z0-9_-]{0,62}$/;
const RESERVED = new Set(["default", "ephemeral", "system", "admin", "_internal"]);

export function isValidProfileName(name: string): boolean {
    if (!PROFILE_NAME_RE.test(name)) return false;
    if (RESERVED.has(name)) return false;
    if (name.startsWith("ephemeral-")) return false;
    return true;
}

export function assertProfileName(name: string): void {
    if (!isValidProfileName(name)) {
        console.error(
            `Error: invalid profile name "${name}". `
                + `Must match /^[a-z0-9][a-z0-9_-]{0,62}$/ and must not be one of `
                + `default, ephemeral, system, admin, _internal `
                + `(or start with "ephemeral-").`,
        );
        exitWith(ExitCode.ProfileInvalidName);
    }
}
