import { ENV, BIN_NAME } from "../branding.js";
import type { CredentialStore } from "./store.js";

let printed = false;

/** Reset internal "printed" cache — tests only. */
export function __resetPrintedBackendOnce(): void {
    printed = false;
}

/**
 * Print the credential backend banner at most once per process (F-0-05).
 *
 * `printBanner` lets machine-readable callers (`--json` / `--quiet`) suppress
 * the banner entirely while still latching the "printed" state so a later
 * human-facing call in the same process does not double-print. The banner is
 * emitted lazily at the moment a credential is first actually read or written
 * (via {@link CredentialStore.prepareForAccess}), so read-only / local-only
 * commands never see the UNAVAILABLE noise.
 */
export function printBackendOnce(
    store: CredentialStore,
    printBanner: boolean,
): void {
    if (printed) return;
    printed = true;
    if (!printBanner) return;
    const b = store.backend();
    const msg =
        b === "keychain"
            ? "credential backend: OS keychain"
            : b === "file-encrypted"
              ? "credential backend: encrypted file store"
              : b === "file-plaintext"
                ? "credential backend: PLAINTEXT file store (unencrypted, opt-in). "
                    + `Secrets are written UNENCRYPTED under ~/.${BIN_NAME}/credentials. `
                    + `Prefer ${ENV.MASTER_PASSWORD} / ${ENV.CREDENTIAL_KEY} for CI.`
                : "credential backend: UNAVAILABLE "
                    + `(set ${ENV.CREDENTIAL_KEY} / ${ENV.MASTER_PASSWORD} or `
                    + "--allow-plaintext-credentials to enable the file store)";
    console.error(msg);
}
