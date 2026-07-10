import { csrfToken } from "@/lib/csrf";

/**
 * 撮影 PWA の書き込み系 XHR 共通ラッパ (doc/10 §10.8-3)。
 * X-XSRF-TOKEN を常時付与し、419 (CSRF 失効) は cookie 再取得 (軽量 GET /app/csrf-cookie)
 * → 1 回だけ再送する。再取得に失敗 (非 2xx / network error) した場合はリトライせず
 * 元の 419 を返す (呼び出し側 = upload-queue がキュー保留 + UI 通知)。
 */
export async function captureFetch(
    url: string,
    init: RequestInit = {},
    retried = false,
): Promise<Response> {
    const response = await fetch(url, {
        ...init,
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-XSRF-TOKEN": csrfToken(),
            ...(init.headers ?? {}),
        },
    });
    if (response.status === 419 && !retried) {
        try {
            const refresh = await fetch("/app/csrf-cookie", { credentials: "same-origin" });
            if (!refresh.ok) return response;
        } catch {
            return response;
        }
        return captureFetch(url, init, true);
    }
    return response;
}

/** エラー応答 (422/409 等) からユーザー向けメッセージを取り出す */
export async function extractErrorMessage(response: Response): Promise<string> {
    try {
        const body: unknown = await response.clone().json();
        if (body !== null && typeof body === "object") {
            const record = body as Record<string, unknown>;
            if (typeof record.message === "string" && record.message !== "") {
                return record.message;
            }
            if (record.errors !== null && typeof record.errors === "object") {
                const first = Object.values(record.errors as Record<string, unknown>)[0];
                if (Array.isArray(first) && typeof first[0] === "string") {
                    return first[0];
                }
            }
        }
    } catch {
        // JSON でない応答は既定文言へフォールバック
    }
    return "操作に失敗しました。時間をおいて再試行してください。";
}

/** JSON body 付きの POST/PATCH/DELETE ヘルパ */
export async function captureJson(
    url: string,
    method: string,
    body?: unknown,
): Promise<Response> {
    return captureFetch(url, {
        method,
        headers: { "Content-Type": "application/json" },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}
