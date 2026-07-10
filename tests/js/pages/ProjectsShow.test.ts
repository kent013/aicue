import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Show from "@/pages/Projects/Show.svelte";

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト", description: "説明文" },
    items: [
        { id: 1, name: "アイテム壱", note: "メモ壱" },
        { id: 2, name: "アイテム弐", note: null },
    ],
    canManage: true,
};

describe("Projects/Show", () => {
    it("プロジェクト情報と Item 一覧を描画する", () => {
        render(Show, { props: baseProps });

        expect(
            screen.getByRole("heading", { name: "サンプルプロジェクト" }),
        ).toBeInTheDocument();
        expect(screen.getByText("説明文")).toBeInTheDocument();
        expect(screen.getByTestId("item-list")).toBeInTheDocument();
        expect(screen.getByText("アイテム壱")).toBeInTheDocument();
        expect(screen.getByText("メモ壱")).toBeInTheDocument();
        expect(screen.getByText("アイテム弐")).toBeInTheDocument();
    });

    it("items が空のときは EmptyState を表示する", () => {
        render(Show, { props: { ...baseProps, items: [] } });

        expect(screen.getByTestId("items-empty")).toBeInTheDocument();
        expect(screen.queryByTestId("item-list")).toBeNull();
    });

    it("canManage=true なら追加フォームと各 Item の編集・削除を表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByRole("heading", { name: "アイテムを追加" })).toBeInTheDocument();
        expect(screen.getByTestId("item-submit")).toBeInTheDocument();
        expect(screen.getByTestId("edit-item-1")).toBeInTheDocument();
        expect(screen.getByTestId("remove-item-1")).toBeInTheDocument();
        expect(screen.getByTestId("delete-project-button")).toBeInTheDocument();
    });

    it("canManage=false なら管理操作を表示しない", () => {
        render(Show, { props: { ...baseProps, canManage: false } });

        expect(screen.queryByRole("heading", { name: "アイテムを追加" })).toBeNull();
        expect(screen.queryByTestId("edit-item-1")).toBeNull();
        expect(screen.queryByTestId("remove-item-1")).toBeNull();
        expect(screen.queryByTestId("delete-project-button")).toBeNull();
        expect(screen.queryByTestId("edit-project-button")).toBeNull();
    });
});
