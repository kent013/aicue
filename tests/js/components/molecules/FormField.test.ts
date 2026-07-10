import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import FormField from "@/components/molecules/FormField.svelte";

type FieldContext = { id: string; describedBy: string | undefined; invalid: boolean };

function fieldSnippet() {
    return createRawSnippet<[FieldContext]>((ctx) => ({
        render: () => {
            const { id, describedBy, invalid } = ctx();
            return `<input id="${id}" data-testid="field" ${describedBy ? `aria-describedby="${describedBy}"` : ""} ${invalid ? 'aria-invalid="true"' : ""} />`;
        },
    }));
}

describe("FormField", () => {
    it("label と入力の for/id を配線する", () => {
        render(FormField, {
            props: { label: "名前", id: "name", children: fieldSnippet() },
        });

        expect(screen.getByLabelText("名前")).toHaveAttribute("id", "name");
    });

    it("error 時に describedBy / invalid が children に渡りエラー文言が出る", () => {
        render(FormField, {
            props: { label: "名前", id: "name", error: "必須です", children: fieldSnippet() },
        });

        expect(screen.getByTestId("field")).toHaveAttribute("aria-describedby", "name-error");
        expect(screen.getByTestId("field")).toHaveAttribute("aria-invalid", "true");
        expect(screen.getByText("必須です")).toHaveAttribute("id", "name-error");
    });

    it("help と error の両方が describedBy に入る", () => {
        render(FormField, {
            props: {
                label: "名前",
                id: "name",
                error: "必須です",
                help: "本名でなくて構いません",
                children: fieldSnippet(),
            },
        });

        expect(screen.getByTestId("field").getAttribute("aria-describedby")).toBe(
            "name-error name-help",
        );
    });

    it("required で * が表示される", () => {
        render(FormField, {
            props: { label: "名前", id: "name", required: true, children: fieldSnippet() },
        });

        expect(screen.getByText("*")).toHaveAttribute("aria-hidden", "true");
    });
});
