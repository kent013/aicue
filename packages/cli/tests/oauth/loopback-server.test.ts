import { fetch } from "undici";
import { describe, expect, it } from "vitest";
import { startLoopbackServer } from "../../src/oauth/loopback-server.js";

describe("loopback-server", () => {
    it("binds 127.0.0.1 on an ephemeral port and captures the code", async () => {
        const handle = await startLoopbackServer({ expectedState: "st-1" });
        expect(handle.redirectUri).toMatch(
            /^http:\/\/127\.0\.0\.1:\d+\/callback$/,
        );

        const res = await fetch(
            `${handle.redirectUri}?code=abc123&state=st-1`,
        );
        expect(res.status).toBe(200);
        const result = await handle.waitForCallback;
        expect(result.code).toBe("abc123");
    });

    it("rejects on state mismatch (CSRF) and returns 400", async () => {
        const handle = await startLoopbackServer({ expectedState: "good" });
        const assertion = expect(handle.waitForCallback).rejects.toThrow(
            /CSRF/,
        );
        const res = await fetch(
            `${handle.redirectUri}?code=abc&state=evil`,
        );
        expect(res.status).toBe(400);
        await assertion;
    });

    it("rejects on an authorization error param", async () => {
        const handle = await startLoopbackServer({ expectedState: "s" });
        const assertion = expect(handle.waitForCallback).rejects.toThrow(
            /access_denied.*nope/,
        );
        await fetch(
            `${handle.redirectUri}?error=access_denied&error_description=nope&state=s`,
        );
        await assertion;
    });

    it("rejects when no code is present", async () => {
        const handle = await startLoopbackServer({ expectedState: "s" });
        const assertion = expect(handle.waitForCallback).rejects.toThrow(
            /authorization code/,
        );
        await fetch(`${handle.redirectUri}?state=s`);
        await assertion;
    });

    it("404s non-callback paths without settling", async () => {
        const handle = await startLoopbackServer({ expectedState: "s" });
        const res = await fetch(handle.redirectUri.replace("/callback", "/x"));
        expect(res.status).toBe(404);
        // Settle it so the test cleans up.
        await fetch(`${handle.redirectUri}?code=c&state=s`);
        await handle.waitForCallback;
    });

    it("times out when no callback arrives", async () => {
        const handle = await startLoopbackServer({
            expectedState: "s",
            timeoutMs: 50,
        });
        await expect(handle.waitForCallback).rejects.toThrow(/Timed out/);
    });

    it("only settles once (first callback wins)", async () => {
        const handle = await startLoopbackServer({ expectedState: "s" });
        await fetch(`${handle.redirectUri}?code=first&state=s`);
        const result = await handle.waitForCallback;
        expect(result.code).toBe("first");
        handle.close();
    });
});
