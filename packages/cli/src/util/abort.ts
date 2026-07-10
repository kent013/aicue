/**
 * Structural detection of an "abort-like" error (F-0-03).
 *
 * `fetch` cancellation surfaces in several shapes depending on the runtime:
 * a `DOMException` named `AbortError`, an undici error with `code:
 * "ABORT_ERR"`, or a legacy `DOMException.ABORT_ERR` numeric code (20).
 * Some libraries wrap the real cause one level deep. We walk the object
 * structurally (not via `instanceof Error`) so an abort that is not an
 * `Error` subclass is still recognised, following the `cause` chain with a
 * depth cap to avoid runaway cycles.
 */
export function isAbortLikeError(e: unknown, depth = 0): boolean {
    if (depth > 5) return false;
    if (typeof e !== "object" || e === null) return false;
    const name = (e as { name?: unknown }).name;
    if (name === "AbortError") return true;
    const code = (e as { code?: unknown }).code;
    // "ABORT_ERR" (undici) / 20 (DOMException.ABORT_ERR legacy numeric code).
    if (code === "ABORT_ERR" || code === 20) return true;
    const cause = (e as { cause?: unknown }).cause;
    if (cause !== undefined && cause !== e) {
        return isAbortLikeError(cause, depth + 1);
    }
    return false;
}
