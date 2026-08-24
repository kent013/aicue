import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import NotificationListItem from "@/components/features/notifications/NotificationListItem.svelte";
import type { NotificationItem } from "@/types/notification";

/** router.post に渡す visit options のうち本テストで発火させるコールバック部分 */
interface ReadVisitOptions {
    preserveScroll?: boolean;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void | Promise<void>;
}

// 行クリックの POST (open) / 個別既読 (read) は router をモックして検証する
const { routerPostMock } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
}));

// 既読失敗時の toast は addToast をモックして検証する
const { addToastMock } = vi.hoisted(() => ({
    addToastMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: {
        post: routerPostMock,
    },
}));

vi.mock("@/lib/stores/toast", () => ({
    addToast: addToastMock,
}));

/** router.post mock に渡された最後の read visit options を取り出す (複数 read でも末尾を返す) */
function lastReadOptions(): ReadVisitOptions {
    const call = [...routerPostMock.mock.calls]
        .reverse()
        .find((c) => String(c[0]).endsWith("/read"));
    if (!call) throw new Error("read POST が発火していません");
    return call[2] as ReadVisitOptions;
}

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
    addToastMock.mockReset();
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

    it("行クリックで POST /organizations/test-org/notifications/{id}/open (サーバ解決の遷移)", async () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        await fireEvent.click(screen.getByTestId("notification-item"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        expect(routerPostMock.mock.calls[0][0]).toBe(
            "/organizations/test-org/notifications/11111111-1111-1111-1111-111111111111/open",
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

    it("invitation_received: 招待文言とアプリ内受諾への案内", () => {
        render(NotificationListItem, {
            props: {
                notification: manualAnalyzedItem({
                    type: "invitation_received",
                    payload: { organization_name: "招待元組織" },
                }),
            },
        });

        expect(screen.getByText("招待元組織 に招待されています")).toBeInTheDocument();
        expect(
            screen.getByText("クリックすると、届いている招待から参加できます"),
        ).toBeInTheDocument();
    });

    it("未読行には個別既読ボタンを表示する", () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
        expect(screen.getByTestId("notification-read-button")).toBeInTheDocument();
    });

    it("既読行 (read_at 非 null) には個別既読ボタンを表示しない", () => {
        render(NotificationListItem, {
            props: { notification: manualAnalyzedItem({ read_at: new Date().toISOString() }) },
        });
        expect(screen.queryByTestId("notification-read-button")).toBeNull();
    });

    it("既読ボタン押下で POST /organizations/test-org/notifications/{id}/read が preserveScroll + 各コールバック付きで 1 回発火し、open は呼ばれない", async () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        await fireEvent.click(screen.getByTestId("notification-read-button"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        const [url, payload, options] = routerPostMock.mock.calls[0];
        expect(url).toBe("/organizations/test-org/notifications/11111111-1111-1111-1111-111111111111/read");
        expect(payload).toEqual({});
        expect(options).toMatchObject({
            preserveScroll: true,
            onSuccess: expect.any(Function),
            onError: expect.any(Function),
            onFinish: expect.any(Function),
        });
        // 遷移しない = open URL は呼ばれない
        expect(routerPostMock.mock.calls.some((c) => String(c[0]).endsWith("/open"))).toBe(false);
    });

    it("既読成功 (onSuccess+onFinish) で該当行が既読表示になり、read ボタンが消え、フォーカスが open ボタンへ移る", async () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        await fireEvent.click(screen.getByTestId("notification-read-button"));
        const options = lastReadOptions();
        options.onSuccess?.();
        await options.onFinish?.();

        await waitFor(() => {
            expect(screen.getByTestId("notification-item")).toHaveAttribute("data-unread", "false");
        });
        expect(screen.queryByTestId("unread-dot")).toBeNull();
        expect(screen.queryByTestId("notification-read-button")).toBeNull();
        expect(document.activeElement).toBe(screen.getByTestId("notification-item"));
    });

    it("既読失敗 (onError) で addToast('error', ...) が呼ばれ、行は未読のまま", async () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        await fireEvent.click(screen.getByTestId("notification-read-button"));
        const options = lastReadOptions();
        options.onError?.();
        await options.onFinish?.();

        expect(addToastMock).toHaveBeenCalledWith("error", expect.stringContaining("既読にできませんでした"));
        await waitFor(() => {
            expect(screen.getByTestId("notification-item")).toHaveAttribute("data-unread", "true");
        });
        // 再試行できるようボタンは残る
        expect(screen.getByTestId("notification-read-button")).toBeInTheDocument();
    });

    it("二重送信防止: コールバック未発火のまま既読ボタンを 2 回押しても read POST は 1 回のみ", async () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        const button = screen.getByTestId("notification-read-button");
        await fireEvent.click(button);
        await fireEvent.click(button);

        expect(routerPostMock).toHaveBeenCalledTimes(1);
    });

    it("open/read 相互排他: open が in-flight (コールバック未発火) の間に既読を押しても追加 POST は発生しない", async () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        await fireEvent.click(screen.getByTestId("notification-item")); // open in-flight
        await fireEvent.click(screen.getByTestId("notification-read-button"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        expect(routerPostMock.mock.calls[0][0]).toBe(
            "/organizations/test-org/notifications/11111111-1111-1111-1111-111111111111/open",
        );
    });

    it("account_deletion_requested: 退会予約の文言と削除予定日を出す (T142)", () => {
        render(NotificationListItem, {
            props: {
                notification: manualAnalyzedItem({
                    type: "account_deletion_requested",
                    payload: { purge_after: "2026-09-09T09:00:00+09:00", grace_days: 30 },
                }),
            },
        });

        expect(screen.getByText("退会のお手続きを受け付けました")).toBeInTheDocument();
        // 未知 type の fallback (rawType 表示) に落ちていないこと
        expect(screen.queryByText("account_deletion_requested")).toBeNull();
        expect(screen.getByText(/2026/)).toBeInTheDocument();
        expect(screen.getByText(/取り消せます/)).toBeInTheDocument();
    });

    it("排他 (逆方向): open (行) クリックで read URL は呼ばれない", async () => {
        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });

        await fireEvent.click(screen.getByTestId("notification-item"));

        expect(routerPostMock.mock.calls.some((c) => String(c[0]).endsWith("/read"))).toBe(false);
    });
});
