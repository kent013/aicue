import { captureFetch } from "@/lib/capture/http";
import type { CaptureConflictBody, QuotaExceededBody, UploadTicket } from "@/types/capture";

/**
 * アップロードキュー (概念設計 D9: 即時アップロード優先)。
 * - enqueue: SHA-256 算出 (D2b) → オンラインなら即時 upload-url → S3 PUT → POST takes。
 *   成功したら blob を端末に残さない。失敗/オフライン時のみ PendingStore (IndexedDB) に保持。
 * - resume: visibilitychange / online / SW message から呼ばれ pending を再送する。
 * - 422 quota_exceeded はキュー停止 + UI 通知 (disabled にはしない = 送信時エラー表示)。
 * - 409 registration_in_flight は指数 backoff で有界リトライ (fresh verifying = 処理中)。
 */

export interface PendingUpload {
    clientTakeId: string;
    projectId: number;
    manualId: number;
    cutId: number;
    blob: Blob;
    contentType: string;
    durationMs: number | null;
    capturedAt: string;
}

/** 一時バッファの抽象 (実装: lib/capture/idb.ts。テストは memory store) */
export interface PendingStore {
    put(item: PendingUpload): Promise<void>;
    delete(clientTakeId: string): Promise<void>;
    list(): Promise<PendingUpload[]>;
}

export type UploadOutcome =
    | { status: "uploaded"; clientTakeId: string }
    | { status: "queued"; clientTakeId: string; reason: string }
    | { status: "quota_exceeded"; clientTakeId: string; message: string };

export interface UploadQueueOptions {
    store: PendingStore;
    fetcher?: typeof captureFetch;
    /** backoff 待機 (テストで即時解決に差し替える) */
    delay?: (ms: number) => Promise<void>;
    /** navigator.onLine の参照 (テスト差し替え用) */
    isOnline?: () => boolean;
    /** 409 registration_in_flight の最大リトライ回数 */
    maxInFlightRetries?: number;
}

/** Blob → ArrayBuffer (arrayBuffer() 未実装環境は FileReader フォールバック) */
async function blobToArrayBuffer(blob: Blob): Promise<ArrayBuffer> {
    if (typeof blob.arrayBuffer === "function") {
        return blob.arrayBuffer();
    }
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as ArrayBuffer);
        reader.onerror = () => reject(reader.error ?? new Error("blob の読み込みに失敗しました"));
        reader.readAsArrayBuffer(blob);
    });
}

/** blob の SHA-256 を base64 で算出する (WebCrypto。概念設計 D2b) */
export async function computeChecksumBase64(blob: Blob): Promise<string> {
    const digest = await crypto.subtle.digest("SHA-256", await blobToArrayBuffer(blob));
    let binary = "";
    for (const byte of new Uint8Array(digest)) {
        binary += String.fromCharCode(byte);
    }
    return btoa(binary);
}

/** 端末生成 ULID (Crockford Base32・26 桁。同期冪等キー) */
export function generateClientTakeId(): string {
    const alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";
    const time = Date.now();
    let id = "";
    let remaining = time;
    for (let i = 0; i < 10; i++) {
        id = alphabet[remaining % 32] + id;
        remaining = Math.floor(remaining / 32);
    }
    const random = new Uint8Array(16);
    crypto.getRandomValues(random);
    for (let i = 0; i < 16; i++) {
        id += alphabet[random[i] % 32];
    }
    return id;
}

export class QuotaExceededError extends Error {}

export class UploadQueue {
    private readonly store: PendingStore;
    private readonly fetcher: typeof captureFetch;
    private readonly delay: (ms: number) => Promise<void>;
    private readonly isOnline: () => boolean;
    private readonly maxInFlightRetries: number;

    /** quota 超過でキューを停止した際の通知メッセージ (UI が購読) */
    quotaMessage: string | null = null;
    private stopped = false;

    constructor(options: UploadQueueOptions) {
        this.store = options.store;
        this.fetcher = options.fetcher ?? captureFetch;
        this.delay = options.delay ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
        this.isOnline = options.isOnline ?? (() => navigator.onLine);
        this.maxInFlightRetries = options.maxInFlightRetries ?? 3;
    }

