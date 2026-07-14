import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
import { router } from "@inertiajs/svelte";
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
    canManageMembers: true,
    members: [
        // 暗黙メンバー (org admin。pivot なし = role null / implicit)
        { id: 1, name: "オーナー 太郎", email: "owner@example.com", role: null, implicit: true },
        {
            id: 2,
            name: "編集 花子",
            email: "editor@example.com",
            role: "project_admin",
            implicit: false,
        },
        {
            id: 3,
            name: "撮影 三郎",
            email: "shooter@example.com",
            role: "project_member",
            implicit: false,
        },
    ],
    canViewMemberEmails: true,
    assignableUsers: [{ id: 4, name: "候補 四郎" }],
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

    it("canManage=true なら新規作成導線を表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("create-manual-button").getAttribute("href")).toMatch(
            /\/projects\/1\/manuals\/create$/,
        );
    });

    it("カテゴリ CRUD UI は存在しない (Admin/Categories へ移設済み = 並走 UI の回帰封じ)", () => {
        render(Show, { props: baseProps });

        expect(screen.queryByTestId("category-list")).toBeNull();
        expect(screen.queryByTestId("category-submit")).toBeNull();
        expect(screen.queryByTestId("move-up-category-1")).toBeNull();
        expect(screen.queryByTestId("edit-category-1")).toBeNull();
        expect(screen.queryByTestId("remove-category-1")).toBeNull();
    });

    it("カテゴリフィルタ select は存続する (撤去し過ぎの回帰封じ)", () => {
        render(Show, { props: baseProps });

        const categorySelect = screen.getByTestId("manual-filter-category");
        expect(categorySelect).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "準備作業" })).toBeInTheDocument();
    });

    it("canManage / canManageMembers に応じて管理メニュー導線を表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("link-manage-categories").getAttribute("href")).toMatch(
            /\/projects\/1\/categories$/,
        );
        expect(screen.getByTestId("link-manage-users").getAttribute("href")).toMatch(
            /\/manage\/users$/,
        );
    });

    it("権限がなければ管理メニュー導線を表示しない", () => {
        render(Show, {
            props: { ...baseProps, canManage: false, canManageMembers: false },
        });

        expect(screen.queryByTestId("link-manage-categories")).toBeNull();
        expect(screen.queryByTestId("link-manage-users")).toBeNull();
        expect(screen.queryByRole("heading", { name: "管理メニュー" })).toBeNull();
        expect(screen.getByTestId("manual-list")).toBeInTheDocument();
    });
});

describe("Projects/Show メンバー管理", () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("canManage=true でメンバー一覧・追加フォーム・各明示メンバーの操作を描画する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByRole("heading", { name: "プロジェクトメンバー" })).toBeInTheDocument();
        expect(screen.getByTestId("project-member-list")).toBeInTheDocument();
        expect(screen.getByTestId("project-member-add-form")).toBeInTheDocument();
        expect(screen.getByTestId("project-member-submit")).toBeInTheDocument();
        // 明示メンバーは role select + 削除ボタンを持つ
        expect(screen.getByTestId("project-member-role-2")).toBeInTheDocument();
        expect(screen.getByTestId("project-member-remove-2")).toBeInTheDocument();
    });

    it("canManage=false ではメンバー管理 UI を一切表示しない", () => {
        render(Show, { props: { ...baseProps, canManage: false } });

        expect(screen.queryByRole("heading", { name: "プロジェクトメンバー" })).toBeNull();
        expect(screen.queryByTestId("project-member-list")).toBeNull();
        expect(screen.queryByTestId("project-member-add-form")).toBeNull();
        expect(screen.queryByTestId("project-member-role-2")).toBeNull();
        expect(screen.queryByTestId("project-member-remove-2")).toBeNull();
    });

    it("暗黙メンバー行はロール select・削除を出さず「管理者（組織）」バッジを表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("project-member-implicit-1")).toHaveTextContent("管理者（組織）");
        expect(screen.queryByTestId("project-member-role-1")).toBeNull();
        expect(screen.queryByTestId("project-member-remove-1")).toBeNull();
    });

    it("候補を選んで送信すると router.post が payload 付きで発火する", async () => {
        const postSpy = vi.spyOn(router, "post").mockImplementation(() => {});
        render(Show, { props: baseProps });

        const userSelect = screen.getByLabelText("メンバー");
        await fireEvent.change(userSelect, { target: { value: "4" } });
        await fireEvent.submit(screen.getByTestId("project-member-add-form"));

        expect(postSpy).toHaveBeenCalledTimes(1);
        expect(postSpy.mock.calls[0][0]).toBe("/projects/1/members");
        expect(postSpy.mock.calls[0][1]).toMatchObject({ user_id: "4" });
    });

    it("候補未選択で送信すると router.post は呼ばれず field error を表示する (disabled 不使用)", async () => {
        const postSpy = vi.spyOn(router, "post").mockImplementation(() => {});
        render(Show, { props: baseProps });

        const submit = screen.getByTestId("project-member-submit");
        expect(submit).not.toBeDisabled();
        await fireEvent.submit(screen.getByTestId("project-member-add-form"));

        expect(postSpy).not.toHaveBeenCalled();
        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();
    });

    it("明示メンバーの role select を変更すると router.post が upsert payload で発火する", async () => {
        const postSpy = vi.spyOn(router, "post").mockImplementation(() => {});
        render(Show, { props: baseProps });

        await fireEvent.change(screen.getByTestId("project-member-role-2"), {
            target: { value: "project_member" },
        });

        expect(postSpy).toHaveBeenCalledTimes(1);
        expect(postSpy.mock.calls[0][0]).toBe("/projects/1/members");
        expect(postSpy.mock.calls[0][1]).toEqual({ user_id: 2, role: "project_member" });
    });

    it("ロール変更処理中は次のロール変更を受け付けない (二重送信ガード)", async () => {
        // router.post は onFinish を発火させない mock なので changingRoleId が張られたまま。
        // 連続 change しても 1 回しか送信されないこと (in-flight ロックの退行検知) を固定する。
        const postSpy = vi.spyOn(router, "post").mockImplementation(() => {});
        render(Show, { props: baseProps });

        await fireEvent.change(screen.getByTestId("project-member-role-2"), {
            target: { value: "project_member" },
        });
        await fireEvent.change(screen.getByTestId("project-member-role-3"), {
            target: { value: "project_admin" },
        });

        expect(postSpy).toHaveBeenCalledTimes(1);
        expect(postSpy.mock.calls[0][1]).toEqual({ user_id: 2, role: "project_member" });
    });

    it("削除ボタン → 確認ダイアログ確定で router.delete が対象 URL で発火する", async () => {
        const deleteSpy = vi.spyOn(router, "delete").mockImplementation(() => {});
        render(Show, { props: baseProps });

        await fireEvent.click(screen.getByTestId("project-member-remove-2"));
        const dialog = screen.getByTestId("project-member-remove-dialog");
        await fireEvent.click(within(dialog).getByRole("button", { name: "削除する" }));

        expect(deleteSpy).toHaveBeenCalledTimes(1);
        expect(deleteSpy.mock.calls[0][0]).toBe("/projects/1/members/2");
    });

    it("canViewMemberEmails=false かつ email=null なら member email を描画しない", () => {
        render(Show, {
            props: {
                ...baseProps,
                canViewMemberEmails: false,
                members: baseProps.members.map((member) => ({ ...member, email: null })),
            },
        });

        expect(screen.queryByText("editor@example.com")).toBeNull();
        expect(screen.queryByText("shooter@example.com")).toBeNull();
    });

    it("assignableUsers が空なら候補なし案内 (canManageMembers ではユーザー管理リンク) を出す", () => {
        render(Show, { props: { ...baseProps, assignableUsers: [] } });

        const note = screen.getByTestId("project-member-no-candidates");
        expect(note).toBeInTheDocument();
        expect(within(note).getByRole("link", { name: "ユーザー管理" }).getAttribute("href")).toMatch(
            /\/manage\/users$/,
        );
    });

    it("members が空のときは EmptyState を表示する", () => {
        render(Show, { props: { ...baseProps, members: [] } });

        expect(screen.getByTestId("project-members-empty")).toBeInTheDocument();
        expect(screen.queryByTestId("project-member-list")).toBeNull();
    });
});

