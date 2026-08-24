import { readFileSync } from "node:fs";
import {
    Agent,
    fetch,
    ProxyAgent,
    type Dispatcher,
} from "undici";
import type { ZodTypeAny, z } from "zod";
import type { ResolvedProfileContext } from "../profile/context.js";
import { isAbortLikeError } from "../util/abort.js";
import { ApiErrorBodySchema, type ApiErrorCode } from "./schemas.js";

/**
 * Result of an authenticated CLI API call.
 *
 * The `error` cases are split into a small finite set so command code can
 * map them to exit codes without re-parsing HTTP details:
 *
 *   - `auth`         → 401/403 (or `unauthenticated` / `forbidden` /
 *                       `insufficient_ability` / `actor_not_resolvable` codes)
 *   - `not-found`    → 404 / `not_found`
 *   - `rate-limit`   → 429 / `rate_limited` with optional Retry-After
 *   - `quota`        → 402 / `quota_exceeded` (Stripe / plan limit exhausted)
 *   - `conflict`     → 409 / `idempotency_conflict`, `idempotency_in_progress`,
 *                       `idempotency_indeterminate`
 *   - `validation`   → 422 / `validation_failed` (+ controller-local codes
 *                       `payload_sanitization_failed`, `site_not_cli_capture`,
 *                       `use_audits_submit`)
 *   - `server`       → 5xx / `internal_server_error`
 *   - `network`      → request never reached the server (DNS, timeout, …)
 *   - `schema`       → response decoded but did not match the Zod contract
 *
 * C1 / T144: dispatch is keyed on `error.code` when present (the public v1
 * contract — see `ApiErrorCode` in `app/Enums/ApiErrorCode.php`) and falls
 * back to HTTP status when the server response did not carry our envelope
 * (older staging builds, misbehaving proxies, plain-text 500s).
 *
 * Every failure also carries the raw `code` string when it was present so
 * callers that care about the controller-local discriminators (e.g. the
 * `audits:submit` vs. `capture:submit` routing) can branch precisely.
 */
export type ApiCallSuccess<T> = { ok: true; status: number; data: T };
export type ApiCallFailure =
    | {
          ok: false;
          kind: "auth";
          status: number;
          message: string;
          code: string | null;
      }
    | {
          ok: false;
          kind: "not-found";
          status: number;
          message: string;
          code: string | null;
      }
    | {
          ok: false;
          kind: "rate-limit";
          status: number;
          message: string;
          code: string | null;
          retryAfterSeconds: number | null;
      }
    | {
          ok: false;
          kind: "quota";
          status: number;
          message: string;
          code: string | null;
      }
    | {
          ok: false;
          kind: "conflict";
          status: number;
          message: string;
          code: string | null;
      }
    | {
          ok: false;
          kind: "validation";
          status: number;
          message: string;
          code: string | null;
          // T116 write commands surface `details` so users can see WHICH
          // field the server rejected (`name: ["required"]`). We keep the
          // shape loose (`unknown`) because different FormRequests in the
          // API compose the array differently.
          details: Record<string, unknown> | null;
      }
    | {
          ok: false;
          kind: "server";
          status: number;
          message: string;
          code: string | null;
      }
    | {
          ok: false;
          kind: "network";
          status: 0;
          message: string;
          code: null;
          // True when the underlying fetch was cancelled by an
          // AbortController (per-request timeout or an upstream `--wait`
          // deadline). Callers that own the phase context (e.g. `run`)
          // humanise this as a timeout; generic callers keep the original
          // network wording.
          aborted: boolean;
      }
    | {
          ok: false;
          kind: "schema";
          status: number;
          message: string;
          code: null;
      };
export type ApiCallResult<T> = ApiCallSuccess<T> | ApiCallFailure;

