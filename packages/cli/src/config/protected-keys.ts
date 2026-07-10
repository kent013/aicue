import { BIN_NAME } from "../branding.js";
// Protected (immutable via config:*) key classifier.
// These keys are readable via config:get / config:list but cannot be mutated
// through config:set / config:unset — dedicated commands (profile:*) own them.

const IMMUTABLE_KEYS: ReadonlyArray<string> = ["default_profile", "profile"];

const IMMUTABLE_PREFIXES: ReadonlyArray<string> = ["profiles"];

export function isProtectedKey(key: string): boolean {
    if (IMMUTABLE_KEYS.includes(key)) return true;
    for (const prefix of IMMUTABLE_PREFIXES) {
        if (key === prefix) return true;
        // Use explicit "." separator to avoid matching "profilesX".
        if (key.startsWith(prefix + ".")) return true;
    }
    return false;
}

export function protectedReason(key: string): string {
    if (key === "default_profile") {
        return `Use \`${BIN_NAME} profile:use <name>\` to change the default profile.`;
    }
    if (key === "profile") {
        return `Use \`${BIN_NAME} profile:use <name>\` to change the selected profile.`;
    }
    if (key === "profiles" || key.startsWith("profiles.")) {
        return `Use \`${BIN_NAME} profile:add/delete/set-key/set-option/verify\` to manage profiles.`;
    }
    return "This key is managed by other commands.";
}
