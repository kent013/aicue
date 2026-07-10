import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Divider from "@/components/molecules/Divider.svelte";

describe("Divider", () => {
    it("label 省略時は <hr> を描画する", () => {
        render(Divider, { props: { testId: "divider" } });

        const hr = screen.getByTestId("divider");
        expect(hr.tagName).toBe("HR");
        expect(hr.className).toContain("border-border");
    });

    it("label 指定時は中央ラベル付きの区切りを描画する", () => {
        render(Divider, { props: { label: "または", testId: "divider" } });

        const root = screen.getByTestId("divider");
        expect(root.tagName).toBe("DIV");
        const label = screen.getByText("または");
        expect(label.tagName).toBe("SPAN");
        expect(label.className).toContain("bg-surface");
        expect(label.className).toContain("text-text-secondary");
        // 線部分は装飾なので aria-hidden
        expect(root.querySelector('[aria-hidden="true"]')).not.toBeNull();
    });

    it("class passthrough で余白を渡せる (label なし)", () => {
        render(Divider, { props: { class: "my-6", testId: "divider" } });

        expect(screen.getByTestId("divider").className).toContain("my-6");
    });

    it("class passthrough で余白を渡せる (label あり)", () => {
        render(Divider, { props: { label: "または", class: "my-6", testId: "divider" } });

        expect(screen.getByTestId("divider").className).toContain("my-6");
    });
});
