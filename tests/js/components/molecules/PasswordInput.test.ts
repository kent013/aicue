import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import PasswordInput from "@/components/molecules/PasswordInput.svelte";

describe("PasswordInput", () => {
    it("既定で type=password の入力を描画する", () => {
        render(PasswordInput, { props: { id: "password", testId: "pw" } });

        expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
        expect(screen.getByTestId("pw")).toHaveAttribute("id", "password");
    });

    it("トグルで type が text ↔ password に切り替わる", async () => {
        render(PasswordInput, { props: { id: "password", testId: "pw" } });

        const toggle = screen.getByRole("button", { name: "パスワードを表示" });
        expect(toggle).toHaveAttribute("aria-pressed", "false");

        await fireEvent.click(toggle);
        expect(screen.getByTestId("pw")).toHaveAttribute("type", "text");
        expect(
            screen.getByRole("button", { name: "パスワードを非表示" }),
        ).toHaveAttribute("aria-pressed", "true");

        await fireEvent.click(screen.getByRole("button", { name: "パスワードを非表示" }));
        expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
    });

    it("トグルボタンが aria-controls で入力に結線される", () => {
        render(PasswordInput, { props: { id: "current-password" } });

        expect(screen.getByRole("button", { name: "パスワードを表示" })).toHaveAttribute(
            "aria-controls",
            "current-password",
        );
    });

    it("disabled で入力とトグルの両方が無効になる", () => {
        render(PasswordInput, { props: { id: "password", disabled: true, testId: "pw" } });

        expect(screen.getByTestId("pw")).toBeDisabled();
        expect(screen.getByRole("button", { name: "パスワードを表示" })).toBeDisabled();
    });

    it("error=true で aria-invalid が入力に付く", () => {
        render(PasswordInput, { props: { id: "password", error: true, testId: "pw" } });

        expect(screen.getByTestId("pw")).toHaveAttribute("aria-invalid", "true");
    });

    it("autocomplete / aria-describedby 等の残余属性を入力へ透過する", () => {
        render(PasswordInput, {
            props: {
                id: "password",
                testId: "pw",
                autocomplete: "new-password",
                "aria-describedby": "password-error",
            },
        });

        const input = screen.getByTestId("pw");
        expect(input).toHaveAttribute("autocomplete", "new-password");
        expect(input).toHaveAttribute("aria-describedby", "password-error");
    });
});
