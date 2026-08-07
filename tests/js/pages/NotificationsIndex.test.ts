import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import NotificationsIndex from "@/pages/Notifications/Index.svelte";
import type { PendingInvitation } from "@/types/invitation";
import type { NotificationItem } from "@/types/notification";
import type { PaginationMeta } from "@/types/manual";

/** Index.svelte の Props (unreadCount / pendingInvitations 必須)。全 render はこの型で統一する */
interface IndexProps {
    notifications: NotificationItem[];
    meta: PaginationMeta;
    unreadCount: number;
    pendingInvitations: PendingInvitation[];
}

// router をモックし page state は実物を使う (props 未設定の空オブジェクト)
const { routerMock } = vi.hoisted(() => ({
    routerMock: { post: vi.fn(), get: vi.fn() },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: routerMock,
}));

const meta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };

/** 全 render の共通 props。必須 prop 追加時の追従漏れを防ぐ (デフォルト空) */
function baseProps(overrides: Partial<IndexProps> = {}): IndexProps {
    return { notifications: [], meta, unreadCount: 0, pendingInvitations: [], ...overrides };
}

function item(id: string): NotificationItem {
    return {
        id,
        type: "manual_analyzed",
        organization_id: 1,
        read_at: null,
        created_at: new Date().toISOString(),
        payload: {
            project_id: 1,
            manual_id: 2,
            manual_title: "手順書",
            organization_name: "組織",
            succeeded: true,
            error: null,
        },
    };
}

afterEach(() => {
    cleanup();
    routerMock.post.mockReset();
    routerMock.get.mockReset();
});

describe("Notifications/Index", () => {
    it("0 件時は EmptyState を表示する", () => {
        render(NotificationsIndex, { props: baseProps() });

        expect(screen.getByTestId("notifications-empty")).toBeInTheDocument();
        expect(screen.queryByTestId("notification-list")).toBeNull();
    });

    it("未読あり時、read-all ボタンは disabled でなく、押下で POST /notifications/read-all", async () => {
        render(NotificationsIndex, { props: baseProps({ unreadCount: 1 }) });

        const button = screen.getByTestId("read-all-button");
        expect(button).not.toHaveAttribute("disabled");
        await fireEvent.click(button);

        expect(routerMock.post).toHaveBeenCalledTimes(1);
        expect(routerMock.post.mock.calls[0][0]).toBe("/notifications/read-all");
    });

    it("未読 0 件なら read-all ボタンを描画しない", () => {
        render(NotificationsIndex, { props: baseProps({ unreadCount: 0 }) });

        expect(screen.queryByTestId("read-all-button")).toBeNull();
        expect(screen.queryByRole("button", { name: "すべて既読にする" })).toBeNull();
    });

    it("未読ありなら read-all ボタンを描画する", () => {
        render(NotificationsIndex, { props: baseProps({ unreadCount: 3 }) });

        expect(screen.getByTestId("read-all-button")).toBeInTheDocument();
    });

    it("通知がある場合は一覧を描画する", () => {
        render(NotificationsIndex, {
            props: baseProps({
                notifications: [item("a"), item("b")],
                meta: { ...meta, total: 2 },
                unreadCount: 2,
            }),
        });

        expect(screen.getByTestId("notification-list")).toBeInTheDocument();
        expect(screen.getAllByTestId("notification-item")).toHaveLength(2);
    });
});

describe("Notifications/Index 届いている招待", () => {
    afterEach(() => cleanup());

    it("pendingInvitations が空なら招待セクションを描画しない", () => {
        render(NotificationsIndex, { props: baseProps() });

        expect(screen.queryByTestId("pending-invitation-list")).toBeNull();
    });

    it("pendingInvitations があれば招待セクションを描画する", () => {
        render(NotificationsIndex, {
            props: baseProps({
                pendingInvitations: [
                    {
                        id: 7,
                        organizationName: "招待元組織",
                        roleLabel: "メンバー",
                        expiresAt: "2026-09-30",
                    },
                ],
            }),
        });

        expect(screen.getByTestId("pending-invitation-list")).toBeInTheDocument();
        expect(screen.getByTestId("accept-invitation-7")).toBeInTheDocument();
    });
});
