import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import { House } from "@lucide/svelte";
import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";

afterEach(() => cleanup());

describe("molecules/PageHeaderSection", () => {
    it("title を h1(text-h2) で描画し testId を反映する", () => {
        render(PageHeaderSection, { props: { title: "見出し", testId: "x-heading" } });
        const h1 = screen.getByTestId("x-heading");
        expect(h1.tagName).toBe("H1");
        expect(h1).toHaveClass("text-h2");
        expect(h1).toHaveTextContent("見出し");
    });
    it("description / icon / actions(children) を描画する", () => {
        const actions = createRawSnippet(() => ({ render: () => `<button data-testid="hdr-action">A</button>` }));
        render(PageHeaderSection, {
            props: { title: "T", description: "説明文", icon: House, children: actions },
        });
        expect(screen.getByText("説明文")).toBeInTheDocument();
        expect(screen.getByTestId("hdr-action")).toBeInTheDocument();
    });
    it("breadcrumbs は 2 件以上でのみ表示する (1 件は非表示)", () => {
        render(PageHeaderSection, { props: { title: "T", breadcrumbs: [{ label: "現在" }] } });
        expect(screen.queryByLabelText("Breadcrumb")).toBeNull();
        cleanup();
        render(PageHeaderSection, {
            props: { title: "T", breadcrumbs: [{ label: "親", href: "/p" }, { label: "子" }] },
        });
        expect(screen.getByLabelText("Breadcrumb")).toBeInTheDocument();
    });
});
