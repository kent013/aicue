import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import NotificationsIndex from "@/pages/Notifications/Index.svelte";
import type { NotificationItem } from "@/types/notification";

// router をモックし page state は実物を使う (props 未設定の空オブジェクト)
const { routerMock } = vi.hoisted(() => ({
    routerMock: { post: vi.fn(), get: vi.fn() },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: routerMock,
}));

const meta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };

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
        render(NotificationsIndex, { props: { notifications: [], meta } });

        expect(screen.getByTestId("notifications-empty")).toBeInTheDocument();
        expect(screen.queryByTestId("notification-list")).toBeNull();
    });

    it("read-all ボタンは disabled でなく、押下で POST /notifications/read-all", async () => {
        render(NotificationsIndex, { props: { notifications: [], meta } });

        const button = screen.getByTestId("read-all-button");
        expect(button).not.toHaveAttribute("disabled");
        await fireEvent.click(button);

        expect(routerMock.post).toHaveBeenCalledTimes(1);
        expect(routerMock.post.mock.calls[0][0]).toBe("/notifications/read-all");
    });

    it("通知がある場合は一覧を描画する", () => {
        render(NotificationsIndex, {
            props: {
                notifications: [item("a"), item("b")],
                meta: { ...meta, total: 2 },
            },
        });

        expect(screen.getByTestId("notification-list")).toBeInTheDocument();
        expect(screen.getAllByTestId("notification-item")).toHaveLength(2);
    });
});
