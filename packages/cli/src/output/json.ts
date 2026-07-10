/**
 * Stable JSON output helper used by every read-side command's `--json` mode.
 *
 * Centralising it (instead of inlining `JSON.stringify(x, null, 2)`) makes it
 * trivial to evolve the contract — e.g. switch to NDJSON for streaming or
 * inject a metadata envelope — without touching every command. CI consumers
 * pipe `uxi <command> --json` straight into `jq`, so the indent and trailing
 * newline are part of the public surface.
 */
export function formatJson(value: unknown): string {
    return JSON.stringify(value, null, 2);
}
