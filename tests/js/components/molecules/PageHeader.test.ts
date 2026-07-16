import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import PageHeader from "@/components/molecules/PageHeader.svelte";

afterEach(() => cleanup());

describe("molecules/PageHeader", () => {
    it("title/description/testId を PageHeaderSection 経由で描画する (root shorthand)", () => {
        render(PageHeader, { props: { title: "タイトル", description: "説明", testId: "root-heading" } });
        const h1 = screen.getByTestId("root-heading");
        expect(h1.tagName).toBe("H1");
        expect(h1).toHaveTextContent("タイトル");
        expect(screen.getByText("説明")).toBeInTheDocument();
    });
});
