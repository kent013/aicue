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

    it("『複製する』押下で /organizations/test-org/projects/{id}/manuals/{id}/duplicate に POST する", async () => {
        render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByTestId("duplicate-manual-confirm"));

        const form = holder.last as { post: ReturnType<typeof vi.fn> };
        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.post.mock.calls[0][0]).toBe("/organizations/test-org/projects/1/manuals/5/duplicate");
    });

    it("送信ボタンは必須未充足でも disabled にしない (禁止事項8)", async () => {
        render(DuplicateManualDialog, { props: { ...baseProps, defaultTitle: "" } });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).not.toBeDisabled();
        });
    });

    it("複製 submit の onSuccess でダイアログが閉じる (F-1-01)", async () => {
        render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByTestId("duplicate-manual-confirm"));

        const form = holder.last as { post: ReturnType<typeof vi.fn> };
        expect(form.post).toHaveBeenCalledTimes(1);
        // reactiveUseForm の post は callback を自動実行しないため、捕捉した onSuccess を手動発火する
        const options = form.post.mock.calls[0][1] as { onSuccess?: () => void };
        options.onSuccess?.();

        await waitFor(() => {
            expect(screen.queryByTestId("duplicate-manual-dialog")).not.toBeInTheDocument();
        });
    });

    it("送信中は submit() 冒頭ガードで二重送信しない (関数ガード)", async () => {
        render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
        });

        const form = holder.last as { processing: boolean; post: ReturnType<typeof vi.fn> };
        form.processing = true;

        // フォームへ submit を直接発火 (ボタン disabled に依らず handler を叩く = Enter 相当)。
        // Modal は portal でツリー外へ描画されるため document から取得する。
        const formEl = document.getElementById("duplicate-manual-form") as HTMLFormElement;
        await fireEvent.submit(formEl);

        expect(form.post).not.toHaveBeenCalled();
    });

    it("送信中は confirm ボタンが disabled かつ aria-busy になる (UI ガード)", async () => {
        render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
        });

        const form = holder.last as { processing: boolean };
        form.processing = true;

        await waitFor(() => {
            const confirm = screen.getByTestId("duplicate-manual-confirm");
            expect(confirm).toHaveAttribute("aria-busy", "true");
            expect(confirm).toBeDisabled();
        });
    });

    it("再オープン (false→true) で現 props に再 seed + clearErrors + エラーDOM消滅", async () => {
        const { rerender } = render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-dialog")).toBeInTheDocument();
        });

        // エラー文言が一度 DOM 表示されたことを確認 (偽陽性防止)
        const form = holder.last as {
            errors: Record<string, string>;
            title: string;
            category: string;
            clearErrors: ReturnType<typeof vi.fn>;
        };
        form.errors.title = "サーバエラー";
        await waitFor(() => {
            expect(screen.getByText("サーバエラー")).toBeInTheDocument();
        });

        // 一旦閉じて unmount を確認してから再オープンする (false 状態を effect が観測できるように)
        await rerender({ ...baseProps, open: false });
        await waitFor(() => {
            expect(screen.queryByTestId("duplicate-manual-dialog")).not.toBeInTheDocument();
        });

        form.clearErrors.mockClear();

        // false→true エッジで seedFromDefaults 発火
        await rerender({
            ...baseProps,
            open: true,
            defaultTitle: "新タイトル のコピー",
            defaultCategory: 1,
        });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-dialog")).toBeInTheDocument();
        });

        expect(form.title).toBe("新タイトル のコピー");
        expect(form.category).toBe("1");
        expect(form.clearErrors).toHaveBeenCalled();
        expect(screen.queryByText("サーバエラー")).not.toBeInTheDocument();

        // エッジ限定: open=true のまま props 変化しても再 seed しない
        await rerender({
            ...baseProps,
            open: true,
            defaultTitle: "別タイトル",
            defaultCategory: 1,
        });
        expect(form.title).toBe("新タイトル のコピー");
    });
});
