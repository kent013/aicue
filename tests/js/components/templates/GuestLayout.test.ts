import { describe, expect, it } from "vitest";
import { createRawSnippet } from "svelte";
import { fireEvent, render, screen, within } from "@testing-library/svelte";
import GuestLayout from "@/components/templates/GuestLayout.svelte";

/*
 * GuestLayout の狭幅ハンバーガー化 (T027)。nav 未指定でトグル・パネルが出ないこと、
 * nav 指定でトグルが出ることを固定する。実挙動 (Escape / パネル内リンク) の主検証は
 * Welcome.test.ts が担う。
 */

const children = createRawSnippet(() => ({ render: () => `<p>content</p>` }));
const nav = createRawSnippet(() => ({
    render: () => `<a href="/pricing">料金プラン</a>`,
}));

describe("GuestLayout", () => {
    it("nav を渡さないとハンバーガー・パネルを描画しない (Contact 相当)", () => {
        render(GuestLayout, { props: { appName: "AI-CUE", children } });

        expect(screen.queryByTestId("guest-nav-toggle")).not.toBeInTheDocument();
        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
    });

    it("nav を渡すとトグルが出て、押下でパネルが開く", async () => {
        render(GuestLayout, { props: { appName: "AI-CUE", children, nav } });

        const toggle = screen.getByTestId("guest-nav-toggle");
        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
        await fireEvent.click(toggle);
        const panel = screen.getByTestId("guest-nav-panel");
        expect(within(panel).getByRole("link", { name: "料金プラン" })).toBeInTheDocument();
    });
});
