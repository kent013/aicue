import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import NotificationListItem from "@/components/features/notifications/NotificationListItem.svelte";
import type { NotificationItem } from "@/types/notification";

// 行クリックの POST (open) は router をモックして検証する
const { routerPostMock } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: {
        post: routerPostMock,
    },
}));

function manualAnalyzedItem(overrides: Partial<NotificationItem> = {}): NotificationItem {
    return {
        id: "11111111-1111-1111-1111-111111111111",
        type: "manual_analyzed",
        organization_id: 1,
        read_at: null,
        created_at: new Date().toISOString(),
        payload: {
            project_id: 1,
            manual_id: 2,
            manual_title: "ネジ締め手順",
            organization_name: "テスト組織",
            succeeded: true,
            error: null,
        },
        ...overrides,
    };
}

beforeEach(() => {
    routerPostMock.mockReset();
});

describe("NotificationListItem", () => {
    it("manual_analyzed 成功: 完了文言 + manual タイトル + org バッジ", () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        expect(screen.getByText("AI 解析が完了しました")).toBeInTheDocument();
        expect(screen.getByText("ネジ締め手順")).toBeInTheDocument();
        expect(screen.getByText("テスト組織")).toBeInTheDocument();
    });

    it("manual_analyzed 失敗: 失敗文言 + error 本文", () => {
        const item = manualAnalyzedItem();
        render(NotificationListItem, {
            props: {
                notification: {
                    ...item,
                    payload: {
                        ...(item.payload as object),
                        succeeded: false,
                        error: "解析に失敗しました。",
                    } as NotificationItem["payload"],
                },
            },
        });

        expect(screen.getByText("AI 解析に失敗しました")).toBeInTheDocument();
        expect(screen.getByText(/解析に失敗しました。/)).toBeInTheDocument();
    });

    it("未読はハイライト (data-unread=true + 未読ドット)、既読はドットなし", () => {
        const { unmount } = render(NotificationListItem, {
            props: { notification: manualAnalyzedItem() },
        });
        expect(screen.getByTestId("notification-item")).toHaveAttribute("data-unread", "true");
        expect(screen.getByTestId("unread-dot")).toBeInTheDocument();
        unmount();

        render(NotificationListItem, {
            props: {
                notification: manualAnalyzedItem({ read_at: new Date().toISOString() }),
            },
        });
        expect(screen.getByTestId("notification-item")).toHaveAttribute("data-unread", "false");
        expect(screen.queryByTestId("unread-dot")).toBeNull();
    });

    it("行クリックで POST /notifications/{id}/open (サーバ解決の遷移)", async () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        await fireEvent.click(screen.getByTestId("notification-item"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        expect(routerPostMock.mock.calls[0][0]).toBe(
            "/notifications/11111111-1111-1111-1111-111111111111/open",
        );
    });

    it("未知 type は rawType 表示の fallback で描画され、クリックも可能 (disabled にしない)", async () => {
        render(NotificationListItem, {
            props: {
                notification: manualAnalyzedItem({
                    type: "future_unknown_type",
                    payload: null,
                }),
            },
        });

        expect(screen.getByText("future_unknown_type")).toBeInTheDocument();
        const row = screen.getByTestId("notification-item");
        expect(row).not.toHaveAttribute("disabled");
        await fireEvent.click(row);
        expect(routerPostMock).toHaveBeenCalledTimes(1);
    });

    it("ticket_balance_low: 残高と閾値を表示", () => {
        render(NotificationListItem, {
            props: {
                notification: manualAnalyzedItem({
                    type: "ticket_balance_low",
                    payload: { organization_name: "テスト組織", balance: 3, threshold: 5 },
                }),
            },
        });

        expect(screen.getByText("チケット残高が残り 3 枚になりました")).toBeInTheDocument();
        expect(screen.getByText(/5 枚/)).toBeInTheDocument();
    });

    it("invitation_received: 招待文言とメール案内", () => {
        render(NotificationListItem, {
            props: {
                notification: manualAnalyzedItem({
                    type: "invitation_received",
                    payload: { organization_name: "招待元組織" },
                }),
            },
        });

        expect(screen.getByText("招待元組織 に招待されています")).toBeInTheDocument();
        expect(screen.getByText("メールの受諾リンクから参加してください")).toBeInTheDocument();
    });
});