function buildDispatcher(ctx: ResolvedProfileContext): Dispatcher {
    const opts = ctx.options;
    const connect: Record<string, unknown> = {};
    if (opts.ca_bundle) {
        connect["ca"] = readFileSync(opts.ca_bundle, "utf-8");
    }
    if (opts.allow_insecure) {
        connect["rejectUnauthorized"] = false;
    }
    const proxy = opts.https_proxy ?? opts.http_proxy ?? null;
    if (proxy) {
        return new ProxyAgent({ uri: proxy, requestTls: connect });
    }
    return new Agent({ connect });
}

function trimSlash(url: string): string {
    return url.replace(/\/+$/, "");
}

/**
 * Resolve a CLI-relative path (e.g. `/projects`) against the profile's
 * `apiUrl`. Both leading slashes are tolerated so call sites stay clean.
 *
 * `apiUrl` is documented to already include the `/api/v1` segment (or its
 * deployment-specific equivalent), so we deliberately do NOT prepend a
 * version segment here — the CLI must work against staging clusters that
 * pin a different prefix.
 */
export function buildUrl(
    ctx: ResolvedProfileContext,
    path: string,
    query?: Record<string, string | number | boolean | null | undefined>,
): string {
    const base = trimSlash(ctx.apiUrl);
    const rel = path.startsWith("/") ? path : `/${path}`;
    const url = new URL(base + rel);
    if (query) {
        for (const [k, v] of Object.entries(query)) {
            if (v === null || v === undefined) continue;
            url.searchParams.set(k, String(v));
        }
    }
    return url.toString();
}

/**
 * Resolve the `Authorization` header for a request.
 *
 * Precedence (T715 Phase 3): a configured API key always wins, so existing
 * `--api-key` / `ENV.API_KEY` (branding.ts) / stored-key users keep their exact
 * behaviour. Only when no API key is present does the OAuth user-token
 * provider run (reading the stored token and refreshing under a file lock
 * if it is near expiry). When neither is available, the request goes out
 * unauthenticated and the server returns 401.
 */
async function authHeader(
    ctx: ResolvedProfileContext,
): Promise<Record<string, string>> {
    if (ctx.apiKey) {
        return { Authorization: `Bearer ${ctx.apiKey}` };
    }
    if (ctx.oauthTokenProvider) {
        const token = await ctx.oauthTokenProvider();
        return { Authorization: `Bearer ${token}` };
    }
    return {};
}

function parseRetryAfter(headerValue: string | null): number | null {
    if (headerValue === null) return null;
    const trimmed = headerValue.trim();
    if (trimmed === "") return null;
    const seconds = Number.parseInt(trimmed, 10);
    if (!Number.isNaN(seconds) && seconds >= 0) return seconds;
    // HTTP-date form: parse to delta seconds (rare from Laravel but spec-allowed).
    const t = Date.parse(trimmed);
    if (!Number.isNaN(t)) {
        return Math.max(0, Math.round((t - Date.now()) / 1000));
    }
    return null;
}

/**
 * Map an `error.code` to the CLI-side failure discriminator.
 * Returns `null` for unknown codes so the caller falls back to the
 * status-based mapping below. Keeping these arms narrow (one case per
 * known code) is deliberate: silent aliasing in this table was the
 * pre-T144 bug that motivated C1 in the first place.
 *
 * T261: the server emits `rate_limited` (not `rate_limit_exceeded`); the old
 * spelling was drift carried over from another app and is **not** kept as an
 * alias. A server still emitting the old spelling lands on the same
 * `rate-limit` kind through the HTTP-status fallback (429), so the observable
 * failure kind does not change — that is fixed by the tests.
 * The four server-only codes (`insufficient_ability`, `actor_not_resolvable`,
 * `idempotency_in_progress`, `idempotency_indeterminate`) get explicit arms
 * rather than relying on the status fallback; the classification matches what
 * `ApiErrorCode::defaultStatus()` would produce (403 → auth, 409 → conflict).
 */
