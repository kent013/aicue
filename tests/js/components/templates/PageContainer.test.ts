import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import PageContainer from "@/components/templates/PageContainer.svelte";

const children = createRawSnippet(() => ({ render: () => `<div data-testid="pc-child">x</div>` }));
afterEach(() => cleanup());

describe("templates/PageContainer", () => {
    it("既定 padding で px-4 py-8 sm:px-6 lg:px-8 を持ち children を描画する", () => {
        const { container } = render(PageContainer, { props: { children } });
        expect(screen.getByTestId("pc-child")).toBeInTheDocument();
        const root = container.querySelector("div")!;
        expect(root).toHaveClass("px-4", "py-8", "sm:px-6", "lg:px-8", "w-full");
    });
    it("padding=false で padding class を持たない", () => {
        const { container } = render(PageContainer, { props: { padding: false, children } });
        const root = container.querySelector("div")!;
        expect(root).toHaveClass("w-full");
        expect(root.className).not.toMatch(/px-4|py-8/);
    });
});
