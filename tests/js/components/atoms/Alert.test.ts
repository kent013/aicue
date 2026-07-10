import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import Alert from "@/components/atoms/Alert.svelte";

const children = createRawSnippet(() => ({
    render: () => "<p>本文メッセージ</p>",
}));

describe("Alert", () => {
    it("bg-surface + 状態色 border の box を rounded-md で描画する", () => {
        render(Alert, { props: { type: "success", children, testId: "alert" } });

        const alert = screen.getByTestId("alert");
        expect(alert.className).toContain("bg-surface");
        expect(alert.className).toContain("border-success");
        expect(alert.className).toContain("rounded-md");
        expect(alert).toHaveTextContent("本文メッセージ");
    });

    it.each([
        ["success", "border-success"],
        ["warning", "border-warning"],
        ["danger", "border-danger"],
        ["info", "border-primary"],
    ] as const)("type=%s で %s が付く", (type, borderClass) => {
        render(Alert, { props: { type, children, testId: "alert" } });

        expect(screen.getByTestId("alert").className).toContain(borderClass);
    });

    it("danger のみ role=alert (aria-live=assertive)、他は role=status (polite)", () => {
        const { unmount } = render(Alert, {
            props: { type: "danger", children, testId: "alert" },
        });
        const danger = screen.getByTestId("alert");
        expect(danger).toHaveAttribute("role", "alert");
        expect(danger).toHaveAttribute("aria-live", "assertive");
        unmount();

        render(Alert, { props: { type: "success", children, testId: "alert" } });
        const success = screen.getByTestId("alert");
        expect(success).toHaveAttribute("role", "status");
        expect(success).toHaveAttribute("aria-live", "polite");
    });

    it("title 指定時に状態色の見出しを描画し、省略時は見出しを描画しない", () => {
        const { unmount } = render(Alert, {
            props: { type: "warning", title: "注意", children, testId: "alert" },
        });
        const heading = screen.getByRole("heading", { level: 4 });
        expect(heading).toHaveTextContent("注意");
        expect(heading.className).toContain("text-warning");
        unmount();

        render(Alert, { props: { type: "warning", children, testId: "alert" } });
        expect(screen.queryByRole("heading")).toBeNull();
    });

    it("action snippet が本文の下に描画される", () => {
        const action = createRawSnippet(() => ({
            render: () => '<button data-testid="alert-action">再試行</button>',
        }));
        render(Alert, { props: { type: "danger", children, action } });

        expect(screen.getByTestId("alert-action")).toBeInTheDocument();
    });

    it("dismissible + onDismiss で閉じるボタンを描画し、クリックで onDismiss が呼ばれる", async () => {
        const onDismiss = vi.fn();
        render(Alert, {
            props: { type: "success", children, dismissible: true, onDismiss },
        });

        await fireEvent.click(screen.getByRole("button", { name: "閉じる" }));
        expect(onDismiss).toHaveBeenCalledTimes(1);
    });

    it("dismissible 省略時は閉じるボタンを描画しない", () => {
        render(Alert, { props: { type: "success", children } });

        expect(screen.queryByRole("button", { name: "閉じる" })).toBeNull();
    });
});
