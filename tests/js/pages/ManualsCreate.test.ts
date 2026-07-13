import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import { reactiveUseForm } from "../support/reactiveUseForm.svelte";

const { formState } = vi.hoisted(() => ({ formState: { current: null as unknown } }));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    useForm: () => formState.current,
    page: { props: {}, url: "/" },
}));

import Create from "@/pages/Manuals/Create.svelte";

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
};

/** 反応的フェイクフォームを毎テスト用意する (errors は $state で clearErrors 再描画を観測可能) */
function setupForm(errors: Record<string, string> = {}): void {
    formState.current = reactiveUseForm(
        { title: "", category: "", document: null as File | null },
        errors,
    );
}

describe("Manuals/Create", () => {
    beforeEach(() => setupForm());

    it("タイトル入力とカテゴリ選択 (未分類既定) を描画する", () => {
        render(Create, { props: baseProps });

        expect(screen.getByRole("heading", { name: "動画マニュアルの作成" })).toBeInTheDocument();
        expect(screen.getByLabelText(/タイトル/)).toBeInTheDocument();

        const select = screen.getByTestId("manual-category-select");
        expect(select).toBeInTheDocument();
        expect(select).toHaveValue("");
        expect(screen.getByRole("option", { name: "未分類" })).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "準備作業" })).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "仕上げ" })).toBeInTheDocument();
    });

    it("送信ボタンは必須未充足でも disabled にしない (押下時にエラー表示)", () => {
        render(Create, { props: baseProps });

        const submit = screen.getByTestId("manual-submit");
        expect(submit).toBeInTheDocument();
        expect(submit).not.toBeDisabled();
    });

    it("カテゴリ 0 件でも未分類のみで描画できる", () => {
        render(Create, { props: { ...baseProps, categories: [] } });

        expect(screen.getByTestId("manual-category-select")).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "未分類" })).toBeInTheDocument();
        expect(screen.queryByRole("option", { name: "準備作業" })).toBeNull();
    });

    it("手順書 (SOP) のファイル入力を描画する (任意・accept 制限付き)", () => {
        render(Create, { props: baseProps });

        const input = screen.getByTestId("manual-document-input");
        expect(input).toBeInTheDocument();
        expect(input.getAttribute("type")).toBe("file");
        expect(input.getAttribute("accept")).toBe(".pdf,.xlsx,.xls,.txt");
    });

    it("タイトル入力 (oninput) でタイトルエラーがその場でクリアされる", async () => {
        setupForm({ title: "タイトルを入力してください" });
        render(Create, { props: baseProps });

        // 初期はエラー文言が表示されている
        expect(screen.getByText("タイトルを入力してください")).toBeInTheDocument();

        const title = screen.getByLabelText(/タイトル/);
        await fireEvent.input(title, { target: { value: "ネ" } });

        // clearErrors("title") が呼ばれ、$state 反応で文言が消える
        expect(
            (formState.current as { clearErrors: ReturnType<typeof vi.fn> }).clearErrors,
        ).toHaveBeenCalledWith("title");
        expect(screen.queryByText("タイトルを入力してください")).toBeNull();
    });

    it("タイトルエラーが無いとき oninput は clearErrors を呼ばない", async () => {
        setupForm();
        render(Create, { props: baseProps });

        const title = screen.getByLabelText(/タイトル/);
        await fireEvent.input(title, { target: { value: "ネジ" } });

        expect(
            (formState.current as { clearErrors: ReturnType<typeof vi.fn> }).clearErrors,
        ).not.toHaveBeenCalled();
    });
});
