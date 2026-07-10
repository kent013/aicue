import { createHash } from "node:crypto";
import { canonicalOrigin } from "./canonical-origin.js";

export function ephemeralProfileName(apiUrl: string): string {
    const origin = canonicalOrigin(apiUrl);
    const hash = createHash("sha256").update(origin).digest("hex").slice(0, 8);
    return `ephemeral-${hash}`;
}
