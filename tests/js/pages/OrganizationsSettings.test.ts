import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Settings from "@/pages/Organizations/Settings.svelte";

const baseProps = {
    organization: {
        id: 1,
        name: "テスト組織",
        slug: "test-org",
        isPersonal: false,
        twoFactorRequired: false,
    },
    // オーナー移譲 select 用の最小 shape (id/name)。email / 2FA は Admin/Users へ移設済み
    members: [
        { id: 1, name: "オーナー 太郎" },
        { id: 2, name: "メンバー 花子" },
    ],
    currentUserRole: "organization_owner",
    canManageApiKeys: true,
    usersUrl: "/manage/users",
};

describe("Organizations/Settings", () => {
    it("組織名フォームを描画する", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByRole("heading", { name: "組織設定" })).toBeInTheDocument();
        expect(screen.getByLabelText("組織名")).toBeInTheDocument();
    });

    it("メンバー管理 UI は存在しない (Admin/Users へ移設済み = 旧 UI 並走の回帰封じ)", () => {
        render(Settings, { props: baseProps });

        expect(screen.queryByTestId("member-list")).toBeNull();
        expect(screen.queryByTestId("invite-submit")).toBeNull();
        expect(screen.queryByRole("heading", { name: "メンバーを招待" })).toBeNull();
        expect(screen.queryByTestId("reset-two-factor-2")).toBeNull();
        expect(screen.queryByTestId("member-role-2")).toBeNull();
        expect(screen.queryByTestId("remove-member-2")).toBeNull();
    });

    it("manageMembers 権限者にはユーザー管理画面への導線リンクを出す", () => {
        render(Settings, { props: baseProps });

        const link = screen.getByTestId("link-manage-users");
        expect(link).toBeInTheDocument();
        expect(link.getAttribute("href")).toMatch(/\/manage\/users$/);
    });

    it("usersUrl が null (権限なし) ならユーザー管理導線を出さない", () => {
        render(Settings, {
            props: {
                ...baseProps,
                currentUserRole: "organization_member",
                canManageApiKeys: false,
                usersUrl: null,
            },
        });

        expect(screen.queryByTestId("link-manage-users")).toBeNull();
        expect(screen.queryByLabelText("組織名")).toBeNull();
        expect(screen.queryByTestId("link-api-keys")).toBeNull();
        expect(screen.queryByTestId("toggle-two-factor-requirement")).toBeNull();
    });

    it("owner には 2FA 必須化トグルが表示される", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByTestId("toggle-two-factor-requirement")).toBeInTheDocument();
    });

    it("オーナー移譲 select は members (id/name) で動く", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByTestId("transfer-ownership-button")).toBeInTheDocument();
        expect(screen.getByLabelText("移譲先のメンバー")).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "メンバー 花子" })).toBeInTheDocument();
    });

    it("canManageApiKeys の場合は API キー / 接続セッション管理画面への導線を出す", () => {
        render(Settings, { props: baseProps });

        const link = screen.getByTestId("link-api-keys");
        expect(link).toBeInTheDocument();
        // Inertia Link は jsdom で絶対 URL に解決するため末尾一致で検証する
        expect(link.getAttribute("href")).toMatch(/\/organizations\/test-org\/api-keys$/);
    });
});
