import type { ZodTypeAny, z } from "zod";
import type { ResolvedConnectionOptions } from "../profile/context.js";
import { httpGetJson } from "./client.js";

export type FetchJsonResult<T> =
    | { ok: true; data: T }
    | { ok: false; status: number; reason: string };

export async function fetchJsonValidated<S extends ZodTypeAny>(
    url: string,
    opts: ResolvedConnectionOptions,
    headers: Record<string, string>,
    schema: S,
): Promise<FetchJsonResult<z.infer<S>>> {
    const r = await httpGetJson<unknown>(url, opts, headers);
    if (!r.ok) return r;
    const parsed = schema.safeParse(r.data);
    if (!parsed.success) {
        return {
            ok: false,
            status: -1,
            reason: `schema violation: ${parsed.error.message}`,
        };
    }
    return { ok: true, data: parsed.data as z.infer<S> };
}