export function dispatchKindFromCode(
    code: string | null,
): Exclude<ApiCallFailure["kind"], "network" | "schema"> | null {
    if (code === null) return null;
    const narrowed = code as ApiErrorCode | string;
    switch (narrowed) {
        case "unauthenticated":
        case "forbidden":
        case "insufficient_ability":
        case "actor_not_resolvable":
            return "auth";
        case "not_found":
            return "not-found";
        case "rate_limited":
            return "rate-limit";
        case "quota_exceeded":
            return "quota";
        case "idempotency_conflict":
        case "idempotency_in_progress":
        case "idempotency_indeterminate":
            return "conflict";
        case "validation_failed":
        case "payload_sanitization_failed":
        case "site_not_cli_capture":
        case "use_audits_submit":
            return "validation";
        case "internal_server_error":
            return "server";
        default:
            return null;
    }
}

/**
 * Fallback dispatch for responses whose envelope did not carry a
 * recognisable `error.code`. Retains the pre-T144 HTTP-status mapping
 * so older staging builds (and misbehaving reverse proxies) keep their
 * historic CLI UX.
 */
export function dispatchKindFromStatus(
    status: number,
): Exclude<ApiCallFailure["kind"], "network" | "schema"> {
    if (status === 401 || status === 403) return "auth";
    if (status === 402) return "quota";
    if (status === 404) return "not-found";
    if (status === 409) return "conflict";
    if (status === 422) return "validation";
    if (status === 429) return "rate-limit";
    return "server";
}

/**
 * Combination point of the two dispatch tables: the canonical `error.code`
 * wins, and an unknown / absent code falls back to the HTTP status. Exported
 * so the fallback contract can be pinned as a pure function (no HTTP round
 * trip needed to tell "the code decided" from "the status decided").
 */
export function resolveFailureKind(
    code: string | null,
    status: number,
): Exclude<ApiCallFailure["kind"], "network" | "schema"> {
    return dispatchKindFromCode(code) ?? dispatchKindFromStatus(status);
}

/**
 * Canonical envelope extracted from an error response (C1 / T144).
 *
 * Returns the raw `error.code` when the server conformed to the public
 * contract, plus the resolved message and (for validation failures)
 * `details`. The code is kept as a plain string because the CLI must
 * tolerate server-side additions it has not learned about.
 */
function extractErrorEnvelope(body: unknown): {
    code: string | null;
    message: string | null;
    details: Record<string, unknown> | null;
} {
    const parsed = ApiErrorBodySchema.safeParse(body);
    if (!parsed.success) {
        return { code: null, message: null, details: null };
    }
    return {
        code: parsed.data.error.code,
        message: parsed.data.error.message,
        details: parsed.data.error.details ?? null,
    };
}

async function readBodyAsJson(res: {
    json: () => Promise<unknown>;
}): Promise<unknown> {
    try {
        return await res.json();
    } catch {
        return null;
    }
}

/**
 * HTTP verbs supported by the CLI's API client. We deliberately keep the
 * allow-list small — REST v1 only uses GET/POST/PUT/PATCH/DELETE — so new
 * exotic verbs have to come back here and reason about auth/idempotency
 * explicitly.
 */
export type HttpMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

type RequestExtras = {
    query?: Record<string, string | number | boolean | null | undefined>;
    body?: unknown;
    extraHeaders?: Record<string, string>;
    // For 204 No Content responses we skip body parsing. Forcing `safeParse`
    // through an empty payload would surface a bogus schema violation on a
    // perfectly compliant server.
    expectNoContent?: boolean;
    // T116: `uxi run --wait` threads its own AbortController so an upstream
    // timeout can cancel the in-flight request cleanly. When `signal` is
    // passed, the request still honours the per-request timeout (override or
    // `ctx.options.timeout_ms`) as a secondary guard.
    signal?: AbortSignal;
    // T815 (F-0-2): per-request abort timeout in ms. When set, overrides
    // `ctx.options.timeout_ms` for this single request. `uxi run --wait` passes
    // its remaining wall-clock budget here so a slow status poll is bounded by
    // `--timeout` (and surfaces exit 3) rather than the static 30s default
    // (which previously aborted long polls at ~30s → misleading exit 1).
    timeoutMsOverride?: number;
};

