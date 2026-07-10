/*
 * 撮影 PWA の Service Worker (概念設計 D9)。ビルド依存を増やさない素の JS。
 *
 * キャッシュ方針 (fetch 分岐は shouldCacheRequest に集約。Vitest が固定する):
 * - キャッシュ対象は「GET かつ同一オリジンかつ fingerprinted build asset (/build/*)」のみ
 *   の stale-while-revalidate。
 * - /app/* の navigation・JSON/XHR・署名 URL・S3 オリジンへのリクエストは一切キャッシュしない
 *   (早期 return = ネットワーク素通し。Inertia HTML やユーザー別応答の誤キャッシュを構造的に排除)。
 * - アップロード同期の実行主体はページ側 JS (セッション cookie / XSRF が自然に効く)。
 *   SW は "resume-uploads" message を全クライアントへ broadcast するだけ。
 */

const CAPTURE_CACHE_VERSION = "capture-v1";

/**
 * @param {{ url: string, method: string }} request
 * @param {string} appOrigin
 * @returns {boolean}
 */
function shouldCacheRequest(request, appOrigin) {
    if (request.method !== "GET") return false;
    let url;
    try {
        url = new URL(request.url);
    } catch {
        return false;
    }
    if (url.origin !== appOrigin) return false; // S3 / 署名 URL 等の別オリジンは素通し
    return url.pathname.startsWith("/build/"); // fingerprinted asset のみ
}

/* eslint-disable no-undef */
if (typeof self !== "undefined" && typeof self.addEventListener === "function" && typeof window === "undefined") {
    self.addEventListener("install", () => {
        self.skipWaiting();
    });

    self.addEventListener("activate", (event) => {
        event.waitUntil(
            (async () => {
                // 旧バージョンのキャッシュを削除
                const keys = await caches.keys();
                await Promise.all(
                    keys
                        .filter((key) => key.startsWith("capture-") && key !== CAPTURE_CACHE_VERSION)
                        .map((key) => caches.delete(key)),
                );
                await self.clients.claim();
            })(),
        );
    });

    self.addEventListener("fetch", (event) => {
        if (!shouldCacheRequest(event.request, self.location.origin)) {
            return; // ネットワーク素通し (respondWith しない)
        }
        event.respondWith(
            (async () => {
                // stale-while-revalidate
                const cache = await caches.open(CAPTURE_CACHE_VERSION);
                const cached = await cache.match(event.request);
                const network = fetch(event.request)
                    .then((response) => {
                        if (response.ok) {
                            cache.put(event.request, response.clone());
                        }
                        return response;
                    })
                    .catch(() => cached);
                return cached ?? network;
            })(),
        );
    });

    // フォアグラウンド復帰時同期のトリガー (ページ側 JS が resume() を実行する)
    self.addEventListener("message", (event) => {
        if (event.data === "resume-uploads") {
            self.clients.matchAll({ type: "window" }).then((clients) => {
                for (const client of clients) {
                    client.postMessage("resume-uploads");
                }
            });
        }
    });
}
