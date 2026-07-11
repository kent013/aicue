import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import NotificationBell from "@/components/molecules/NotificationBell.svelte";

describe("NotificationBell", () => {
    it("/notifications への link (a 要素) を描画する", () => {
        render(NotificationBell, { props: { unreadCount: 0 } });

        const link = screen.getByTestId("notification-bell");
        expect(link.tagName).toBe("A");
        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/notifications",
        );
        expect(link).toHaveAccessibleName("通知");
    });

    it("unreadCount=0 でバッジ非表示", () => {
        render(NotificationBell, { props: { unreadCount: 0 } });

        expect(screen.queryByTestId("unread-badge")).toBeNull();
    });

    it("unreadCount=5 でバッジに 5 を表示", () => {
        render(NotificationBell, { props: { unreadCount: 5 } });

        expect(screen.getByTestId("unread-badge")).toHaveTextContent("5");
    });

    it("unreadCount=100 で 99+ に打ち切る", () => {
        render(NotificationBell, { props: { unreadCount: 100 } });

        expect(screen.getByTestId("unread-badge")).toHaveTextContent("99+");
    });

    it("disabled 属性を一切持たない (必須未充足 disabled UI 禁止)", () => {
        render(NotificationBell, { props: { unreadCount: 0 } });

        expect(screen.getByTestId("notification-bell")).not.toHaveAttribute("disabled");
    });
});