    /** 録画確定後の投入。オンラインなら即時アップロード、失敗時のみ永続化 */
    async enqueue(item: PendingUpload): Promise<UploadOutcome> {
        if (!this.isOnline() || this.stopped) {
            await this.store.put(item);
            return { status: "queued", clientTakeId: item.clientTakeId, reason: "offline" };
        }
        return this.processOne(item, { persisted: false });
    }

    /** pending を全件再送 (visibilitychange / online / SW message から) */
    async resume(): Promise<UploadOutcome[]> {
        if (!this.isOnline()) return [];
        this.stopped = false;
        this.quotaMessage = null;
        const outcomes: UploadOutcome[] = [];
        for (const item of await this.store.list()) {
            if (this.stopped) break;
            outcomes.push(await this.processOne(item, { persisted: true }));
        }
        return outcomes;
    }

    private async processOne(
        item: PendingUpload,
        context: { persisted: boolean },
    ): Promise<UploadOutcome> {
        try {
            await this.upload(item);
            if (context.persisted) {
                await this.store.delete(item.clientTakeId);
            }
            return { status: "uploaded", clientTakeId: item.clientTakeId };
        } catch (error) {
            if (error instanceof QuotaExceededError) {
                // 容量上限: キューを停止し UI に通知 (blob は保持 = 上限解消後に resume)
                this.stopped = true;
                this.quotaMessage = error.message;
                if (!context.persisted) {
                    await this.store.put(item);
                }
                return {
                    status: "quota_exceeded",
                    clientTakeId: item.clientTakeId,
                    message: error.message,
                };
            }
            if (!context.persisted) {
                await this.store.put(item);
            }
            return {
                status: "queued",
                clientTakeId: item.clientTakeId,
                reason: error instanceof Error ? error.message : "upload failed",
            };
        }
    }

    /** upload-url → S3 PUT → POST takes の 3 段 (D2-D4 経路) */
    private async upload(item: PendingUpload): Promise<void> {
        const base = `/app/projects/${item.projectId}/manuals/${item.manualId}/cuts/${item.cutId}/takes`;
        const checksum = await computeChecksumBase64(item.blob);

        const ticketResponse = await this.fetcher(`${base}/upload-url`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                client_take_id: item.clientTakeId,
                size_bytes: item.blob.size,
                content_type: item.contentType,
                checksum_sha256: checksum,
            }),
        });
        if (ticketResponse.status === 422) {
            const body = (await ticketResponse.json()) as Partial<QuotaExceededBody>;
            if (body.code === "quota_exceeded") {
                throw new QuotaExceededError(body.message ?? "保存容量の上限に達しています。");
            }
            throw new Error("アップロード URL の取得に失敗しました。");
        }
        if (!ticketResponse.ok) {
            throw new Error("アップロード URL の取得に失敗しました。");
        }
        const ticket = (await ticketResponse.json()) as UploadTicket;

        // S3 直 PUT (presign ヘッダをそのまま付ける。cookie は送らない)
        const putResponse = await fetch(ticket.upload_url, {
            method: "PUT",
            headers: ticket.headers,
            body: item.blob,
        });
        if (!putResponse.ok) {
            throw new Error("動画のアップロードに失敗しました。");
        }

        // 登録 (409 registration_in_flight は有界 backoff リトライ)
        for (let attempt = 0; ; attempt++) {
            const registerResponse = await this.fetcher(base, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    ticket: ticket.ticket,
                    client_take_id: item.clientTakeId,
                    duration_ms: item.durationMs,
                    captured_at: item.capturedAt,
                }),
            });
            if (registerResponse.ok) return;
            if (registerResponse.status === 409) {
                const body = (await registerResponse.json()) as Partial<CaptureConflictBody>;
                if (
                    body.code === "capture_conflict" &&
                    body.conflict_type === "registration_in_flight" &&
                    attempt < this.maxInFlightRetries
                ) {
                    await this.delay(2 ** attempt * 1000);
                    continue;
                }
            }
            throw new Error("テイクの登録に失敗しました。");
        }
    }
}
