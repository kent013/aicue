import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Edit from "@/pages/Manuals/Edit.svelte";
import type { ScenarioDocument } from "@/types/manual";

const scenario: ScenarioDocument = {
    scenario_version: 0,
    steps: [],
};

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    manual: { id: 5, title: "ネジ締め作業", category: 2, status: "draft" as const },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
    scenario,
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

    it("2 つの保存系統が分離して描画される (基本情報を保存 / シナリオを更新)", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByRole("heading", { name: "基本情報" })).toBeInTheDocument();
        expect(screen.getByRole("heading", { name: "シナリオ" })).toBeInTheDocument();
        expect(screen.getByTestId("manual-submit")).toHaveTextContent("基本情報を保存");
        expect(screen.getByTestId("scenario-submit")).toHaveTextContent("シナリオを更新");
    });

    it("空シナリオでは EmptyState (最初の手順を追加) が表示される", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByTestId("scenario-empty-state")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "最初の手順を追加" })).toBeInTheDocument();
    });

    it("保存ボタンは disabled にしない", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByTestId("manual-submit")).not.toBeDisabled();
        expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
    });
});