/**
 * Shared request core for every verb. Keeps dispatcher lifecycle, auth
 * header, error envelope decoding, and AbortController wiring in one place
 * so the GET/POST/PUT/PATCH/DELETE wrappers stay declarative.
 *
 * `extraHeaders` is how callers inject `Idempotency-Key` (for
 * `POST /pages/{id}/audits`, T116/U-18) without having to know the auth
 * plumbing. Content-Type is set to `application/json` only when a body is
 * actually sent — bare DELETEs leave it off so servers (and proxies) don't
 * over-read an empty stream.
 *
 * The dispatcher is closed in `finally` to avoid leaking sockets — same
 * lifecycle as `http/client.ts` (T112).
 */
async function apiRequest<S extends ZodTypeAny>(
    ctx: ResolvedProfileContext,
    method: HttpMethod,
    path: string,
    schema: S,
    extras: RequestExtras = {},
): Promise<ApiCallResult<z.infer<S>>> {
    const url = buildUrl(ctx, path, extras.query);
    const dispatcher = buildDispatcher(ctx);
    const ctrl = new AbortController();
    // T815 (F-0-2): bound this request by the caller-supplied override (e.g. the
    // `--wait` remaining budget) when present, else the static profile timeout.
    const perRequestTimeoutMs = extras.timeoutMsOverride ?? ctx.options.timeout_ms;
    const timer = setTimeout(() => ctrl.abort(), perRequestTimeoutMs);
    // Propagate an external signal (e.g. the --wait timeout controller) so
    // that an upstream cancellation short-circuits the fetch without waiting
    // for `timeout_ms`.
    const linkedSignal = extras.signal;
    const onUpstreamAbort = (): void => ctrl.abort();
    if (linkedSignal) {
        if (linkedSignal.aborted) ctrl.abort();
        else linkedSignal.addEventListener("abort", onUpstreamAbort);
    }
    try {
        const headers: Record<string, string> = {
            Accept: "application/json",
            ...(await authHeader(ctx)),
            ...(extras.extraHeaders ?? {}),
        };
        const fetchInit: {
            dispatcher: Dispatcher;
            method: HttpMethod;
            headers: Record<string, string>;
            signal: AbortSignal;
            body?: string;
        } = {
            dispatcher,
            method,
            headers,
            signal: ctrl.signal,
        };
        if (extras.body !== undefined) {
            headers["Content-Type"] = "application/json";
            fetchInit.body = JSON.stringify(extras.body);
        }
        const res = await fetch(url, fetchInit);
        const status = res.status;
        // 204 by spec has no body; do not attempt to parse.
        const body =
            status === 204 || extras.expectNoContent === true
                ? null
                : await readBodyAsJson(res);
        if (res.ok) {
            if (extras.expectNoContent === true || status === 204) {
                // Caller told us to ignore the body. Return null with the
                // correct type — the zod schema is a formality here so the
                // generic stays consistent with the ok-result shape.
                return {
                    ok: true,
                    status,
                    data: null as z.infer<S>,
                };
            }
            const parsed = schema.safeParse(body);
            if (!parsed.success) {
                return {
                    ok: false,
                    kind: "schema",
                    status,
                    code: null,
                    message: `Response schema violation: ${parsed.error.message}`,
                };
            }
            return {
                ok: true,
                status,
                data: parsed.data as z.infer<S>,
            };
        }
        // C1 / T144: prefer the server-emitted `error.code` over HTTP
        // status. Status is still surfaced on the result so older tests
        // / log readers keep working, but the dispatch keyword comes
        // from the canonical v1 contract. When the envelope is missing
        // (legacy proxy, 500 from nginx, …) we fall through to a pure
        // status-based mapping.
        const envelope = extractErrorEnvelope(body);
        const code = envelope.code;
        const resolvedMessage = (fallback: string): string =>
            envelope.message ?? fallback;

        const kind: ApiCallFailure["kind"] = resolveFailureKind(code, status);

        switch (kind) {
            case "auth":
                return {
                    ok: false,
                    kind: "auth",
                    status,
                    code,
                    message: resolvedMessage(
                        "Invalid API key or insufficient permissions.",
                    ),
                };
            case "not-found":
                return {
                    ok: false,
                    kind: "not-found",
                    status,
                    code,
                    message: resolvedMessage("Resource not found."),
                };
            case "rate-limit":
                return {
                    ok: false,
                    kind: "rate-limit",
                    status,
                    code,
                    message: resolvedMessage("Rate limit exceeded."),
                    retryAfterSeconds: parseRetryAfter(
                        res.headers.get("retry-after"),
                    ),
                };
            case "quota":
                return {
                    ok: false,
                    kind: "quota",
                    status,
                    code,
                    message: resolvedMessage(
                        "Quota exceeded. Upgrade or wait for the plan reset.",
                    ),
                };
            case "conflict":
                return {
                    ok: false,
                    kind: "conflict",
                    status,
                    code,
                    message: resolvedMessage(
                        "Idempotency key conflict.",
                    ),
                };
            case "validation":
                return {
                    ok: false,
                    kind: "validation",
                    status,
                    code,
                    message: resolvedMessage("The given data was invalid."),
                    details: envelope.details,
                };
            case "server":
                return {
                    ok: false,
                    kind: "server",
                    status,
                    code,
                    message: resolvedMessage(
                        status >= 500
                            ? `Server error (HTTP ${String(status)}).`
                            : `Unexpected response (HTTP ${String(status)}).`,
                    ),
                };
            default:
                // `schema` / `network` are never emitted from this branch;
                // explicit fallthrough for exhaustiveness.
                return {
                    ok: false,
                    kind: "server",
                    status,
                    code,
                    message: resolvedMessage(
                        `Unexpected response (HTTP ${String(status)}).`,
                    ),
                };
        }
    } catch (e) {
        // `fetch` throws plain Errors and `AbortError` (DOMException) but
        // libraries may surface non-Error rejections too. Narrow before
        // reading `.message` so a string/object reject doesn't render as
        // `undefined` to the user.
        const aborted = isAbortLikeError(e);
        const message =
            e instanceof Error && e.message !== ""
                ? e.message
                : typeof e === "string" && e !== ""
                  ? e
                  : "Network error";
        return {
            ok: false,
            kind: "network",
            status: 0,
            code: null,
            message,
            aborted,
        };
    } finally {
        clearTimeout(timer);
        if (linkedSignal) {
            linkedSignal.removeEventListener("abort", onUpstreamAbort);
        }
        try {
            await dispatcher.close();
        } catch {
            /* already closed */
        }
    }
}

