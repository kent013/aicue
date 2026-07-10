import { writable, type Readable } from "svelte/store";

/**
 * Toast 通知ストア (singleton)。
 *
 * - success / info / warning: 4 秒で自動消去
 * - error: 自動消去しない (手動閉じのみ。ユーザーが読み終える前に消さない)
 *
 * 表示は ToastContainer organism が担い、画面には 1 箇所のみ mount すること。
 * Svelte component 外 (Inertia callback 等) からも呼べるよう svelte/store で実装する
 * (テスト容易性: get(toasts) でスナップショット取得できる)。
 */

export type ToastType = "success" | "info" | "warning" | "error";

export interface Toast {
    id: number;
    type: ToastType;
    message: string;
}

/** type 別の自動消去時間 (ms)。null は自動消去しない */
const AUTO_DISMISS_MS: Record<ToastType, number | null> = {
    success: 4000,
    info: 4000,
    warning: 4000,
    error: null,
};

const store = writable<Toast[]>([]);

let nextId = 1;

// 自動消去タイマーは id → handle で管理し、手動 dismiss 時にも確実に解除する (リーク防止)
const timers = new Map<number, ReturnType<typeof setTimeout>>();

/**
 * toast を追加する。success / info / warning は 4 秒後に自動消去される。
 * @returns 追加した toast の id (空メッセージは追加せず -1 を返す)
 */
export function addToast(type: ToastType, message: string): number {
    const trimmed = message.trim();
    if (!trimmed) return -1; // 空メッセージは積まない
    const id = nextId++;
    store.update((items) => [...items, { id, type, message: trimmed }]);
    const ttl = AUTO_DISMISS_MS[type];
    if (ttl !== null) {
        timers.set(
            id,
            setTimeout(() => dismissToast(id), ttl),
        );
    }
    return id;
}

/** 指定 id の toast を消去する (自動消去タイマーも解除) */
export function dismissToast(id: number): void {
    const handle = timers.get(id);
    if (handle !== undefined) {
        clearTimeout(handle);
        timers.delete(id);
    }
    store.update((items) => items.filter((t) => t.id !== id));
}

/** 全 toast とタイマーを破棄する (ToastContainer unmount 時の cleanup / テスト用) */
export function clearToasts(): void {
    timers.forEach((handle) => clearTimeout(handle));
    timers.clear();
    store.set([]);
}

/** 購読専用 view (外部から set させない) */
export const toasts: Readable<Toast[]> = { subscribe: store.subscribe };
