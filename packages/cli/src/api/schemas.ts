import { z } from "zod";

import { EnvironmentTagSourceSchema } from "../schemas/environment-tag-source.js";

/**
 * Zod schemas for the REST API v1 read endpoints consumed by the CLI.
 *
 * Why `passthrough()` on the leaf objects: the server payload is owned by the
 * Laravel side (`app/Dtos/*`). New optional fields ship there frequently and
 * we want CLI upgrades to be decoupled from server adds — `strict()` would
 * fail every old-CLI ↔ new-server pair. The CLI only relies on the documented
 * fields below and never re-emits the parsed objects elsewhere, so the wider
 * surface is harmless. Pagination metadata is also `passthrough()` for the
 * same reason — Laravel may add `from`/`to`/`path` opportunistically. The
 * wrapping envelope ({data, links, meta}) is kept `strict()` because the
 * envelope shape itself is part of the negotiated contract (T108) and any
 * drift there is a real server bug we want to surface immediately.
 */

const PaginationLinksSchema = z
    .object({
        first: z.string().nullable(),
        last: z.string().nullable(),
        prev: z.string().nullable(),
        next: z.string().nullable(),
    })
    .strict();

const PaginationMetaSchema = z
    .object({
        current_page: z.number().int(),
        per_page: z.number().int(),
        total: z.number().int(),
        last_page: z.number().int(),
        from: z.number().int().nullable().optional(),
        to: z.number().int().nullable().optional(),
        path: z.string().optional(),
    })
    .passthrough();

function paginated<T extends z.ZodTypeAny>(item: T) {
    return z
        .object({
            data: z.array(item),
            links: PaginationLinksSchema,
            meta: PaginationMetaSchema,
        })
        .strict();
}

function envelope<T extends z.ZodTypeAny>(item: T) {
    return z
        .object({
            data: item,
        })
        .strict();
}

const MeApiKeySchema = z
    .object({
        id: z.number().int(),
        name: z.string(),
        tokenPrefix: z.string(),
        expiresAt: z.string().nullable(),
    })
    .passthrough();

const MeSessionSchema = z
    .object({
        id: z.string(),
        scopes: z.array(z.string()),
    })
    .passthrough();

const MeDataSchema = z
    .object({
        organizationId: z.number().int(),
        organizationName: z.string(),
        plan: z.string(),
        environmentTag: z.string().nullable(),
        environmentTagSource: EnvironmentTagSourceSchema,
        // T715: dual-actor。API Key actor は apiKey、OAuth user-token actor は session を持つ。
        actorKind: z.string().optional(),
        apiKey: MeApiKeySchema.nullable(),
        session: MeSessionSchema.nullable().optional(),
    })
    .passthrough();

export const MeResponseSchema = envelope(MeDataSchema);

/**
 * `DELETE /api/v1/me/session` response (T715 Phase 3). Mirrors
 * `SessionRevokedResource` (camelCase, `data`-wrapped).
 */
const SessionRevokedSchema = z
    .object({
        sessionId: z.string(),
        revoked: z.boolean(),
    })
    .passthrough();

export const SessionRevokedResponseSchema = envelope(SessionRevokedSchema);

const ProjectSchema = z
    .object({
        id: z.number().int(),
        name: z.string(),
        description: z.string().nullable(),
        organizationId: z.number().int(),
        sitesCount: z.number().int(),
        createdAt: z.string(),
        averageScore: z.number().nullable(),
        totalPages: z.number().int(),
    })
    .passthrough();

export const ProjectListResponseSchema = paginated(ProjectSchema);

const SiteSchema = z
    .object({
        id: z.number().int(),
        projectId: z.number().int(),
        name: z.string(),
        baseUrl: z.string().nullable(),
        type: z.string(),
        industry: z.string().nullable(),
        pagesCount: z.number().int(),
        averageScore: z.number().nullable(),
        // T129: `capture:login` gates on authMode=cli_capture. Old servers
        // predate this field, so it is optional at the schema layer — the
        // command layer treats `undefined` as "unknown, assume public".
        authMode: z.enum(["public", "cli_capture"]).optional(),
    })
    .passthrough();

export const SiteListResponseSchema = paginated(SiteSchema);

const PageSchema = z
    .object({
        id: z.number().int(),
        siteId: z.number().int(),
        name: z.string(),
        path: z.string(),
        fullUrl: z.string().nullable(),
        depth: z.number().int().nullable(),
        isEvaluationTarget: z.boolean(),
        isDeleted: z.boolean(),
        lastEvaluatedAt: z.string().nullable(),
        lastScore: z.number().nullable(),
        updatedAt: z.string().nullable(),
        // API は常に返すが、CLI はクライアントなので新規追加フィールドは
        // optional 扱い (旧 API サーバ互換 + 既存 page response mock 互換)。
        discoverySource: z.string().optional(),
    })
    .passthrough();

export const PageListResponseSchema = paginated(PageSchema);

const PersonaSchema = z
    .object({
        id: z.number().int(),
        projectId: z.number().int(),
        name: z.string(),
        age: z.number().int().nullable(),
        gender: z.string().nullable(),
        literacy: z.string(),
        goal: z.string().nullable(),
    })
    .passthrough();

export const PersonaListResponseSchema = paginated(PersonaSchema);

const ScenarioSchema = z
    .object({
        id: z.number().int(),
        projectId: z.number().int(),
        title: z.string(),
        description: z.string().nullable(),
        startUrl: z.string().nullable(),
        goalDescription: z.string().nullable(),
        goalUrl: z.string().nullable(),
        viewportType: z.string(),
    })
    .passthrough();

export const ScenarioListResponseSchema = paginated(ScenarioSchema);

