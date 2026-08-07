import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import PendingInvitationsNotice from "@/components/molecules/PendingInvitationsNotice.svelte";

describe("PendingInvitationsNotice", () => {
    it("pendingCount=0 では描画しない", () => {
        render(PendingInvitationsNotice, { props: { pendingCount: 0 } });

        expect(screen.queryByTestId("pending-invitations-notice")).toBeNull();
    });

    it("pendingCount=3 で件数と /notifications への link を描画する", () => {
        render(PendingInvitationsNotice, { props: { pendingCount: 3 } });

        const link = screen.getByTestId("pending-invitations-notice");
        expect(link.tagName).toBe("A");
        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/notifications",
        );
        expect(link).toHaveTextContent("あなた宛の招待が 3 件あります");
    });

    it("disabled 属性を持たない (必須未充足 disabled UI 禁止)", () => {
        render(PendingInvitationsNotice, { props: { pendingCount: 1 } });

        expect(screen.getByTestId("pending-invitations-notice")).not.toHaveAttribute("disabled");
    });
});
