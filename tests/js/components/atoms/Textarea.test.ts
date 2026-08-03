import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import Textarea from "@/components/atoms/Textarea.svelte";

describe("Textarea", () => {
    it("既定 rows=4 の <textarea> を描画し id / placeholder を反映する", () => {
        render(Textarea, {
            props: { id: "memo", placeholder: "メモを入力", testId: "ta" },
        });

        const textarea = screen.getByTestId("ta");
        expect(textarea.tagName).toBe("TEXTAREA");
        expect(textarea).toHaveAttribute("rows", "4");
        expect(textarea).toHaveAttribute("id", "memo");
        expect(textarea).toHaveAttribute("placeholder", "メモを入力");
    });

    it("value が要素に同期し、入力で更新される (bind)", async () => {
        render(Textarea, { props: { value: "初期値", testId: "ta" } });

        const textarea = screen.getByTestId("ta") as HTMLTextAreaElement;
        expect(textarea.value).toBe("初期値");

        await fireEvent.input(textarea, { target: { value: "更新後" } });
        expect(textarea.value).toBe("更新後");
    });

    it("error=true で aria-invalid と danger 系クラスが付く", () => {
        render(Textarea, { props: { error: true, testId: "ta" } });

        const textarea = screen.getByTestId("ta");
        expect(textarea).toHaveAttribute("aria-invalid", "true");
        expect(textarea.className).toContain("border-danger");
    });

    it("error=false では aria-invalid を付けず通常の枠線になる", () => {
        render(Textarea, { props: { testId: "ta" } });

        const textarea = screen.getByTestId("ta");
        expect(textarea).not.toHaveAttribute("aria-invalid");
        expect(textarea.className).toContain("border-border-strong");
    });

    // 入力系 atom 横断で同じ規約が成立していることの固定 (DESIGN.md §Input / Textarea / Select)
    it("readonly で native 属性と muted な面が付く (Input と同じ規約)", () => {
        render(Textarea, { props: { readonly: true, testId: "ta" } });

        const textarea = screen.getByTestId("ta");
        expect(textarea).toHaveAttribute("readonly");
        const tokens = textarea.className.split(/\s+/);
        expect(tokens).toContain("bg-neutral");
        expect(tokens).toContain("cursor-default");
        expect(tokens).toContain("text-text");
    });

    it("disabled と aria-describedby (restProps) を透過する", () => {
        render(Textarea, {
            props: { disabled: true, "aria-describedby": "memo-error", testId: "ta" },
        });

        const textarea = screen.getByTestId("ta");
        expect(textarea).toBeDisabled();
        expect(textarea).toHaveAttribute("aria-describedby", "memo-error");
    });
});
