import { addToast } from "@/lib/stores/toast";

/**
 * Laravel flash → toast 変換。
 *
 * Inertia の shared props (flash) は Layout の再評価ごとに同じ値で再注入されるため、
 * visit ごとに一意な visitKey で de-dup し、同一 visit の flash は一度だけ消費する。
 */

export interface FlashPayload {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    /** visit ごとに一意なキー (de-dup 用)。backend が flash と一緒に発行する */
    visitKey?: string | null;
}

/** 最後に消費した visitKey (モジュール変数で保持し、同一 visit の再評価を抑止する) */
let lastVisitKey: string | null = null;

/** flash の各キーと toast type の対応 (キーが入っていれば対応する type で addToast する) */
const FLASH_KEYS = ["success", "error", "info", "warning"] as const;

/**
 * flash payload を toast に変換して enqueue する。
 * 同じ visitKey は一度だけ消費する。visitKey 不在時は de-dup 不能のため消費しない
 * (stale props の再評価で同じ通知を二重表示しないことを優先する)。
 */
export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.visitKey ?? null;
    if (!key || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (message) {
            addToast(flashKey, message);
        }
    }
}

/** de-dup 状態をリセットする (テスト用。アプリコードからは呼ばない) */
export function resetFlashConsumption(): void {
    lastVisitKey = null;
}
