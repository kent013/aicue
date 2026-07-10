import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import SelectHarness from "./SelectHarness.svelte";

describe("Select", () => {
    it("children の <option> 群を持つ <select> を描画し id を反映する", () => {
        render(SelectHarness);

        const select = screen.getByTestId("select") as HTMLSelectElement;
        expect(select.tagName).toBe("SELECT");
        expect(select).toHaveAttribute("id", "fruit");
        expect(select.options.length).toBe(2);
    });

    it("change で bind:value が親へ同期する", async () => {
        render(SelectHarness);

        const select = screen.getByTestId("select") as HTMLSelectElement;
        expect(select.value).toBe("apple");

        await fireEvent.change(select, { target: { value: "banana" } });
        expect(select.value).toBe("banana");
        expect(screen.getByTestId("bound-value").textContent).toBe("banana");
    });

    it("error=true で aria-invalid と danger 系クラスが付く", () => {
        render(SelectHarness, { props: { error: true } });

        const select = screen.getByTestId("select");
        expect(select).toHaveAttribute("aria-invalid", "true");
        expect(select.className).toContain("border-danger");
    });

    it("error=false では aria-invalid を付けず restProps (aria-describedby) を透過する", () => {
        render(SelectHarness);

        const select = screen.getByTestId("select");
        expect(select).not.toHaveAttribute("aria-invalid");
        expect(select).toHaveAttribute("aria-describedby", "fruit-error");
        expect(select.className).toContain("border-border-strong");
    });

    it("disabled を反映する", () => {
        render(SelectHarness, { props: { disabled: true } });

        expect(screen.getByTestId("select")).toBeDisabled();
    });
});