const EvaluationIssueSchema = z
    .object({
        severity: z.string(),
        category: z.string(),
        title: z.string(),
        description: z.string(),
        suggestion: z.string(),
    })
    .passthrough();

export const EvaluationResultSchema = z
    .object({
        id: z.number().int(),
        // Server today serialises scores as strings (Eloquent decimal cast
        // returns string) but the DTO contract may switch to native floats
        // without a CLI bump — accept both so the CLI does not break the
        // first time the server type changes.
        overallScore: z.union([z.string(), z.number()]),
        // F-0-02: AIO 等で scores が空のとき server が空配列 [] を返す既存データが
        // ある。空配列のみ {} に正規化して受容する (暫定互換)。canonical 保証は
        // server 側 (EvaluationResultResource) が SoT。非空配列は producer 契約崩れ
        // を隠さないため従来どおり object 検証で reject する。
        scores: z.preprocess(
            (value) => (Array.isArray(value) && value.length === 0 ? {} : value),
            z.record(z.string(), z.union([z.number(), z.string()])),
        ),
        summary: z.string(),
        issues: z.array(EvaluationIssueSchema),
    })
    .passthrough();

const EvaluationSchema = z
    .object({
        id: z.number().int(),
        pageId: z.number().int().nullable(),
        type: z.string(),
        personaId: z.number().int().nullable(),
        status: z.string(),
        createdAt: z.string(),
        result: EvaluationResultSchema.nullable(),
    })
    .passthrough();

export const EvaluationListResponseSchema = paginated(EvaluationSchema);
export const EvaluationResponseSchema = envelope(EvaluationSchema);

/**
 * `POST /api/v1/pages/{page}/audits` response (T108, CLI T116).
 *
 * Intentionally narrower than {@link EvaluationSchema}: at submission time
 * the server only knows the queued row (`status=pending`, no `result` yet).
 * Full result fetches go through {@link EvaluationResponseSchema}.
 */
const EvaluationExecutionSchema = z
    .object({
        id: z.number().int(),
        type: z.string(),
        status: z.string(),
        pageId: z.number().int().nullable(),
        submittedAt: z.string().nullable(),
    })
    .passthrough();

export const EvaluationExecutionResponseSchema = envelope(
    EvaluationExecutionSchema,
);

/**
 * `GET /api/v1/evaluations/{id}/status` response — the polling endpoint for
 * `uxi run --wait`. Small by design (T108) so the CLI can hit it up to
 * several times per minute without hurting the rate-limit bucket.
 */
const EvaluationStatusSchema = z
    .object({
        id: z.number().int(),
        status: z.string(),
        progress: z.number().int(),
        hasResult: z.boolean(),
        errorCode: z.string().nullable(),
        errorMessage: z.string().nullable(),
    })
    .passthrough();

export const EvaluationStatusResponseSchema = envelope(EvaluationStatusSchema);

/**
 * Write-side envelopes. All REST v1 write endpoints return `{data: <entity>}`
 * so the CLI gets a stable shape to parse regardless of verb.
 */
export const ProjectResponseSchema = envelope(ProjectSchema);
export const SiteResponseSchema = envelope(SiteSchema);
export const PageResponseSchema = envelope(PageSchema);
export const PersonaResponseSchema = envelope(PersonaSchema);
export const ScenarioResponseSchema = envelope(ScenarioSchema);

/**
 * Canonical `error.code` strings emitted by the v1 REST API (C1 / T144).
 *
 * Mirrors `app/Enums/ApiErrorCode.php` plus the controller-local codes
 * that rely on the same envelope shape. Keep this list in sync by hand —
 * the schema contract test (`tests/api/schemas-contract.test.ts`) catches
 * drift by round-tripping real API responses through these schemas.
 *
 * Unknown `error.code` values are not rejected: consumers should fall
 * back to HTTP status when the CLI is older than the server.
 */
export const API_ERROR_CODES = [
    "unauthenticated",
    "forbidden",
    "not_found",
    "validation_failed",
    "rate_limit_exceeded",
    "quota_exceeded",
    "idempotency_conflict",
    "internal_server_error",
    // Controller-local codes (AuditSubmissionController / SitePagesBulkController
    // / EvaluationExecutionController) layered on top of the canonical enum.
    "payload_sanitization_failed",
    "site_not_cli_capture",
    "use_audits_submit",
] as const;

export type ApiErrorCode = (typeof API_ERROR_CODES)[number];

const ApiErrorBodySchema = z
    .object({
        error: z
            .object({
                // Left as a plain string to tolerate server-side additions
                // the CLI has not learned about yet; the `ApiErrorCode`
                // union above is the authoritative CLI-side list.
                code: z.string(),
                message: z.string(),
                status: z.number().int(),
                details: z.record(z.string(), z.unknown()).optional(),
            })
            .passthrough(),
    })
    .passthrough();

export type Project = z.infer<typeof ProjectSchema>;
export type Site = z.infer<typeof SiteSchema>;
export type Page = z.infer<typeof PageSchema>;
export type Persona = z.infer<typeof PersonaSchema>;
export type Scenario = z.infer<typeof ScenarioSchema>;
export type Evaluation = z.infer<typeof EvaluationSchema>;
export type EvaluationExecution = z.infer<typeof EvaluationExecutionSchema>;
export type EvaluationStatus = z.infer<typeof EvaluationStatusSchema>;
export type EvaluationIssue = z.infer<typeof EvaluationIssueSchema>;
export type MeData = z.infer<typeof MeDataSchema>;
export type ApiErrorBody = z.infer<typeof ApiErrorBodySchema>;

export { ApiErrorBodySchema };
