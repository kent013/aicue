import { describe, expect, it, vi } from "vitest";
import fs from "node:fs";
import path from "node:path";

/*
 * capture-sw.js の fetch 分岐 (概念設計 D9):
 * - キャッシュ対象は「GET かつ同一オリジンかつ /build/*」のみ
 * - /organizations/test-org/app/* navigation・JSON・S3/署名 URL は素通し (respondWith しない)
 * - activate で旧バージョン (capture-*) キャッシュを削除
 */

const swSource = fs.readFileSync(
    path.resolve(__dirname, "../../../../public/capture-sw.js"),
    "utf-8",
);

const APP_ORIGIN = "https://app.example.test";

interface FakeSelf {
    listeners: Map<string, (event: unknown) => void>;
    addEventListener: (name: string, handler: (event: unknown) => void) => void;
    skipWaiting: () => void;
    clients: { claim: () => Promise<void>; matchAll: () => Promise<unknown[]> };
    location: { origin: string };
}

function loadSw(fakeCaches: unknown): FakeSelf {
    const listeners = new Map<string, (event: unknown) => void>();
    const fakeSelf: FakeSelf = {
        listeners,
        addEventListener: (name, handler) => listeners.set(name, handler),
        skipWaiting: vi.fn(),
        clients: { claim: vi.fn(async () => {}), matchAll: vi.fn(async () => []) },
        location: { origin: APP_ORIGIN },
    };
    // window を undefined で shadow し SW 実行環境として評価する
    const factory = new Function(
        "self",
        "window",
        "caches",
        `${swSource}\nreturn { shouldCacheRequest, CAPTURE_CACHE_VERSION };`,
    );
    const exports = factory(fakeSelf, undefined, fakeCaches) as {
        shouldCacheRequest: (request: { url: string; method: string }, origin: string) => boolean;
        CAPTURE_CACHE_VERSION: string;
    };
    // 判定関数もテストから参照できるよう添付する
    Object.assign(fakeSelf, exports);
    return fakeSelf;
}

function extractPolicy(): (request: { url: string; method: string }, origin: string) => boolean {
    const factory = new Function(
        "self",
        "window",
        "caches",
        `${swSource}\nreturn shouldCacheRequest;`,
    );
    return factory(undefined, undefined, undefined);
}

describe("shouldCacheRequest (キャッシュ方針)", () => {
    const shouldCache = extractPolicy();

    it("GET の同一オリジン /build/* のみキャッシュ対象", () => {
        expect(shouldCache({ url: `${APP_ORIGIN}/build/assets/app-abc123.js`, method: "GET" }, APP_ORIGIN)).toBe(true);
        expect(shouldCache({ url: `${APP_ORIGIN}/build/assets/app.css`, method: "GET" }, APP_ORIGIN)).toBe(true);
    });

    it("/organizations/test-org/app/* の navigation / JSON は素通し", () => {
        expect(shouldCache({ url: `${APP_ORIGIN}/organizations/test-org/app`, method: "GET" }, APP_ORIGIN)).toBe(false);
        expect(shouldCache({ url: `${APP_ORIGIN}/organizations/test-org/app/projects/1/manuals`, method: "GET" }, APP_ORIGIN)).toBe(false);
    });

    it("S3 / 署名 URL (別オリジン) は /build/* でも素通し", () => {
        expect(
            shouldCache({ url: "https://bucket.s3.amazonaws.com/build/x?X-Amz-Signature=s", method: "GET" }, APP_ORIGIN),
        ).toBe(false);
    });

    it("GET 以外 (POST/PUT) は常に素通し", () => {
        expect(shouldCache({ url: `${APP_ORIGIN}/build/app.js`, method: "POST" }, APP_ORIGIN)).toBe(false);
        expect(shouldCache({ url: `${APP_ORIGIN}/build/app.js`, method: "PUT" }, APP_ORIGIN)).toBe(false);
    });

    it("不正 URL は素通し", () => {
        expect(shouldCache({ url: "not-a-url", method: "GET" }, APP_ORIGIN)).toBe(false);
    });
});

describe("SW イベント配線", () => {
    it("fetch: /build/* のみ respondWith し、/organizations/test-org/app/* はハンドリングしない", async () => {
        const cache = { match: vi.fn(async () => undefined), put: vi.fn(async () => {}) };
        const fakeCaches = {
            open: vi.fn(async () => cache),
            keys: vi.fn(async () => []),
            delete: vi.fn(async () => true),
        };
        vi.stubGlobal("fetch", vi.fn(async () => new Response("asset", { status: 200 })));
        const sw = loadSw(fakeCaches);
        const fetchHandler = sw.listeners.get("fetch");
        expect(fetchHandler).toBeDefined();

        const respondWith = vi.fn();
        fetchHandler!({
            request: { url: `${APP_ORIGIN}/build/assets/app.js`, method: "GET" },
            respondWith,
        });
        expect(respondWith).toHaveBeenCalledTimes(1);

        const passthrough = vi.fn();
        fetchHandler!({
            request: { url: `${APP_ORIGIN}/organizations/test-org/app/projects/1/manuals`, method: "GET" },
            respondWith: passthrough,
        });
        expect(passthrough).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });

    it("activate: 旧バージョンの capture-* キャッシュを削除する", async () => {
        const fakeCaches = {
            open: vi.fn(),
            keys: vi.fn(async () => ["capture-v0", "capture-v1", "other-cache"]),
            delete: vi.fn(async () => true),
        };
        const sw = loadSw(fakeCaches);
        const activateHandler = sw.listeners.get("activate");
        expect(activateHandler).toBeDefined();

        let settled: Promise<unknown> | null = null;
        activateHandler!({
            waitUntil: (promise: Promise<unknown>) => {
                settled = promise;
            },
        });
        await settled;

        expect(fakeCaches.delete).toHaveBeenCalledWith("capture-v0");
        expect(fakeCaches.delete).not.toHaveBeenCalledWith("capture-v1"); // 現行版は残す
        expect(fakeCaches.delete).not.toHaveBeenCalledWith("other-cache"); // 他機能のキャッシュに触れない
    });
});
