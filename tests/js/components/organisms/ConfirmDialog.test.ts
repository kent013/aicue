import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
import type { ConfirmDialogProps } from "@/components/organisms/ConfirmDialog.types";

describe("ConfirmDialog", () => {
    it("open=true で title / message / 既定ラベルのボタンが描画される", async () => {
        render(ConfirmDialog, {
            props: {
                open: true,
                title: "削除の確認",
                message: "この操作は取り消せません。",
                onConfirm: vi.fn(),
            },
        });

        expect(await screen.findByText("削除の確認")).toBeInTheDocument();
        expect(screen.getByText("この操作は取り消せません。")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "確認" })).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "キャンセル" })).toBeInTheDocument();
    });

    it("確認ボタン押下で onConfirm が発火する", async () => {
        const onConfirm = vi.fn();
        render(ConfirmDialog, {
            props: { open: true, title: "確認", message: "実行しますか?", onConfirm },
        });

        await fireEvent.click(await screen.findByRole("button", { name: "確認" }));

        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it("キャンセルボタン押下で onCancel が発火し onConfirm は呼ばれない", async () => {
        const onConfirm = vi.fn();
        const onCancel = vi.fn();
        render(ConfirmDialog, {
            props: { open: true, title: "確認", message: "実行しますか?", onConfirm, onCancel },
        });

        await fireEvent.click(await screen.findByRole("button", { name: "キャンセル" }));

        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it("confirmVariant=danger で確認ボタンが danger スタイルになる", async () => {
        render(ConfirmDialog, {
            props: {
                open: true,
                title: "削除",
                message: "削除しますか?",
                confirmVariant: "danger",
                confirmLabel: "削除する",
                onConfirm: vi.fn(),
            },
        });

        const confirmButton = await screen.findByRole("button", { name: "削除する" });
        expect(confirmButton.className).toContain("bg-danger");
    });

    it("processing 中は confirm が loading (disabled) / cancel が disabled になる", async () => {
        render(ConfirmDialog, {
            props: {
                open: true,
                title: "確認",
                message: "処理中",
                processing: true,
                onConfirm: vi.fn(),
            },
        });

        expect(await screen.findByRole("button", { name: "確認" })).toBeDisabled();
        expect(screen.getByRole("button", { name: "キャンセル" })).toBeDisabled();
    });

    it("confirmVariant は primary | danger の 2 値のみ (型レベル)", () => {
        const _invalid: ConfirmDialogProps = {
            open: true,
            title: "t",
            message: "m",
            // @ts-expect-error confirmVariant に 'success' は指定できない (2 値限定)
            confirmVariant: "success",
            onConfirm: () => undefined,
        };
        const _valid: ConfirmDialogProps = {
            open: true,
            title: "t",
            message: "m",
            confirmVariant: "danger",
            onConfirm: () => undefined,
        };
        expect(true).toBe(true);
    });
});
