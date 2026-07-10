import { readFileSync } from "node:fs";
import { Agent, fetch, ProxyAgent, type Dispatcher } from "undici";
import type { ResolvedConnectionOptions } from "../profile/context.js";

export type HttpResult<T> =
    | { ok: true; status: number; data: T }
    | { ok: false; status: number; reason: string };

export function buildDispatcher(opts: ResolvedConnectionOptions): Dispatcher {
    const connect: Record<string, unknown> = {};
    if (opts.ca_bundle) {
        connect["ca"] = readFileSync(opts.ca_bundle, "utf-8");
    }
    if (opts.allow_insecure) {
        connect["rejectUnauthorized"] = false;
    }
    const proxy = opts.https_proxy ?? opts.http_proxy ?? null;
    if (proxy) {
        return new ProxyAgent({
            uri: proxy,
            requestTls: connect,
        });
    }
    return new Agent({ connect });
}

async function withDispatcher<T>(
    opts: ResolvedConnectionOptions,
    fn: (d: Dispatcher) => Promise<T>,
): Promise<T> {
    const d = buildDispatcher(opts);
    try {
        return await fn(d);
    } finally {
        try {
            await d.close();
        } catch {
            /* already closed */
        }
    }
}

export async function httpGetJson<T>(
    url: string,
    opts: ResolvedConnectionOptions,
    headers: Record<string, string> = {},
): Promise<HttpResult<T>> {
    let lastErr: Error | null = null;
    for (let attempt = 0; attempt <= opts.retry_max; attempt++) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), opts.timeout_ms);
        try {
            return await withDispatcher(opts, async (dispatcher) => {
                const res = await fetch(url, {
                    dispatcher,
                    headers,
                    signal: ctrl.signal,
                });
                if (!res.ok) {
                    return {
                        ok: false as const,
                        status: res.status,
                        reason: `HTTP ${String(res.status)}`,
                    };
                }
                const data = (await res.json()) as T;
                return {
                    ok: true as const,
                    status: res.status,
                    data,
                };
            });
        } catch (e) {
            lastErr = e as Error;
            if (attempt < opts.retry_max) {
                await new Promise((r) =>
                    setTimeout(r, opts.retry_backoff_ms * 2 ** attempt),
                );
            }
        } finally {
            clearTimeout(timer);
        }
    }
    return {
        ok: false,
        status: 0,
        reason: lastErr?.message ?? "unknown error",
    };
}