/**
 * GET an API endpoint, parse the response with the given Zod schema, and
 * return a tagged result so command code can branch on the failure shape.
 */
export async function apiGet<S extends ZodTypeAny>(
    ctx: ResolvedProfileContext,
    path: string,
    schema: S,
    query?: Record<string, string | number | boolean | null | undefined>,
    extraHeaders?: Record<string, string>,
    signal?: AbortSignal,
    timeoutMsOverride?: number,
): Promise<ApiCallResult<z.infer<S>>> {
    const extras: RequestExtras = {};
    if (query !== undefined) extras.query = query;
    if (extraHeaders !== undefined) extras.extraHeaders = extraHeaders;
    if (signal !== undefined) extras.signal = signal;
    if (timeoutMsOverride !== undefined) extras.timeoutMsOverride = timeoutMsOverride;
    return apiRequest(ctx, "GET", path, schema, extras);
}

/**
 * POST a JSON body to the API. Used by the T116 write commands (project /
 * site / persona / scenario creation and the `run` evaluation kick-off).
 * `extraHeaders` is the escape hatch for Idempotency-Key.
 */
export async function apiPost<S extends ZodTypeAny>(
    ctx: ResolvedProfileContext,
    path: string,
    schema: S,
    body: unknown,
    extraHeaders?: Record<string, string>,
): Promise<ApiCallResult<z.infer<S>>> {
    const extras: RequestExtras = { body };
    if (extraHeaders !== undefined) extras.extraHeaders = extraHeaders;
    return apiRequest(ctx, "POST", path, schema, extras);
}

