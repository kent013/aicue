import { describe, expect, it } from "vitest";
import {
    dispatchKindFromCode,
    dispatchKindFromStatus,
    resolveFailureKind,
} from "../../src/api/client.js";
import {
    API_ERROR_CODES,
    NON_CANONICAL_API_ERROR_CODES,
} from "../../src/api/schemas.js";

/**
 * T261: the CLI-side `error.code` vocabulary drifted from the server enum
 * (`app/Enums/ApiErrorCode.php`): the CLI branched on `rate_limit_exceeded`
 * while the server emits `rate_limited`, and four server codes had no arm at
 * all. The value-set half is pinned by the enum sync gate
 * (`tests/js/architecture/enum-ts-sync.test.ts`); this file pins the half the
 * sync gate cannot see — that the codes actually classify responses the way
 * the server's `defaultStatus()` implies.
 */
describe("dispatchKindFromCode() — server codes", () => {
    it.each([
        ["unauthenticated", "auth"],
        ["forbidden", "auth"],
        ["insufficient_ability", "auth"],
        ["actor_not_resolvable", "auth"],
        ["not_found", "not-found"],
        ["validation_failed", "validation"],
        ["rate_limited", "rate-limit"],
        ["idempotency_conflict", "conflict"],
        ["idempotency_in_progress", "conflict"],
        ["idempotency_indeterminate", "conflict"],
        ["internal_server_error", "server"],
    ] as const)("%s → %s", (code, kind) => {
        expect(dispatchKindFromCode(code)).toBe(kind);
    });

    it("classifies every server code (no silent fall-through to status)", () => {
        const unclassified = API_ERROR_CODES.filter(
            (code) => dispatchKindFromCode(code) === null,
        );
        expect(unclassified).toEqual([]);
    });
});

describe("dispatchKindFromCode() — non-canonical surface-local codes", () => {
    it.each([
        ["quota_exceeded", "quota"],
        ["payload_sanitization_failed", "validation"],
        ["site_not_cli_capture", "validation"],
        ["use_audits_submit", "validation"],
    ] as const)("%s → %s", (code, kind) => {
        expect(dispatchKindFromCode(code)).toBe(kind);
    });

    it("classifies every non-canonical code", () => {
        const unclassified = NON_CANONICAL_API_ERROR_CODES.filter(
            (code) => dispatchKindFromCode(code) === null,
        );
        expect(unclassified).toEqual([]);
    });
});

describe("dispatchKindFromCode() — unknown codes", () => {
    it("returns null so the caller falls back to HTTP status", () => {
        expect(dispatchKindFromCode("something_the_cli_never_learned")).toBeNull();
        expect(dispatchKindFromCode(null)).toBeNull();
    });

    it("does not keep the retired spelling as an alias", () => {
        expect(dispatchKindFromCode("rate_limit_exceeded")).toBeNull();
    });
});

/**
 * `resolveFailureKind` is the combination point the client uses: the code wins,
 * an unknown / absent code falls back to the status. Pinning it as a pure
 * function keeps "the code decided" and "the status decided" distinguishable
 * (an end-to-end response assertion cannot tell them apart).
 */
describe("resolveFailureKind() — code first, status as the safety net", () => {
    it("rate_limited + 429 → rate-limit (decided by the code)", () => {
        expect(resolveFailureKind("rate_limited", 429)).toBe("rate-limit");
    });

    it("retired rate_limit_exceeded + 429 → rate-limit (decided by the status)", () => {
        expect(dispatchKindFromCode("rate_limit_exceeded")).toBeNull();
        expect(dispatchKindFromStatus(429)).toBe("rate-limit");
        expect(resolveFailureKind("rate_limit_exceeded", 429)).toBe("rate-limit");
    });

    it("unknown code + 409 → conflict (status fallback still works)", () => {
        expect(resolveFailureKind("brand_new_server_code", 409)).toBe("conflict");
    });

    it("a code that disagrees with the status is decided by the code", () => {
        expect(resolveFailureKind("idempotency_in_progress", 500)).toBe("conflict");
    });
});
