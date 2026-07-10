import { describe, expect, it } from "vitest";
import { ExitCode } from "../src/exit-codes.js";

describe("ExitCode registry", () => {
    it("auth domain constants have the agreed numeric values", () => {
        expect(ExitCode.AuthCredentialNotFound).toBe(20);
        expect(ExitCode.AuthTypeUnknown).toBe(21);
        expect(ExitCode.AuthValidationFailed).toBe(22);
        expect(ExitCode.AuthTestFailed).toBe(24);
        expect(ExitCode.AuthExportForbidden).toBe(25);
        expect(ExitCode.AuthEnvironmentNotFound).toBe(27);
        expect(ExitCode.AuthDependencyMissing).toBe(28);
    });

    it("23, 26 and 29 are reserved (intentionally absent as values)", () => {
        // 23 was always reserved. 26 was AuthMigrationConflict before
        // T145/YAGNI-02 removed the 2-phase migration machinery; the slot
        // is intentionally kept empty so CI scripts that once branched on
        // 26 surface as distinct errors if the code resurfaces.
        const values = new Set<number>(Object.values(ExitCode));
        expect(values.has(23)).toBe(false);
        expect(values.has(26)).toBe(false);
        expect(values.has(29)).toBe(false);
    });

    it("encryption codes 30-33 stay assigned after migration cleanup", () => {
        expect(ExitCode.EncryptionKeyMissing).toBe(30);
        expect(ExitCode.EncryptionKeyInvalid).toBe(31);
        expect(ExitCode.MasterPasswordRequired).toBe(32);
        expect(ExitCode.DecryptionFailed).toBe(33);
    });

    it("migration slots 34/35/39 are reserved after T145 cleanup", () => {
        // Pre-T145: 34 = MigrationBackupMissing, 35 = MigrationBackupExpired,
        // 39 = MigrationMetaCorrupted. All three were deleted with the
        // 2-phase migration mechanism. The numeric holes MUST remain empty
        // in case a user surfaces an old CI log referencing those codes.
        const values = new Set<number>(Object.values(ExitCode));
        expect(values.has(34)).toBe(false);
        expect(values.has(35)).toBe(false);
        expect(values.has(39)).toBe(false);
    });

    it("form redirect/bot codes 36/37 are assigned (U-24)", () => {
        expect(ExitCode.FormRedirectTooManyHops).toBe(36);
        expect(ExitCode.FormBotDetectionDetected).toBe(37);
    });

    it("38 is reserved (intentionally absent as a value)", () => {
        const values = new Set<number>(Object.values(ExitCode));
        expect(values.has(38)).toBe(false);
    });

    it("scan domain constants have the agreed numeric values (U-34)", () => {
        expect(ExitCode.ScanAdapterNotFound).toBe(40);
        expect(ExitCode.ScanNoRoutes).toBe(41);
        expect(ExitCode.ScanAdapterFailed).toBe(42);
    });

    it("50 is reserved after T147 cleanup (was ProfileMismatch)", () => {
        // Pre-T147: 50 = ProfileMismatch. Removed with the
        // `site.expected_profile` guard in T147/YAGNI-05. The numeric hole
        // MUST remain empty so operators reading an old CI log referencing
        // exit 50 never get a new meaning silently reassigned.
        const values = new Set<number>(Object.values(ExitCode));
        expect(values.has(50)).toBe(false);
    });

    it("all ExitCode values are unique", () => {
        const values = Object.values(ExitCode);
        expect(new Set(values).size).toBe(values.length);
    });

    it("profile (10-19) and auth (20-28) domains do not collide", () => {
        const profile = [10, 11, 12, 13, 14, 15, 16, 17, 18, 19];
        const auth = [20, 21, 22, 24, 25, 27, 28];
        const intersection = profile.filter((v) => auth.includes(v));
        expect(intersection).toEqual([]);
        const values = new Set<number>(Object.values(ExitCode));
        for (const p of profile) expect(values.has(p)).toBe(true);
        for (const a of auth) expect(values.has(a)).toBe(true);
    });
});
