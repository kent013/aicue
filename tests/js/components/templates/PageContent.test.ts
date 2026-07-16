import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import PageContent from "@/components/templates/PageContent.svelte";

/*
 * PageContent — aigenba 準拠の中央寄せ max-width wrapper (prop 無し・常に mx-auto max-w-7xl)。
 * T070 の独自 maxWidth/testId prop は撤去済み (prop を受けないことは pnpm typecheck で保証)。
 */

const children = createRawSnippet(() => ({ render: () => `<div data-testid="pc-child">body</div>` }));

afterEach(() => cleanup());

describe("templates/PageContent", () => {
    it("children を描画する", () => {
        render(PageContent, { props: { children } });
        expect(screen.getByTestId("pc-child")).toBeInTheDocument();
    });

    it("ルートは mx-auto max-w-7xl 固定で中央寄せする", () => {
        const { container } = render(PageContent, { props: { children } });
        const root = container.querySelector("div")!;
        expect(root).toHaveClass("mx-auto", "max-w-7xl");
    });
});
