import { createHash } from "node:crypto";
import { describe, expect, it } from "vitest";
import {
    codeChallengeS256,
    generateCodeVerifier,
    generateState,
} from "../../src/oauth/pkce.js";

function base64urlOf(buf: Buffer): string {
    return buf
        .toString("base64")
        .replace(/\+/g, "-")
        .replace(/\//g, "_")
        .replace(/=+$/, "");
}

describe("pkce", () => {
    it("verifier is base64url (43 chars, RFC 7636 unreserved set)", () => {
        const v = generateCodeVerifier();
        expect(v).toMatch(/^[A-Za-z0-9_-]{43}$/);
    });

    it("verifier is high-entropy (no repeats across many draws)", () => {
        const seen = new Set<string>();
        for (let i = 0; i < 200; i++) seen.add(generateCodeVerifier());
        expect(seen.size).toBe(200);
    });

    it("challenge is the S256 (base64url SHA-256) of the verifier", () => {
        const v = generateCodeVerifier();
        const expected = base64urlOf(
            createHash("sha256").update(v).digest(),
        );
        expect(codeChallengeS256(v)).toBe(expected);
    });

    it("challenge contains no base64 padding or non-url chars", () => {
        const c = codeChallengeS256(generateCodeVerifier());
        expect(c).toMatch(/^[A-Za-z0-9_-]+$/);
        expect(c).not.toContain("=");
    });

    it("state is base64url and unique", () => {
        const a = generateState();
        const b = generateState();
        expect(a).toMatch(/^[A-Za-z0-9_-]+$/);
        expect(a).not.toBe(b);
    });
});
