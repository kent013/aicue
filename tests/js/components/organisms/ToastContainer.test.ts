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

    it("閉じるボタンで toast が消える", async () => {
        render(ToastContainer);
        addToast("error", "手動で閉じる");

        const closeButton = await screen.findByRole("button", { name: "閉じる" });
        await fireEvent.click(closeButton);

        expect(screen.queryByText("手動で閉じる")).not.toBeInTheDocument();
        expect(get(toasts)).toHaveLength(0);
    });
});
