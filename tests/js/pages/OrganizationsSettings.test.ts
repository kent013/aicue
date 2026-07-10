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
    members: [
        {
            id: 1,
            name: "オーナー 太郎",
            email: "owner@example.com",
            role: "organization_owner",
            twoFactorStatus: "enabled" as const,
        },
        {
            id: 2,
            name: "メンバー 花子",
            email: "member@example.com",
            role: "organization_member",
            twoFactorStatus: "enabled" as const,
        },
    ],
    invitations: [],
    currentUserRole: "organization_owner",
    canManageApiKeys: true,
};

describe("Organizations/Settings", () => {
    it("組織名フォームとメンバー一覧を描画する", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByRole("heading", { name: "組織設定" })).toBeInTheDocument();
        expect(screen.getByLabelText("組織名")).toBeInTheDocument();
        expect(screen.getByTestId("member-list")).toBeInTheDocument();
        expect(screen.getByText("owner@example.com")).toBeInTheDocument();
        expect(screen.getByText("member@example.com")).toBeInTheDocument();
    });

    it("owner には招待フォーム (email + role) が表示される", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByRole("heading", { name: "メンバーを招待" })).toBeInTheDocument();
        expect(screen.getByLabelText("メールアドレス")).toBeInTheDocument();
        expect(screen.getByLabelText("ロール")).toBeInTheDocument();
        expect(screen.getByTestId("invite-submit")).toBeInTheDocument();
    });

    it("member ロールには招待フォームと管理操作を表示しない", () => {
        render(Settings, {
            props: {
                ...baseProps,
                currentUserRole: "organization_member",
                canManageApiKeys: false,
            },
        });

        expect(screen.queryByRole("heading", { name: "メンバーを招待" })).toBeNull();
        expect(screen.queryByLabelText("組織名")).toBeNull();
        expect(screen.queryByTestId("remove-member-2")).toBeNull();
        // API キー管理画面への導線は manageApiKeys 境界のメンバーには出さない
        expect(screen.queryByTestId("link-api-keys")).toBeNull();
        // 2FA 必須化トグル (owner 専権) とメンバー 2FA 解除も表示しない
        expect(screen.queryByTestId("toggle-two-factor-requirement")).toBeNull();
        expect(screen.queryByTestId("reset-two-factor-1")).toBeNull();
    });

    it("owner には 2FA 必須化トグルとメンバーの 2FA 解除ボタンが表示される", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByTestId("toggle-two-factor-requirement")).toBeInTheDocument();
        // 2FA 設定済み (enabled) のメンバーにのみ解除導線を出す
        expect(screen.getByTestId("reset-two-factor-2")).toBeInTheDocument();
    });

    it("2FA 未設定 (disabled) のメンバーには解除ボタンを出さない", () => {
        render(Settings, {
            props: {
                ...baseProps,
                members: [
                    {
                        id: 2,
                        name: "メンバー 花子",
                        email: "member@example.com",
                        role: "organization_member",
                        twoFactorStatus: "disabled" as const,
                    },
                ],
            },
        });

        expect(screen.queryByTestId("reset-two-factor-2")).toBeNull();
    });

    it("canManageApiKeys の場合は API キー / 接続セッション管理画面への導線を出す", () => {
        render(Settings, { props: baseProps });

        const link = screen.getByTestId("link-api-keys");
        expect(link).toBeInTheDocument();
        // Inertia Link は jsdom で絶対 URL に解決するため末尾一致で検証する
        expect(link.getAttribute("href")).toMatch(/\/organizations\/test-org\/api-keys$/);
    });
});
