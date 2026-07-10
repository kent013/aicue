import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Input from "@/components/atoms/Input.svelte";

describe("Input", () => {
    it("text input を描画し value を反映する", () => {
        render(Input, { props: { value: "hello", testId: "input" } });

        expect(screen.getByTestId("input")).toHaveValue("hello");
    });

    it("error 時に aria-invalid と danger ボーダーが付く", () => {
        render(Input, { props: { error: true, testId: "input" } });

        const input = screen.getByTestId("input");
        expect(input).toHaveAttribute("aria-invalid", "true");
        expect(input.className).toContain("border-danger");
    });

    it("aria-describedby 等の restProps を透過する", () => {
        render(Input, { props: { testId: "input", "aria-describedby": "name-error" } });

        expect(screen.getByTestId("input")).toHaveAttribute("aria-describedby", "name-error");
    });
});
