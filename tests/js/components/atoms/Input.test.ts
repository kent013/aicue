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

    // 編集不可は「面」で示す (DESIGN.md §Input / Textarea / Select)。bug-hunt F-3-03 の根治。
    it("readonly で native 属性と muted な面が付く", () => {
        render(Input, { props: { readonly: true, testId: "input" } });

        const input = screen.getByTestId("input");
        expect(input).toHaveAttribute("readonly");
        // token 単位で見る (disabled:bg-neutral 等のバリアントを substring で拾わないため)
        const tokens = input.className.split(/\s+/);
        expect(tokens).toContain("bg-neutral");
        expect(tokens).toContain("cursor-default");
        expect(tokens).not.toContain("bg-surface");
    });

    it("readonly でも文字色は落とさない (disabled と意味が違う)", () => {
        render(Input, { props: { readonly: true, testId: "input" } });

        const input = screen.getByTestId("input");
        // class token 単位で見る (text-text-secondary は disabled: バリアント側にしか無いこと)
        const tokens = input.className.split(/\s+/);
        expect(tokens).toContain("text-text");
        expect(tokens).not.toContain("text-text-secondary");
        expect(tokens).not.toContain("cursor-not-allowed");
        // フォーカス可能なまま (値の選択・コピーができる)
        expect(input).not.toBeDisabled();
    });

    it("readonly 既定 (false) では通常の面のまま", () => {
        render(Input, { props: { testId: "input" } });

        const input = screen.getByTestId("input");
        expect(input).not.toHaveAttribute("readonly");
        const tokens = input.className.split(/\s+/);
        expect(tokens).toContain("bg-surface");
        expect(tokens).not.toContain("bg-neutral");
        expect(tokens).not.toContain("cursor-default");
    });

    it("readonly + error では danger border と muted 面が両立する", () => {
        render(Input, { props: { readonly: true, error: true, testId: "input" } });

        const tokens = screen.getByTestId("input").className.split(/\s+/);
        expect(tokens).toContain("border-danger");
        expect(tokens).toContain("bg-neutral");
    });
});
