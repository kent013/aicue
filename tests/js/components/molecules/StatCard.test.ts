import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import { FolderKanban } from "@lucide/svelte";
import StatCard from "@/components/molecules/StatCard.svelte";

describe("StatCard", () => {
    it("label と value を描画する (value は text-h2)", () => {
        render(StatCard, {
            props: { label: "プロジェクト数", value: 12, testId: "stat" },
        });

        const card = screen.getByTestId("stat");
        expect(screen.getByText("プロジェクト数")).toBeInTheDocument();
        const value = screen.getByText("12");
        expect(value.className).toContain("text-h2");
        expect(card.className).toContain("bg-surface");
    });

    it("subtext 指定時に補足文を描画し、省略時は描画しない", () => {
        const { unmount } = render(StatCard, {
            props: { label: "メンバー", value: 3, subtext: "先月比 +1", testId: "stat" },
        });
        expect(screen.getByText("先月比 +1")).toBeInTheDocument();
        unmount();

        render(StatCard, { props: { label: "メンバー", value: 3, testId: "stat" } });
        expect(screen.queryByText("先月比 +1")).toBeNull();
    });

    it("icon 指定時に aria-hidden の Lucide アイコンを描画する", () => {
        render(StatCard, {
            props: { label: "件数", value: 0, icon: FolderKanban, testId: "stat" },
        });

        const svg = screen.getByTestId("stat").querySelector("svg");
        expect(svg).not.toBeNull();
        expect(svg).toHaveAttribute("aria-hidden", "true");
    });

    it("icon 省略時はアイコン box を描画しない", () => {
        render(StatCard, { props: { label: "件数", value: 0, testId: "stat" } });

        expect(screen.getByTestId("stat").querySelector("svg")).toBeNull();
    });
});
