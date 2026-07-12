import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
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

describe("Organizations/Settings オーナー移譲の常時表示 (F-12)", () => {
    // 自分 (id:1) しかいない組織 = 移譲候補 0 人。
    // 実運用では members に自分が必ず含まれる (controller は全メンバーを返す) が、
    // 本テストは page 未モックで myId=null のため members: [] で候補 0 人を表現する
    // (どちらでも transferCandidates.length === 0 の同一分岐)。
    const soloProps = { ...baseProps, members: [] };

    it("候補 0 人でもオーナーにはセクションと案内文が表示される", () => {
        render(Settings, { props: soloProps });

        expect(screen.getByRole("heading", { name: "オーナー移譲" })).toBeInTheDocument();
        expect(screen.getByTestId("transfer-no-candidates")).toBeInTheDocument();
        expect(screen.getByTestId("transfer-no-candidates")).toHaveTextContent("ユーザー管理");
        const button = screen.getByTestId("transfer-ownership-button");
        expect(button).toBeInTheDocument();
        expect(button).not.toBeDisabled();
    });

    it("候補 0 人で押下すると確認ダイアログを開かずエラーを表示する", async () => {
        render(Settings, { props: soloProps });

        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));

        expect(
            screen.getByText(
                "移譲先にできるメンバーがいません。先にメンバーを招待してください。",
            ),
        ).toBeInTheDocument();
        // ConfirmDialog (Modal) は開いていない
        expect(screen.queryByRole("button", { name: "移譲する" })).toBeNull();
    });

    it("未選択のまま押下すると確認ダイアログを開かず選択エラーを表示する", async () => {
        render(Settings, { props: baseProps });

        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));

        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "移譲する" })).toBeNull();
    });

    it("非オーナーにはオーナー移譲セクションを表示しない", () => {
        render(Settings, {
            props: { ...baseProps, currentUserRole: "organization_admin" },
        });

        expect(screen.queryByTestId("transfer-ownership-button")).toBeNull();
        expect(screen.queryByRole("heading", { name: "オーナー移譲" })).toBeNull();
    });
});
