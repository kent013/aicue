import { spawn } from "node:child_process";

/**
 * Open a URL in the OS default browser. Resolves `true` when the launcher
 * process actually started (T715 Phase 3).
 *
 * `spawn` reports a missing launcher (ENOENT) asynchronously via the
 * `error` event, not as a synchronous throw, so success is decided on the
 * `spawn` event. Callers fall back to printing the URL when this returns
 * `false`.
 */
export function openInBrowser(url: string): Promise<boolean> {
    const platform = process.platform;
    const command =
        platform === "darwin"
            ? "open"
            : platform === "win32"
              ? "cmd"
              : "xdg-open";
    const args = platform === "win32" ? ["/c", "start", "", url] : [url];

    return new Promise<boolean>((resolve) => {
        let child;
        try {
            child = spawn(command, args, {
                stdio: "ignore",
                detached: true,
            });
        } catch {
            resolve(false);
            return;
        }
        child.once("error", () => resolve(false));
        // `spawn` event = process started (ENOENT does not reach here).
        child.once("spawn", () => {
            child.unref();
            resolve(true);
        });
    });
}
