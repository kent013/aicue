import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Categories from "@/pages/Admin/Categories.svelte";

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "本作業" },
        { id: 3, name: "仕上げ" },
    ],
    usersUrl: "/manage/users",
};

describe("Admin/Categories", () => {
    it("カテゴリ一覧と追加フォームを描画する", () => {
        render(Categories, { props: baseProps });

        expect(screen.getByRole("heading", { name: "カテゴリ管理" })).toBeInTheDocument();
        expect(screen.getByTestId("category-list")).toBeInTheDocument();
        expect(screen.getByText("準備作業")).toBeInTheDocument();
        expect(screen.getByLabelText(/カテゴリ名/)).toBeInTheDocument();
        expect(screen.getByTestId("category-submit")).toBeInTheDocument();
    });

    it("端の行は該当方向の▲▼を非描画にする (disabled にしない)", () => {
        render(Categories, { props: baseProps });

        // 先頭行: ▲なし・▼あり
        expect(screen.queryByTestId("move-up-category-1")).toBeNull();
        expect(screen.getByTestId("move-down-category-1")).toBeInTheDocument();
        // 中間行: 両方あり
        expect(screen.getByTestId("move-up-category-2")).toBeInTheDocument();
        expect(screen.getByTestId("move-down-category-2")).toBeInTheDocument();
        // 末尾行: ▲あり・▼なし
        expect(screen.getByTestId("move-up-category-3")).toBeInTheDocument();
        expect(screen.queryByTestId("move-down-category-3")).toBeNull();
    });

    it("必須未充足でも追加ボタンは disabled にしない (押下時エラー方針)", () => {
        render(Categories, { props: baseProps });

        expect(screen.getByTestId("category-submit")).not.toBeDisabled();
        expect(screen.getByTestId("edit-category-1")).not.toBeDisabled();
        expect(screen.getByTestId("remove-category-1")).not.toBeDisabled();
    });

    it("categories が空のときは EmptyState を表示する", () => {
        render(Categories, { props: { ...baseProps, categories: [] } });

        expect(screen.getByTestId("categories-empty")).toBeInTheDocument();
        expect(screen.queryByTestId("category-list")).toBeNull();
    });

    it("削除 ConfirmDialog は未分類化の警告文言を持つ", async () => {
        render(Categories, { props: baseProps });

        screen.getByTestId("remove-category-1").click();
        const dialog = await screen.findByTestId("remove-category-dialog");
        expect(dialog.textContent).toContain("準備作業");
        expect(dialog.textContent).toContain("未分類になります");
    });

    it("usersUrl が null なら管理メニューのユーザー管理項目を出さない (can 連動)", () => {
        render(Categories, { props: { ...baseProps, usersUrl: null } });

        expect(screen.queryByTestId("admin-nav-users")).toBeNull();
    });

    it("usersUrl があれば管理メニューにユーザー管理リンクを出す", () => {
        render(Categories, { props: baseProps });

        const link = screen.getByTestId("admin-nav-users");
        expect(link.getAttribute("href")).toMatch(/\/manage\/users$/);
    });
});
