import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import { get } from "svelte/store";
import ToastContainer from "@/components/organisms/ToastContainer.svelte";
import { addToast, clearToasts, toasts } from "@/lib/stores/toast";

describe("ToastContainer", () => {
    beforeEach(() => {
        clearToasts();
    });

    afterEach(() => {
        clearToasts();
    });

    it("toast ストアの内容を表示する (success は role=status)", async () => {
        render(ToastContainer);
        addToast("success", "保存しました");

        const toast = await screen.findByRole("status");
        expect(toast).toHaveTextContent("保存しました");
        expect(toast.className).toContain("border-success");
    });

    it("error は role=alert で表示される", async () => {
        render(ToastContainer);
        addToast("error", "失敗しました");

        const toast = await screen.findByRole("alert");
        expect(toast).toHaveTextContent("失敗しました");
        expect(toast.className).toContain("border-danger");
    });

    it("複数 toast を stack 表示する", async () => {
        render(ToastContainer);
        addToast("info", "お知らせ 1");
        addToast("warning", "注意 2");

        expect(await screen.findByTestId("toast-info")).toBeInTheDocument();
        expect(screen.getByTestId("toast-warning")).toBeInTheDocument();
    });

    it("unmount → 再 mount しても toast は残る (消去責務は container に無い)", async () => {
        // 消去境界は layout の初期化に一本化してある (DESIGN.md §Toast)。container が
        // unmount で clearToasts() すると、着地先で flash を表示する契約の成否が
        // Svelte の破棄/フラッシュ順に依存してしまう。
        const first = render(ToastContainer);
        addToast("success", "リダイレクト前に積んだ通知");
        expect(await screen.findByTestId("toast-success")).toBeInTheDocument();

        first.unmount();
        expect(get(toasts)).toHaveLength(1);

        render(ToastContainer);
        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
            "リダイレクト前に積んだ通知",
        );
    });

    it("閉じるボタンで toast が消える", async () => {
        render(ToastContainer);
        addToast("error", "手動で閉じる");

        const closeButton = await screen.findByRole("button", { name: "閉じる" });
        await fireEvent.click(closeButton);

        expect(screen.queryByText("手動で閉じる")).not.toBeInTheDocument();
        expect(get(toasts)).toHaveLength(0);
    });
});
