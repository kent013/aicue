import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import NotificationBell from "@/components/molecules/NotificationBell.svelte";

describe("NotificationBell", () => {
    it("親が渡した組織配下の通知 URL への link (a 要素) を描画する", () => {
        render(NotificationBell, { props: { unreadCount: 0, href: "/organizations/test-org/notifications" } });

        const link = screen.getByTestId("notification-bell");
        expect(link.tagName).toBe("A");
        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/organizations/test-org/notifications",
        );
        expect(link).toHaveAccessibleName("通知");
    });

    it("unreadCount=0 でバッジ非表示", () => {
        render(NotificationBell, { props: { unreadCount: 0, href: "/organizations/test-org/notifications" } });

        expect(screen.queryByTestId("unread-badge")).toBeNull();
    });

    it("unreadCount=5 でバッジに 5 を表示", () => {
        render(NotificationBell, { props: { unreadCount: 5, href: "/organizations/test-org/notifications" } });

        expect(screen.getByTestId("unread-badge")).toHaveTextContent("5");
    });

    it("unreadCount=100 で 99+ に打ち切る", () => {
        render(NotificationBell, { props: { unreadCount: 100, href: "/organizations/test-org/notifications" } });

        expect(screen.getByTestId("unread-badge")).toHaveTextContent("99+");
    });

    it("disabled 属性を一切持たない (必須未充足 disabled UI 禁止)", () => {
        render(NotificationBell, { props: { unreadCount: 0, href: "/organizations/test-org/notifications" } });

        expect(screen.getByTestId("notification-bell")).not.toHaveAttribute("disabled");
    });
});
