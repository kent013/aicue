import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import Checkbox from "@/components/atoms/Checkbox.svelte";

describe("Checkbox", () => {
    it("ラベル付きで描画され click でトグルする", async () => {
        render(Checkbox, { props: { id: "agree", label: "同意します", testId: "cb" } });

        const checkbox = screen.getByTestId("cb");
        expect(screen.getByLabelText("同意します")).toBe(checkbox);
        await fireEvent.click(checkbox);
        expect(checkbox).toBeChecked();
    });

    it("error 指定でエラー文言と aria 配線が付く", () => {
        render(Checkbox, {
            props: { id: "agree", label: "同意します", error: "同意が必要です", testId: "cb" },
        });

        expect(screen.getByText("同意が必要です")).toHaveAttribute("id", "agree-error");
        const checkbox = screen.getByTestId("cb");
        expect(checkbox).toHaveAttribute("aria-invalid", "true");
        expect(checkbox).toHaveAttribute("aria-describedby", "agree-error");
    });

    it("複数行ラベルでも行揃えが atom 側で担保される (items-start + mt)", () => {
        render(Checkbox, { props: { id: "agree", label: "とても長いラベル", testId: "cb" } });

        const label = screen.getByTestId("cb").closest("label");
        expect(label?.className).toContain("items-start");
        expect(screen.getByTestId("cb").className).toContain("mt-1.5");
    });
});
