import { Flags } from "@oclif/core";

/**
 * Flags shared by every command that can consume the profile resolution
 * stack (profile:*, auth:*, config:*, read/write commands). Kept in one
 * place so `--profile` / `--api-url` / `--api-key` semantics stay uniform
 * across the CLI.
 */
export const profileFlags = {
    profile: Flags.string({ description: "use the named profile" }),
    "api-url": Flags.string({
        description:
            "use an ephemeral profile (read-only for profile-gated commands)",
    }),
    "api-key": Flags.string({ description: "override API key" }),
    "allow-plaintext-credentials": Flags.boolean({
        description: "permit unencrypted file-store writes",
    }),
};

/**
 * Output-shape flags for read commands. Kept separate so write commands
 * don't accidentally inherit `--json`/`--quiet` they don't honour.
 */
export const readOutputFlags = {
    json: Flags.boolean({ description: "output JSON instead of a table" }),
    quiet: Flags.boolean({ description: "suppress non-essential output" }),
};

/**
 * Top-level `--ci` flag. Attached to every command via BaseCommand so the
 * CI latch can be set consistently regardless of which command was
 * invoked (oclif doesn't have a "global flag" concept the way commander's
 * top-level program did).
 */
export const ciFlag = {
    ci: Flags.boolean({
        description: "force CI mode (no colour, no interactive prompts)",
    }),
};
