// CLI-wide exit code registry.
// Grouped by domain so future commands can extend without collision.

export const ExitCode = {
    Success: 0,
    GeneralError: 1,
    // config domain: 2-5
    ProtectedKey: 2,
    SchemaViolation: 3,
    KeyNotFound: 4,
    ConfigInconsistent: 5,
    // cross-domain 6-9 (generic):
    InvalidOptionKey: 7,
    // profile domain: 10-19 (U-15)
    ProfileConflict: 10,
    ProfileNotFound: 11,
    ProfileAlreadyExists: 12,
    ProfileInvalidName: 13,
    ConnectionFailed: 14,
    EnvironmentTagMismatch: 15,
    CapabilityMissing: 16,
    EphemeralRestricted: 17,
    CredentialStoreFailure: 18,
    ProfileVerifyRollback: 19,
    // auth domain: 20-29 (U-23 — T119)
    AuthCredentialNotFound: 20,
    AuthTypeUnknown: 21,
    AuthValidationFailed: 22,
    // 23 reserved (intentionally unused — see tests/exit-codes.test.ts)
    AuthTestFailed: 24,
    AuthExportForbidden: 25,
    // 26 reserved (was AuthMigrationConflict, removed in T145/YAGNI-02)
    AuthEnvironmentNotFound: 27,
    AuthDependencyMissing: 28,
    // 29 reserved (intentionally unused)
    // encryption domain: 30-39 (U-21 / U-24)
    // 34/35/39 were the 2-phase migration backup codes, removed in
    // T145/YAGNI-02 — the numbers are left as reserved holes so existing
    // CI scripts never get a new meaning silently reassigned.
    EncryptionKeyMissing: 30,
    EncryptionKeyInvalid: 31,
    MasterPasswordRequired: 32,
    DecryptionFailed: 33,
    // 34/35 reserved (were MigrationBackupMissing / MigrationBackupExpired)
    FormRedirectTooManyHops: 36,
    FormBotDetectionDetected: 37,
    // 38 reserved (intentionally unused)
    // 39 reserved (was MigrationMetaCorrupted)
    // scan domain: 40-49 (U-34 — T131)
    ScanAdapterNotFound: 40,
    ScanNoRoutes: 41,
    ScanAdapterFailed: 42,
    // 50 was ProfileMismatch (expected_profile guard, T142). Removed in
    // T147/YAGNI-05 — URL mismatches surface as 404/401 naturally, so the
    // guard duplicated work. The slot is intentionally left empty: if a
    // future runtime-safety code lands in the 50-59 range it should pick
    // an unused number rather than reuse 50 so operators reading old CI
    // logs never get a stealth meaning change.
} as const;

/**
 * `uxi run --wait` (T116/U-18) contract, per cli-commands.md §終了コード,
 * hardcodes exit 2 for "evaluation failed" and exit 3 for "polling timed
 * out". Those numbers are part of the public CI surface — GitHub Actions and
 * shell scripts branch on them — so they cannot be remapped without
 * breaking integrations.
 *
 * The same integer values are already used by the config domain
 * (`ProtectedKey=2`, `SchemaViolation=3`). The collision is intentional and
 * has been accepted in the T116 spec: `run` never emits config errors and
 * `config:*` never emits evaluation errors, so the numeric meaning is always
 * unambiguous from the command context. We deliberately do NOT add these as
 * keys on `ExitCode` because TypeScript's `as const` would treat the
 * duplicate-value members as inconsistent and because downstream code should
 * go through the dedicated helpers below rather than memorising the numbers.
 */
export const RunExitCode = {
    EvaluationFailed: 2,
    EvaluationTimeout: 3,
} as const satisfies Record<string, 2 | 3>;

export type ExitCodeName = keyof typeof ExitCode;
export type ExitCodeValue = (typeof ExitCode)[ExitCodeName];
export type RunExitCodeValue = (typeof RunExitCode)[keyof typeof RunExitCode];

/**
 * Thin wrapper around `process.exit` so tests can stub it without touching
 * the real process. The default implementation delegates to `process.exit`.
 */
export function exitWith(code: ExitCodeValue | RunExitCodeValue): never {
    process.exit(code);
}

/**
 * Explicit-intent wrapper for `uxi run --wait` failures. Callers should use
 * this instead of `exitWith(2)` so grep-ability of the failure path survives.
 */
export function exitAsEvaluationFailed(): never {
    process.exit(RunExitCode.EvaluationFailed);
}

/**
 * Explicit-intent wrapper for `uxi run --wait` polling timeouts. Mirrors
 * {@see exitAsEvaluationFailed}.
 */
export function exitAsEvaluationTimeout(): never {
    process.exit(RunExitCode.EvaluationTimeout);
}
