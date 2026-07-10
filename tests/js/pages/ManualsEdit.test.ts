import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Edit from "@/pages/Manuals/Edit.svelte";

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    manual: { id: 5, title: "ネジ締め作業", category: 2 },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
};

describe("Manuals/Edit", () => {
    it("現在のタイトルとカテゴリを初期値に描画する", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByRole("heading", { name: "動画マニュアルの編集" })).toBeInTheDocument();
        expect(screen.getByLabelText(/タイトル/)).toHaveValue("ネジ締め作業");
        expect(screen.getByTestId("manual-category-select")).toHaveValue("2");
    });

    it("未分類 (category=null) の manual は未分類が選択される", () => {
        render(Edit, {
            props: { ...baseProps, manual: { ...baseProps.manual, category: null } },
        });

        expect(screen.getByTestId("manual-category-select")).toHaveValue("");
    });

    it("保存ボタンは disabled にしない", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByTestId("manual-submit")).not.toBeDisabled();
    });
});
