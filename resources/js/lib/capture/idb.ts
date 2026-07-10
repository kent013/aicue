import type { PendingStore, PendingUpload } from "@/lib/capture/upload-queue";

/**
 * オフライン/失敗時の一時バッファ (IndexedDB)。即時アップロード優先設計のため
 * 保持時間は最小化する (概念設計 D9。iOS Safari の eviction リスクを直視)。
 * upload-queue には PendingStore interface で注入する (テストは memory store)。
 */
const DB_NAME = "aicue-capture";
const STORE = "pending-uploads";
const DB_VERSION = 1;

function openDb(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(STORE)) {
                request.result.createObjectStore(STORE, { keyPath: "clientTakeId" });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error("IndexedDB を開けません"));
    });
}

function requestAsPromise<T>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error("IndexedDB 操作に失敗しました"));
    });
}

/** IndexedDB 実装の PendingStore */
export function createIdbPendingStore(): PendingStore {
    return {
        async put(item: PendingUpload): Promise<void> {
            const db = await openDb();
            await requestAsPromise(
                db.transaction(STORE, "readwrite").objectStore(STORE).put(item),
            );
            db.close();
        },
        async delete(clientTakeId: string): Promise<void> {
            const db = await openDb();
            await requestAsPromise(
                db.transaction(STORE, "readwrite").objectStore(STORE).delete(clientTakeId),
            );
            db.close();
        },
        async list(): Promise<PendingUpload[]> {
            const db = await openDb();
            const items = await requestAsPromise(
                db.transaction(STORE, "readonly").objectStore(STORE).getAll(),
            );
            db.close();
            return items as PendingUpload[];
        },
    };
}
