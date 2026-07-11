import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Users from "@/pages/Admin/Users.svelte";
import type { InvitationRow, MemberRow } from "@/types/admin";

const membersFixture: MemberRow[] = [
    {
        id: 1,
        name: "オーナー 太郎",
        email: "owner@example.com",
        roleState: "owner",
        roleLabel: "管理者（オーナー）",
        twoFactorStatus: "enabled",
        isSelf: true,
    },
    {
        id: 2,
        name: "編集 花子",
        email: "editor@example.com",
        roleState: "editor",
        roleLabel: "編集者",
        twoFactorStatus: "enabled",
        isSelf: false,
    },
    {
        id: 3,
        name: "未割当 次郎",
        email: "unassigned@example.com",
        roleState: "unassigned",
        roleLabel: "未割当",
        twoFactorStatus: "disabled",
        isSelf: false,
    },
];

const invitationsFixture: InvitationRow[] = [
    {
        id: 10,
        email: "invited@example.com",
        roleState: "shooter",
        roleLabel: "撮影者",
        expiresAt: "2026-07-18",
    },
];

const baseProps = {
    organizationSlug: "test-org",
    members: membersFixture,
    invitations: invitationsFixture,
    hasDefaultProject: true,
    categoriesUrl: "/projects/1/categories",
};

describe("Admin/Users", () => {
    it("メンバー一覧・招待中一覧・追加フォームを描画する", () => {
        render(Users, { props: baseProps });

        expect(screen.getByRole("heading", { name: "ユーザー管理" })).toBeInTheDocument();
        expect(screen.getByTestId("member-list")).toBeInTheDocument();
        expect(screen.getByText("owner@example.com")).toBeInTheDocument();
        expect(screen.getByText("editor@example.com")).toBeInTheDocument();
        expect(screen.getByTestId("invitation-list")).toBeInTheDocument();
        expect(screen.getByText("invited@example.com")).toBeInTheDocument();
        expect(screen.getByLabelText("メールアドレス")).toBeInTheDocument();
        expect(screen.getByTestId("invite-submit")).toBeInTheDocument();
    });

    it("owner 行と自分の行にはロール select を出さずラベル表示する", () => {
        render(Users, { props: baseProps });

        // id=1 は owner かつ self → select なし・roleLabel テキスト
        expect(screen.queryByTestId("member-role-1")).toBeNull();
        expect(screen.getByText("管理者（オーナー）")).toBeInTheDocument();
        // 他メンバーには select と削除ボタンが出る
        expect(screen.getByTestId("member-role-2")).toBeInTheDocument();
        expect(screen.getByTestId("remove-member-2")).toBeInTheDocument();
    });

    it("未割当行は警告バッジと空 option (未割当) を表示する", () => {
        render(Users, { props: baseProps });

        expect(screen.getByTestId("unassigned-3")).toBeInTheDocument();
        const select = screen.getByTestId("member-role-3");
        expect(select).toHaveValue("");
        expect(
            screen.getByRole("option", { name: "未割当（選択してください）" }),
        ).toBeInTheDocument();
    });

    it("ロール select の選択肢は遷移コマンド 3 値 (owner は選べない)", () => {
        render(Users, { props: baseProps });

        const select = screen.getByTestId("member-role-2");
        const options = Array.from(select.querySelectorAll("option")).map(
            (option) => option.textContent,
        );
        expect(options).toEqual(["管理者", "編集者", "撮影者"]);
    });

    it("必須未充足でもボタンは disabled にしない (押下時エラー方針)", () => {
        render(Users, { props: baseProps });

        expect(screen.getByTestId("invite-submit")).not.toBeDisabled();
        expect(screen.getByTestId("member-role-2")).not.toBeDisabled();
        expect(screen.getByTestId("remove-member-2")).not.toBeDisabled();
    });

    it("2FA 設定済み・非同格メンバーには 2FA 解除ボタンを出す (owner 閲覧)", () => {
        render(Users, { props: baseProps });

        // editor (enabled) → 出る / unassigned (disabled) → 出ない / self → 出ない
        expect(screen.getByTestId("reset-two-factor-2")).toBeInTheDocument();
        expect(screen.queryByTestId("reset-two-factor-3")).toBeNull();
        expect(screen.queryByTestId("reset-two-factor-1")).toBeNull();
    });

    it("admin 閲覧者は同格 (admin) の 2FA 解除ボタンを出さない", () => {
        render(Users, {
            props: {
                ...baseProps,
                members: [
                    {
                        id: 1,
                        name: "管理 太郎",
                        email: "admin-self@example.com",
                        roleState: "admin",
                        roleLabel: "管理者",
                        twoFactorStatus: "enabled",
                        isSelf: true,
                    },
                    {
                        id: 2,
                        name: "別管理 花子",
                        email: "other-admin@example.com",
                        roleState: "admin",
                        roleLabel: "管理者",
                        twoFactorStatus: "enabled",
                        isSelf: false,
                    },
                    {
                        id: 3,
                        name: "撮影 三郎",
                        email: "shooter@example.com",
                        roleState: "shooter",
                        roleLabel: "撮影者",
                        twoFactorStatus: "enabled",
                        isSelf: false,
                    },
                ] satisfies MemberRow[],
            },
        });

        expect(screen.queryByTestId("reset-two-factor-2")).toBeNull();
        expect(screen.getByTestId("reset-two-factor-3")).toBeInTheDocument();
    });

    it("招待が空のときは EmptyState を表示する", () => {
        render(Users, { props: { ...baseProps, invitations: [] } });

        expect(screen.getByTestId("invitations-empty")).toBeInTheDocument();
        expect(screen.queryByTestId("invitation-list")).toBeNull();
    });

    it("project 不在時は案内文を出し、カテゴリ管理 nav 項目は非表示 (URL null)", () => {
        render(Users, {
            props: { ...baseProps, hasDefaultProject: false, categoriesUrl: null },
        });

        expect(screen.getByTestId("no-project-note")).toBeInTheDocument();
        expect(screen.queryByTestId("admin-nav-categories")).toBeNull();
    });

    it("削除 ConfirmDialog はメンバー名入りの警告文言を持つ", async () => {
        const { component: _ } = render(Users, { props: baseProps });

        const removeButton = screen.getByTestId("remove-member-2");
        removeButton.click();
        // ConfirmDialog は open で描画される
        const dialog = await screen.findByTestId("remove-member-dialog");
        expect(dialog).toBeInTheDocument();
        expect(dialog.textContent).toContain("編集 花子");
        expect(dialog.textContent).toContain("削除しますか");
    });
});
