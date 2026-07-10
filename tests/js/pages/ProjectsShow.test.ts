import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Show from "@/pages/Projects/Show.svelte";
import type { ManualFilters, ManualListItem, PaginationMeta } from "@/types/manual";

const emptyMeta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 10, total: 0 };
const emptyFilters: ManualFilters = { category: null, status: null, q: null };

const manualsFixture: ManualListItem[] = [
    {
        id: 1,
        title: "ネジ締め作業",
        status: "draft",
        category: { id: 1, name: "準備作業" },
        created_at: "2026-07-10 12:00",
    },
    {
        id: 2,
        title: "洗浄手順",
        status: "published",
        category: null,
        created_at: "2026-07-10 13:00",
    },
];

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト", description: "説明文" },
    items: [
        { id: 1, name: "アイテム壱", note: "メモ壱" },
        { id: 2, name: "アイテム弐", note: null },
    ],
    canManage: true,
    manuals: {
        data: manualsFixture,
        meta: { ...emptyMeta, total: 2 },
    },
    categories: [{ id: 1, name: "準備作業" }],
    manualFilters: emptyFilters,
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

    it("動画マニュアル一覧を状態バッジ・カテゴリ付きで描画する (未分類は「未分類」)", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("manual-list")).toBeInTheDocument();
        expect(screen.getByTestId("manual-link-1")).toHaveTextContent("ネジ締め作業");
        expect(screen.getByTestId("manual-link-1").getAttribute("href")).toMatch(
            /\/projects\/1\/manuals\/1$/,
        );
        expect(screen.getByTestId("manual-status-1")).toHaveTextContent("下書き");
        expect(screen.getByText(/準備作業 ・ 2026-07-10 12:00/)).toBeInTheDocument();
        expect(screen.getByTestId("manual-status-2")).toHaveTextContent("公開済み");
        expect(screen.getByText(/未分類 ・ 2026-07-10 13:00/)).toBeInTheDocument();
    });

    it("manuals が空のときは EmptyState を表示する", () => {
        render(Show, {
            props: { ...baseProps, manuals: { data: [], meta: emptyMeta } },
        });

        expect(screen.getByTestId("manuals-empty")).toBeInTheDocument();
        expect(screen.queryByTestId("manual-list")).toBeNull();
    });

    it("フィルタにカテゴリ・未分類・状態の選択肢と検索ボタンを描画する (disabled 不使用)", () => {
        render(Show, { props: baseProps });

        const categorySelect = screen.getByTestId("manual-filter-category");
        expect(categorySelect).toBeInTheDocument();
        expect(categorySelect).not.toBeDisabled();
        expect(screen.getByRole("option", { name: "未分類" })).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "準備作業" })).toBeInTheDocument();

        const statusSelect = screen.getByTestId("manual-filter-status");
        expect(statusSelect).not.toBeDisabled();
        expect(screen.getByRole("option", { name: "下書き" })).toBeInTheDocument();

        const submit = screen.getByTestId("manual-filter-submit");
        expect(submit).toBeInTheDocument();
        expect(submit).not.toBeDisabled();
    });

    it("canManage=true なら新規作成導線とカテゴリ管理を表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("create-manual-button").getAttribute("href")).toMatch(
            /\/projects\/1\/manuals\/create$/,
        );
        expect(screen.getByRole("heading", { name: "カテゴリ管理" })).toBeInTheDocument();
        expect(screen.getByTestId("category-list")).toBeInTheDocument();
        expect(screen.getByTestId("move-up-category-1")).toBeInTheDocument();
        expect(screen.getByTestId("move-down-category-1")).toBeInTheDocument();
        expect(screen.getByTestId("edit-category-1")).toBeInTheDocument();
        expect(screen.getByTestId("remove-category-1")).toBeInTheDocument();
        expect(screen.getByTestId("category-submit")).toBeInTheDocument();
    });

    it("canManage=false なら新規作成導線とカテゴリ管理を表示しない (一覧は閲覧可)", () => {
        render(Show, { props: { ...baseProps, canManage: false } });

        expect(screen.queryByTestId("create-manual-button")).toBeNull();
        expect(screen.queryByRole("heading", { name: "カテゴリ管理" })).toBeNull();
        expect(screen.queryByTestId("category-list")).toBeNull();
        expect(screen.getByTestId("manual-list")).toBeInTheDocument();
    });
});
