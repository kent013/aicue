import { ENV } from "./branding.js";
/**
 * Process-wide runtime state: CI-mode detection and the plaintext
 * credential guard. Consolidated here (was `ci.ts` + `credential/plaintext-flag.ts`)
 * so every latch lives in one module; `BaseCommand.latchCiFlag()` is
 * the single entry point that sets both.
 *
 * **Why module-state instead of DI**: commander's action callbacks
 * didn't thread a context object, so every helper deep in the credential
 * stack would otherwise need its own ci/plaintext params. oclif's
 * `BaseCommand` could reintroduce DI, but the latches are set once per
 * invocation and read in many call sites — module state is still the
 * pragmatic choice for v1.
 */

// ---------------------------------------------------------------------------
// CI mode
// ---------------------------------------------------------------------------

export type CiArgv = {
    readonly ci?: boolean;
};

let globalCiFlag = false;

export function setGlobalCiFlag(value: boolean): void {
    globalCiFlag = value;
}

export function resetCiFlagForTest(): void {
    globalCiFlag = false;
}

/**
 * CI mode is active when any of:
 *   1. The user passed `--ci` explicitly.
 *   2. The `CI` env var is `"true"`.
 *   3. stdout is not a TTY (piped / redirected / headless runner).
 *
 * The three triggers are intentionally OR-ed: any single indicator is
 * enough. Matches the convention used by popular tools (GitHub Actions
 * sets `CI=true`; Jenkins/Travis/CircleCI set it too; piped stdout
 * implies non-interactive).
 *
 * CI-mode effects:
 *   - colour output suppressed (no colour dep today; canonical gate for
 *     future additions)
 *   - interactive prompts skipped (callers must pass `--yes` explicitly;
 *     `--yes` is NOT implied by `--ci` — Codex Round 3 decision)
 *   - Playwright auto-install disabled unless `--auto-install-browser`
 *   - unmet prerequisites cause hard-fail (reproducibility over silent
 *     degradation)
 */
export function isCiMode(argv: CiArgv): boolean {
    if (argv.ci === true) return true;
    if (process.env["CI"] === "true") return true;
    if (!process.stdout.isTTY) return true;
    return false;
}

export function isCiModeGlobal(): boolean {
    return isCiMode({ ci: globalCiFlag });
}

// ---------------------------------------------------------------------------
// Plaintext credential guard
// ---------------------------------------------------------------------------

let allowPlaintext = false;

export function setGlobalAllowPlaintextFlag(on: boolean): void {
    allowPlaintext = on;
}

export function globalAllowPlaintextFlag(): boolean {
    return allowPlaintext;
}

/**
 * Mirror of the `--ci` flag specifically for the plaintext guard (B2 /
 * T144). Kept as a separate latch from `globalCiFlag` because the guard
 * uses a **stricter** CI detection than {@link isCiMode} — shell
 * redirection (non-TTY) on a dev laptop is interactive in spirit, so
 * non-TTY alone must NOT forbid plaintext. Merging the latches would
 * break local workflows.
 */
let plaintextGuardCiFlag = false;

export function setGlobalCiFlagForPlaintextGuard(on: boolean): void {
    plaintextGuardCiFlag = on;
}

export function resetCiFlagForPlaintextGuardForTest(): void {
    plaintextGuardCiFlag = false;
}

/**
 * Read the strict plaintext-guard CI latch (kept for call-site
 * compatibility; `BaseCommand.latchCiFlag` still sets it and tests still
 * reset it). The value is intentionally **not** consulted by
 * {@link isPlaintextOptInAllowed} anymore — see F-0-02 below.
 */
export function plaintextGuardCiFlagForTest(): boolean {
    return plaintextGuardCiFlag;
}

/**
 * Resolve the effective plaintext-opt-in state (F-0-02 fix).
 *
 * Plaintext is permitted **only when the user explicitly opts in**:
 *   - CLI flag `--allow-plaintext-credentials` (→ `allowPlaintext` latch)
 *   - env var `${ENV.ALLOW_PLAINTEXT}=1`
 *
 * Both are explicit, so they are honoured even in CI mode. The previous
 * implementation refused plaintext in CI even when the user opted in,
 * which made it impossible to persist credentials non-interactively /
 * on CI runners (F-0-02). Without an explicit opt-in the result is still
 * `false`, so no "implicit plaintext degrade" can ever happen.
 */
export function isPlaintextOptInAllowed(
    env: NodeJS.ProcessEnv = process.env,
): boolean {
    if (env[ENV.ALLOW_PLAINTEXT] === "1") {
        return true;
    }
    return allowPlaintext;
}
