import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import DangerZone from "@/components/molecules/DangerZone.svelte";

/** children snippet (破壊的操作ボタンの代わりのダミー) */
const children = createRawSnippet(() => ({
    render: () => `<button type="button">アカウントを削除</button>`,
}));

describe("DangerZone", () => {
    it("title を accessible name とする region として描画する", () => {
        render(DangerZone, { props: { title: "危険な操作", children } });

        const region = screen.getByRole("region", { name: "危険な操作" });
        expect(region.tagName).toBe("SECTION");
    });

    it("aria-labelledby が見出し id (既定 danger-zone-title) と配線される", () => {
        render(DangerZone, { props: { title: "危険な操作", children, testId: "zone" } });

        const section = screen.getByTestId("zone");
        expect(section).toHaveAttribute("aria-labelledby", "danger-zone-title");
        const heading = screen.getByRole("heading", { level: 3 });
        expect(heading).toHaveAttribute("id", "danger-zone-title");
        expect(heading).toHaveTextContent("危険な操作");
    });

    it("idBase 指定で見出し id の基底を変更できる (複数同居時の衝突回避)", () => {
        render(DangerZone, {
            props: { title: "組織の削除", idBase: "org-danger", children, testId: "zone" },
        });

        expect(screen.getByTestId("zone")).toHaveAttribute("aria-labelledby", "org-danger-title");
        expect(screen.getByRole("heading", { level: 3 })).toHaveAttribute("id", "org-danger-title");
    });

    it("description を描画する (省略時は描画しない)", () => {
        render(DangerZone, {
            props: { title: "危険な操作", description: "この操作は取り消せません", children },
        });

        expect(screen.getByText("この操作は取り消せません")).toBeInTheDocument();
    });

    it("children snippet を描画する", () => {
        render(DangerZone, { props: { title: "危険な操作", children } });

        expect(screen.getByRole("button", { name: "アカウントを削除" })).toBeInTheDocument();
    });

    it("danger トーンのスタイルが適用される", () => {
        render(DangerZone, { props: { title: "危険な操作", children, testId: "zone" } });

        const className = screen.getByTestId("zone").className;
        expect(className).toContain("bg-danger/5");
        expect(className).toContain("border-danger/30");
        expect(className).toContain("rounded-lg");
    });
});