/**
 * PUT a JSON body — used by persona:update / scenario:update. The server
 * treats PUT as full replacement (the Laravel FormRequest lists `name` and
 * `literacy` / `title` as required), so the CLI must merge existing fields
 * before calling. Partial-merge logic lives in the command, not here.
 */
export async function apiPut<S extends ZodTypeAny>(
    ctx: ResolvedProfileContext,
    path: string,
    schema: S,
    body: unknown,
): Promise<ApiCallResult<z.infer<S>>> {
    return apiRequest(ctx, "PUT", path, schema, { body });
}

/**
 * PATCH a JSON body — currently only used by `page:target` for the
 * `is_evaluation_target` toggle. Body is always small and server-validated.
 */
export async function apiPatch<S extends ZodTypeAny>(
    ctx: ResolvedProfileContext,
    path: string,
    schema: S,
    body: unknown,
): Promise<ApiCallResult<z.infer<S>>> {
    return apiRequest(ctx, "PATCH", path, schema, { body });
}

/**
 * DELETE an API resource. Server returns 204 No Content on success; the
 * caller receives `{ok: true, data: null}`.
 */
export async function apiDelete<S extends ZodTypeAny>(
    ctx: ResolvedProfileContext,
    path: string,
    schema: S,
): Promise<ApiCallResult<z.infer<S>>> {
    return apiRequest(ctx, "DELETE", path, schema, { expectNoContent: true });
}

/**
 * DELETE an API resource that responds with a JSON body (200, not 204).
 * Used by the CLI `logout` command against `DELETE /api/v1/me/session`, which returns
 * the revoked-session descriptor. Unlike {@link apiDelete} the body is
 * parsed against `schema`.
 */
export async function apiDeleteJson<S extends ZodTypeAny>(
    ctx: ResolvedProfileContext,
    path: string,
    schema: S,
): Promise<ApiCallResult<z.infer<S>>> {
    return apiRequest(ctx, "DELETE", path, schema, {});
}

/**
 * Format an `ApiCallFailure` for stderr. Centralised so every command
 * surfaces identical wording — important for users who script against the
 * messages.
 */
export function formatFailure(failure: ApiCallFailure): string {
    switch (failure.kind) {
        case "auth":
            return `Error: ${failure.message} (HTTP ${String(failure.status)})`;
        case "not-found":
            return `Error: ${failure.message}`;
        case "rate-limit":
            if (failure.retryAfterSeconds !== null) {
                return (
                    `Error: ${failure.message} `
                    + `Retry after ${String(failure.retryAfterSeconds)} seconds.`
                );
            }
            return `Error: ${failure.message}`;
        case "quota":
            return `Error: ${failure.message}`;
        case "conflict":
            return `Error: ${failure.message}`;
        case "validation": {
            // Flatten `details: {name: ["required"]}` into
            // `  name: required` lines so the user can see exactly which
            // field failed. Laravel's `errors` and custom FormRequest
            // details both land here; we defensively handle both arrays
            // and scalar strings.
            const lines: string[] = [`Error: ${failure.message}`];
            if (failure.details) {
                for (const [field, messages] of Object.entries(
                    failure.details,
                )) {
                    const joined = Array.isArray(messages)
                        ? messages.filter((m) => typeof m === "string").join(", ")
                        : typeof messages === "string"
                          ? messages
                          : JSON.stringify(messages);
                    if (joined !== "") lines.push(`  ${field}: ${joined}`);
                }
            }
            return lines.join("\n");
        }
        case "server":
            return `Error: ${failure.message}`;
        case "network":
            return `Error: ${failure.message}`;
        case "schema":
            return `Error: ${failure.message}`;
    }
}
