import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import PendingInvitationList from "@/components/features/invitations/PendingInvitationList.svelte";
import type { PendingInvitation } from "@/types/invitation";

// 受諾は router.post をモックして検証する (サーバは 302 で dashboard へ着地させる)
const { routerPostMock } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: {
        post: routerPostMock,
    },
}));

function invitation(overrides: Partial<PendingInvitation> = {}): PendingInvitation {
    return {
        id: 12,
        organizationName: "テスト組織",
        roleLabel: "メンバー",
        expiresAt: "2026-09-30",
        ...overrides,
    };
}

describe("PendingInvitationList", () => {
    beforeEach(() => {
        routerPostMock.mockReset();
    });

    it("招待 0 件では何も描画しない", () => {
        render(PendingInvitationList, { props: { invitations: [] } });

        expect(screen.queryByTestId("pending-invitation-list")).toBeNull();
    });

    it("組織名・ロール・期限・参加ボタンを描画する", () => {
        render(PendingInvitationList, { props: { invitations: [invitation()] } });

        expect(screen.getByTestId("pending-invitation-list")).toBeInTheDocument();
        expect(screen.getByText("テスト組織")).toBeInTheDocument();
        expect(screen.getByText("メンバー")).toBeInTheDocument();
        expect(screen.getByText("期限 2026-09-30")).toBeInTheDocument();
        expect(screen.getByTestId("accept-invitation-12")).toHaveTextContent("参加する");
    });

    it("初期描画では参加ボタンが disabled 属性を持たない (禁止事項 8)", () => {
        render(PendingInvitationList, { props: { invitations: [invitation()] } });

        expect(screen.getByTestId("accept-invitation-12")).not.toHaveAttribute("disabled");
    });

    it("参加ボタン押下で POST /invitations/{id}/accept-in-app を送る", async () => {
        render(PendingInvitationList, { props: { invitations: [invitation()] } });

        await fireEvent.click(screen.getByTestId("accept-invitation-12"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        expect(routerPostMock.mock.calls[0]?.[0]).toBe("/invitations/12/accept-in-app");
    });

    it("in-flight 中の 2 回目の押下は送信しない (二重送信ガード)", async () => {
        render(PendingInvitationList, { props: { invitations: [invitation()] } });

        const button = screen.getByTestId("accept-invitation-12");
        await fireEvent.click(button);
        await fireEvent.click(button);

        expect(routerPostMock).toHaveBeenCalledTimes(1);
    });
});