describe("Projects/Show メンバー追加の client error 自動解消 (T044)", () => {
    // 有効候補 2 人 (A id:4 / B id:5)。A→B の切替で $effect のクリア分岐を通す。
    const multiCandidateProps = {
        ...baseProps,
        assignableUsers: [
            { id: 4, name: "候補 四郎" },
            { id: 5, name: "候補 五郎" },
        ],
    };

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("未選択で押下→エラー表示後、候補を選ぶと client error と aria-invalid が自動解消する", async () => {
        vi.spyOn(router, "post").mockImplementation(() => {});
        render(Show, { props: multiCandidateProps });

        await fireEvent.submit(screen.getByTestId("project-member-add-form"));
        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();
        const select = screen.getByLabelText("メンバー");
        expect(select).toHaveAttribute("aria-invalid", "true");

        await fireEvent.change(select, { target: { value: "4" } });

        await waitFor(() => {
            expect(screen.queryByText("追加するメンバーを選択してください。")).toBeNull();
        });
        expect(select).not.toHaveAttribute("aria-invalid");
    });

    it("未選択のまま維持なら client error は残留する (過剰クリア防止)", async () => {
        vi.spyOn(router, "post").mockImplementation(() => {});
        render(Show, { props: multiCandidateProps });

        await fireEvent.submit(screen.getByTestId("project-member-add-form"));
        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();

        const select = screen.getByLabelText("メンバー");
        await fireEvent.change(select, { target: { value: "" } });

        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();
        expect(select).toHaveAttribute("aria-invalid", "true");
    });

    it("client error の自動クリアは serverErrors を破壊せず、背後のサーバエラーが再表示される (非退行)", async () => {
        const serverMsg = "サーバ由来: 追加できません";
        vi.spyOn(router, "post").mockImplementation(
            (_url, _data, opts) => {
                (opts as { onError?: (e: Record<string, string>) => void } | undefined)?.onError?.(
                    { user_id: serverMsg },
                );
            },
        );
        render(Show, { props: multiCandidateProps });

        const select = screen.getByLabelText("メンバー");

        // 1. 有効候補 A を選択 → 追加 → サーバエラー表示 (onError 経路のため reset は発火せず選択値は残る)
        await fireEvent.change(select, { target: { value: "4" } });
        await fireEvent.submit(screen.getByTestId("project-member-add-form"));
        await waitFor(() => {
            expect(screen.getByText(serverMsg)).toBeInTheDocument();
        });

        // 2. 空選択に戻して送信 → client error がサーバエラーを一時的に覆う
        await fireEvent.change(select, { target: { value: "" } });
        await fireEvent.submit(screen.getByTestId("project-member-add-form"));
        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();
        expect(screen.queryByText(serverMsg)).toBeNull();

        // 3. 有効候補 B を選択 → $effect がクリア分岐を通り client error=null → サーバエラー再表示
        await fireEvent.change(select, { target: { value: "5" } });
        await waitFor(() => {
            expect(screen.queryByText("追加するメンバーを選択してください。")).toBeNull();
        });
        expect(screen.getByText(serverMsg)).toBeInTheDocument();
    });
});
