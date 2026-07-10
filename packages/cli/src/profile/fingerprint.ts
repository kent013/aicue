import { createHash } from "node:crypto";

export function endpointFingerprint(canonicalOrigin: string): string {
    return createHash("sha256")
        .update(canonicalOrigin)
        .digest("hex")
        .slice(0, 16);
}
