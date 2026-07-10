import { createHash, randomBytes } from "node:crypto";

/**
 * RFC 7636 PKCE code_verifier / code_challenge generation (T715 Phase 3).
 *
 * - verifier: 43-char unreserved string from 32 random bytes, base64url
 *   encoded (RFC 7636 §4.1 recommended entropy).
 * - challenge: S256 only. The server's `McpAuthorizationCodeGrant`
 *   rejects `plain`, so the CLI never offers it.
 * - state: 16 random bytes, base64url, for CSRF protection on the loopback
 *   redirect.
 */

function base64url(buffer: Buffer): string {
    return buffer
        .toString("base64")
        .replace(/\+/g, "-")
        .replace(/\//g, "_")
        .replace(/=+$/, "");
}

export function generateCodeVerifier(): string {
    return base64url(randomBytes(32));
}

export function codeChallengeS256(verifier: string): string {
    return base64url(createHash("sha256").update(verifier).digest());
}

export function generateState(): string {
    return base64url(randomBytes(16));
}
