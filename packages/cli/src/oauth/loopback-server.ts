import { DISPLAY_NAME } from "../branding.js";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";

/**
 * Loopback HTTP server that captures the OAuth redirect (T715 Phase 3,
 * RFC 8252 §7.3 — native-app loopback redirect).
 *
 * Listens on `127.0.0.1` with an ephemeral port (`listen(0)`) and waits for
 * a single redirect to `/callback`:
 *
 * - `state` mismatch is treated as CSRF and rejected (server closes).
 * - an authorization error (`error=access_denied`, …) is surfaced.
 * - a timeout (default 5 minutes) rejects.
 *
 * The redirect host is pinned to the literal `127.0.0.1` (never `localhost`,
 * which is name-resolved and therefore vulnerable to DNS rebinding).
 */

export type LoopbackResult = {
    code: string;
};

export type LoopbackServerHandle = {
    /** Actually-bound redirect URI, e.g. http://127.0.0.1:54321/callback */
    redirectUri: string;
    /** Settles on code receipt / error / timeout. */
    waitForCallback: Promise<LoopbackResult>;
    /** Explicit close (idempotent), used on cancellation. */
    close: () => void;
};

const SUCCESS_HTML = `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>${DISPLAY_NAME}</title></head>
<body style="font-family: sans-serif; text-align: center; padding-top: 4rem;">
<h1>Login complete</h1><p>You can close this tab and return to your terminal.</p>
</body></html>`;

const FAILURE_HTML = `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>${DISPLAY_NAME}</title></head>
<body style="font-family: sans-serif; text-align: center; padding-top: 4rem;">
<h1>Login failed</h1><p>Check the error message in your terminal.</p>
</body></html>`;

export async function startLoopbackServer(options: {
    expectedState: string;
    timeoutMs?: number;
}): Promise<LoopbackServerHandle> {
    const timeoutMs = options.timeoutMs ?? 5 * 60 * 1000;

    let server: Server;
    let settled = false;
    let timer: NodeJS.Timeout | undefined;
    let resolveCallback: (result: LoopbackResult) => void;
    let rejectCallback: (error: Error) => void;

    const waitForCallback = new Promise<LoopbackResult>((resolve, reject) => {
        resolveCallback = resolve;
        rejectCallback = reject;
    });

    const close = (): void => {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }
        server.close();
        // Force-close keep-alive sockets so the process exits promptly.
        server.closeAllConnections?.();
    };

    const settle = (fn: () => void): void => {
        if (settled) return;
        settled = true;
        fn();
        close();
    };

    server = createServer((req, res) => {
        const url = new URL(req.url ?? "/", "http://127.0.0.1");
        if (url.pathname !== "/callback") {
            res.writeHead(404).end();
            return;
        }

        const error = url.searchParams.get("error");
        const code = url.searchParams.get("code");
        const state = url.searchParams.get("state");

        if (error !== null) {
            res.writeHead(200, {
                "Content-Type": "text/html; charset=utf-8",
            }).end(FAILURE_HTML);
            const description = url.searchParams.get("error_description");
            settle(() =>
                rejectCallback(
                    new Error(
                        `Authorization failed: ${error}`
                            + (description !== null ? ` (${description})` : ""),
                    ),
                ),
            );
            return;
        }

        if (state !== options.expectedState) {
            res.writeHead(400, {
                "Content-Type": "text/html; charset=utf-8",
            }).end(FAILURE_HTML);
            settle(() =>
                rejectCallback(
                    new Error(
                        "State mismatch on OAuth callback (possible CSRF).",
                    ),
                ),
            );
            return;
        }

        if (code === null || code === "") {
            res.writeHead(400, {
                "Content-Type": "text/html; charset=utf-8",
            }).end(FAILURE_HTML);
            settle(() =>
                rejectCallback(
                    new Error(
                        "OAuth callback did not include an authorization code.",
                    ),
                ),
            );
            return;
        }

        res.writeHead(200, {
            "Content-Type": "text/html; charset=utf-8",
        }).end(SUCCESS_HTML);
        settle(() => resolveCallback({ code }));
    });

    await new Promise<void>((resolve, reject) => {
        server.once("error", reject);
        server.listen(0, "127.0.0.1", () => resolve());
    });

    const address = server.address() as AddressInfo;
    const redirectUri = `http://127.0.0.1:${String(address.port)}/callback`;

    timer = setTimeout(() => {
        settle(() =>
            rejectCallback(
                new Error(
                    `Timed out waiting for the OAuth callback (${String(timeoutMs)} ms).`,
                ),
            ),
        );
    }, timeoutMs);
    // Don't keep the event loop alive solely for the timeout.
    timer.unref();

    return { redirectUri, waitForCallback, close };
}
