import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Create from "@/pages/Manuals/Create.svelte";

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
};

describe("Manuals/Create", () => {
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
});
