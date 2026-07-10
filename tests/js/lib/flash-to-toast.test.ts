import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { get } from "svelte/store";
import { consumeFlash, resetFlashConsumption } from "@/lib/stores/flash-to-toast";
import { clearToasts, toasts } from "@/lib/stores/toast";

describe("flash-to-toast", () => {
    beforeEach(() => {
        resetFlashConsumption();
        clearToasts();
    });

    afterEach(() => {
        clearToasts();
    });

    it("flash の各キーが対応する type の toast になる", () => {
        consumeFlash({
            success: "保存しました",
            error: "一部失敗しました",
            info: "お知らせ",
            warning: "注意してください",
            visitKey: "visit-1",
        });

        const items = get(toasts);
        expect(items).toHaveLength(4);
        expect(items.map((t) => [t.type, t.message])).toEqual([
            ["success", "保存しました"],
            ["error", "一部失敗しました"],
            ["info", "お知らせ"],
            ["warning", "注意してください"],
        ]);
    });

    it("同じ visitKey の 2 回目は追加されない (de-dup)", () => {
        consumeFlash({ success: "保存しました", visitKey: "visit-1" });
        consumeFlash({ success: "保存しました", visitKey: "visit-1" });

        expect(get(toasts)).toHaveLength(1);
    });

    it("異なる visitKey なら再度追加される", () => {
        consumeFlash({ success: "1 回目", visitKey: "visit-1" });
        consumeFlash({ success: "2 回目", visitKey: "visit-2" });

        const items = get(toasts);
        expect(items).toHaveLength(2);
        expect(items[1].message).toBe("2 回目");
    });

    it("visitKey 不在の flash は消費しない (de-dup 不能な二重表示を防ぐ)", () => {
        consumeFlash({ success: "キー無し" });
        consumeFlash({ success: "キー null", visitKey: null });
        consumeFlash(undefined);
        consumeFlash(null);

        expect(get(toasts)).toHaveLength(0);
    });

    it("メッセージが空の flash キーは toast にならない", () => {
        consumeFlash({ success: "成功のみ", error: null, visitKey: "visit-1" });

        const items = get(toasts);
        expect(items).toHaveLength(1);
        expect(items[0].type).toBe("success");
    });

    it("visitKey 不在の flash を挟んでも直前の visitKey を保持し続ける", () => {
        consumeFlash({ success: "1 回目", visitKey: "visit-1" });
        consumeFlash({ success: "キー無し" });
        consumeFlash({ success: "再評価", visitKey: "visit-1" });

        expect(get(toasts)).toHaveLength(1);
    });
});
