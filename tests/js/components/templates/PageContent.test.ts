import { describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import { afterEach } from "vitest";
import PageContent from "@/components/templates/PageContent.svelte";

/*
 * PageContent — 認証ページ本文の中央寄せ + max-width 制御 layout primitive の契約テスト。
 * 幅は maxWidth(必須 union prop) が単独所有し、mx-auto で中央寄せする。
 */

const children = createRawSnippet(() => ({
    render: () => `<div data-testid="pc-child">body</div>`,
}));

afterEach(() => cleanup());

describe("templates/PageContent", () => {
    it("children を描画する", () => {
        render(PageContent, { props: { maxWidth: "2xl", children } });
        expect(screen.getByTestId("pc-child")).toBeInTheDocument();
    });

    it("ルートは mx-auto で中央寄せする", () => {
        render(PageContent, { props: { maxWidth: "2xl", children } });
        expect(screen.getByTestId("page-content")).toHaveClass("mx-auto");
    });

    it.each([
        ["md", "max-w-md"],
        ["2xl", "max-w-2xl"],
        ["3xl", "max-w-3xl"],
        ["4xl", "max-w-4xl"],
        ["7xl", "max-w-7xl"],
    ] as const)("maxWidth=%s でルートに %s を適用する", (maxWidth, cls) => {
        render(PageContent, { props: { maxWidth, children } });
        expect(screen.getByTestId("page-content")).toHaveClass(cls);
    });

    it("testId prop で data-testid を差し替えられる (既定は page-content)", () => {
        render(PageContent, { props: { maxWidth: "2xl", testId: "custom-pc", children } });
        expect(screen.getByTestId("custom-pc")).toHaveClass("mx-auto", "max-w-2xl");
    });
});
