import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    computeChecksumBase64,
    generateClientTakeId,
    UploadQueue,
    type PendingStore,
    type PendingUpload,
} from "@/lib/capture/upload-queue";

/*
 * アップロードキュー (概念設計 D9: 即時アップロード優先):
 * - 成功したら端末 (store) に残さない / 失敗・オフラインのみ永続化
 * - 422 quota_exceeded はキュー停止 + メッセージ保持
 * - 409 registration_in_flight は有界 backoff リトライ
 */

function memoryStore(): PendingStore & { items: Map<string, PendingUpload> } {
    const items = new Map<string, PendingUpload>();
    return {
        items,
        async put(item) {
            items.set(item.clientTakeId, item);
        },
        async delete(id) {
            items.delete(id);
        },
        async list() {
            return [...items.values()];
        },
    };
}

function pendingItem(overrides: Partial<PendingUpload> = {}): PendingUpload {
    return {
        clientTakeId: generateClientTakeId(),
        projectId: 1,
        manualId: 2,
        cutId: 3,
        blob: new Blob(["video-bytes"], { type: "video/mp4" }),
        contentType: "video/mp4",
        durationMs: 4200,
        capturedAt: "2026-07-11T00:00:00Z",
        ...overrides,
    };
}

function jsonResponse(status: number, body: unknown = {}): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}

const ticketBody = {
    upload_url: "https://s3.example.test/bucket/key?sig=1",
    headers: { "Content-Type": "video/mp4", "x-amz-checksum-sha256": "abc=" },
    ticket: "sealed-ticket",
    client_take_id: "X",
    expires_at: "2026-07-11T01:00:00Z",
};

const putFetchMock = vi.fn();

beforeEach(() => {
    // S3 直 PUT (素の fetch) 用
    vi.stubGlobal("fetch", putFetchMock);
    putFetchMock.mockResolvedValue(new Response(null, { status: 200 }));
});

afterEach(() => {
    putFetchMock.mockReset();
    vi.unstubAllGlobals();
});

describe("computeChecksumBase64", () => {
    it("既知ベクトル: SHA-256('abc') の base64", async () => {
        const checksum = await computeChecksumBase64(new Blob(["abc"]));

        expect(checksum).toBe("ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0=");
    });
});

describe("generateClientTakeId", () => {
    it("Crockford Base32 の 26 桁 ULID 形式を生成する", () => {
        const id = generateClientTakeId();

        expect(id).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(generateClientTakeId()).not.toBe(id);
    });
});

describe("UploadQueue", () => {
    it("即時アップロード成功: upload-url → S3 PUT → POST takes、store に残らない", async () => {
        const store = memoryStore();
        const fetcher = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(200, ticketBody)) // upload-url
            .mockResolvedValueOnce(jsonResponse(201, { id: 10 })); // POST takes
        const queue = new UploadQueue({ store, fetcher, isOnline: () => true });
        const item = pendingItem();

        const outcome = await queue.enqueue(item);

        expect(outcome.status).toBe("uploaded");
        expect(store.items.size).toBe(0);
        // upload-url へ checksum / size / content_type を申告
        const uploadUrlBody = JSON.parse(fetcher.mock.calls[0][1].body);
        expect(uploadUrlBody.client_take_id).toBe(item.clientTakeId);
        expect(uploadUrlBody.size_bytes).toBe(item.blob.size);
        expect(uploadUrlBody.checksum_sha256).toMatch(/^[A-Za-z0-9+/]{43}=$/);
        // S3 PUT は presign headers をそのまま付ける
        expect(putFetchMock).toHaveBeenCalledWith(
            ticketBody.upload_url,
            expect.objectContaining({ method: "PUT", headers: ticketBody.headers }),
        );
        // POST takes はチケットを送る
        const registerBody = JSON.parse(fetcher.mock.calls[1][1].body);
        expect(registerBody.ticket).toBe("sealed-ticket");
    });

    it("オフライン時は store へ永続化し、resume で再送・成功後に削除する", async () => {
        const store = memoryStore();
        let online = false;
        const fetcher = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(200, ticketBody))
            .mockResolvedValueOnce(jsonResponse(201, { id: 10 }));
        const queue = new UploadQueue({ store, fetcher, isOnline: () => online });
        const item = pendingItem();

        const queued = await queue.enqueue(item);
        expect(queued.status).toBe("queued");
        expect(store.items.size).toBe(1);
        expect(fetcher).not.toHaveBeenCalled();

        online = true;
        const outcomes = await queue.resume();
        expect(outcomes).toEqual([{ status: "uploaded", clientTakeId: item.clientTakeId }]);
        expect(store.items.size).toBe(0);
    });

    it("アップロード失敗時は永続化して queued を返す", async () => {
        const store = memoryStore();
        const fetcher = vi.fn().mockResolvedValue(jsonResponse(500));
        const queue = new UploadQueue({ store, fetcher, isOnline: () => true });

        const outcome = await queue.enqueue(pendingItem());

        expect(outcome.status).toBe("queued");
        expect(store.items.size).toBe(1);
    });

    it("422 quota_exceeded はキュー停止 + メッセージ保持 (blob は保持)", async () => {
        const store = memoryStore();
        const fetcher = vi
            .fn()
            .mockResolvedValue(jsonResponse(422, { code: "quota_exceeded", message: "保存容量の上限です" }));
        const queue = new UploadQueue({ store, fetcher, isOnline: () => true });

        const outcome = await queue.enqueue(pendingItem());

        expect(outcome.status).toBe("quota_exceeded");
        expect(queue.quotaMessage).toBe("保存容量の上限です");
        expect(store.items.size).toBe(1);

        // 停止中の enqueue は即時アップロードせず queued
        const next = await queue.enqueue(pendingItem());
        expect(next.status).toBe("queued");
        expect(fetcher).toHaveBeenCalledTimes(1);
    });

    it("409 registration_in_flight は指数 backoff で有界リトライし、成功すれば uploaded", async () => {
        const store = memoryStore();
        const delays: number[] = [];
        const fetcher = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(200, ticketBody))
            .mockResolvedValueOnce(
                jsonResponse(409, { code: "capture_conflict", conflict_type: "registration_in_flight" }),
            )
            .mockResolvedValueOnce(
                jsonResponse(409, { code: "capture_conflict", conflict_type: "registration_in_flight" }),
            )
            .mockResolvedValueOnce(jsonResponse(200, { id: 10 }));
        const queue = new UploadQueue({
            store,
            fetcher,
            isOnline: () => true,
            delay: async (ms) => {
                delays.push(ms);
            },
        });

        const outcome = await queue.enqueue(pendingItem());

        expect(outcome.status).toBe("uploaded");
        expect(delays).toEqual([1000, 2000]); // 指数 backoff
    });

    it("409 が上限回数を超えたら queued (端末保持) に倒す", async () => {
        const store = memoryStore();
        const fetcher = vi.fn().mockImplementation(async (url: string) =>
            url.endsWith("/upload-url")
                ? jsonResponse(200, ticketBody)
                : jsonResponse(409, {
                      code: "capture_conflict",
                      conflict_type: "registration_in_flight",
                  }),
        );
        const queue = new UploadQueue({
            store,
            fetcher,
            isOnline: () => true,
            delay: async () => {},
            maxInFlightRetries: 1,
        });

        const outcome = await queue.enqueue(pendingItem());

        expect(outcome.status).toBe("queued");
        expect(store.items.size).toBe(1);
    });
});
