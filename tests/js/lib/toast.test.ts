import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { get } from "svelte/store";
import { addToast, clearToasts, dismissToast, toasts } from "@/lib/stores/toast";

describe("toast store", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        clearToasts();
    });

    afterEach(() => {
        clearToasts();
        vi.useRealTimers();
    });

    it("addToast で toast が追加され id を返す", () => {
        const id = addToast("success", "保存しました");

        const items = get(toasts);
        expect(items).toHaveLength(1);
        expect(items[0]).toEqual({ id, type: "success", message: "保存しました" });
    });

    it("空メッセージ (trim 後空) は追加されず -1 を返す", () => {
        const id = addToast("info", "   ");

        expect(id).toBe(-1);
        expect(get(toasts)).toHaveLength(0);
    });

    it("dismissToast で指定 id の toast だけが消える", () => {
        const id1 = addToast("error", "失敗 1");
        const id2 = addToast("error", "失敗 2");

        dismissToast(id1);

        const items = get(toasts);
        expect(items).toHaveLength(1);
        expect(items[0].id).toBe(id2);
    });

    it("success / info / warning は 4 秒で自動消去される", () => {
        addToast("success", "完了");
        addToast("info", "お知らせ");
        addToast("warning", "注意");
        expect(get(toasts)).toHaveLength(3);

        vi.advanceTimersByTime(3999);
        expect(get(toasts)).toHaveLength(3);

        vi.advanceTimersByTime(1);
        expect(get(toasts)).toHaveLength(0);
    });

    it("error は自動消去されない (手動閉じのみ)", () => {
        const id = addToast("error", "エラー発生");

        vi.advanceTimersByTime(60_000);
        expect(get(toasts)).toHaveLength(1);

        dismissToast(id);
        expect(get(toasts)).toHaveLength(0);
    });

    it("dismiss 済み toast の自動消去タイマーが他の toast に影響しない", () => {
        const id1 = addToast("success", "先に消す");
        addToast("success", "残す");

        dismissToast(id1);
        vi.advanceTimersByTime(4000);

        expect(get(toasts)).toHaveLength(0); // 2 件目も 4 秒経過で正しく消える
    });

    it("clearToasts で全 toast が消える", () => {
        addToast("success", "a");
        addToast("error", "b");

        clearToasts();

        expect(get(toasts)).toHaveLength(0);
    });
});
