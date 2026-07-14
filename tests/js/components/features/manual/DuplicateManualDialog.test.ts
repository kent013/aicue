import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { reactiveUseForm } from "../../../support/reactiveUseForm.svelte";

// useForm を反応的フェイクへ差し替える (init 値を尊重するため prefill も観測できる)。
// 生成したフォームを holder に退避し、POST の呼び出し (URL) をアサートする。
const { holder } = vi.hoisted(() => ({ holder: { last: null as unknown } }));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    useForm: (init: Record<string, unknown>) => {
        const form = reactiveUseForm(init);
        holder.last = form;
        return form;
    },
}));

import DuplicateManualDialog from "@/components/features/manual/DuplicateManualDialog.svelte";

const baseProps = {
    open: true,
    projectId: 1,
    manualId: 5,
    defaultTitle: "ネジ締め作業 のコピー",
    defaultCategory: 2 as number | null,
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
};

describe("features/manual/DuplicateManualDialog", () => {
    beforeEach(() => {
        holder.last = null;
    });

    it("タイトルは defaultTitle、カテゴリは defaultCategory をプリフィルする", async () => {
        render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-dialog")).toBeInTheDocument();
        });
        const title = screen.getByLabelText(/タイトル/) as HTMLInputElement;
        expect(title.value).toBe("ネジ締め作業 のコピー");
        const category = screen.getByTestId("duplicate-category-select") as HTMLSelectElement;
        expect(category.value).toBe("2");
    });

    it("『複製する』押下で /projects/{id}/manuals/{id}/duplicate に POST する", async () => {
        render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByTestId("duplicate-manual-confirm"));

        const form = holder.last as { post: ReturnType<typeof vi.fn> };
        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.post.mock.calls[0][0]).toBe("/projects/1/manuals/5/duplicate");
    });

    it("送信ボタンは必須未充足でも disabled にしない (禁止事項8)", async () => {
        render(DuplicateManualDialog, { props: { ...baseProps, defaultTitle: "" } });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).not.toBeDisabled();
        });
    });
});
