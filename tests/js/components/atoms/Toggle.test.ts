import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import Toggle from "@/components/atoms/Toggle.svelte";
import type { ToggleProps } from "@/components/atoms/Toggle.types";

describe("Toggle", () => {
    it('role="switch" の <button> を aria-checked / aria-label 付きで描画する', () => {
        render(Toggle, {
            props: { checked: false, ariaLabel: "通知を有効化", testId: "toggle" },
        });

        const toggle = screen.getByRole("switch");
        expect(toggle.tagName).toBe("BUTTON");
        expect(toggle).toHaveAttribute("type", "button");
        expect(toggle).toHaveAttribute("aria-checked", "false");
        expect(toggle).toHaveAttribute("aria-label", "通知を有効化");
    });

    it("click で checked がトグルし onChange が新しい値で呼ばれる", async () => {
        const onChange = vi.fn();
        render(Toggle, {
            props: { checked: false, ariaLabel: "通知", onChange, testId: "toggle" },
        });

        const toggle = screen.getByTestId("toggle");
        await fireEvent.click(toggle);
        expect(toggle).toHaveAttribute("aria-checked", "true");
        expect(onChange).toHaveBeenCalledWith(true);

        await fireEvent.click(toggle);
        expect(toggle).toHaveAttribute("aria-checked", "false");
        expect(onChange).toHaveBeenLastCalledWith(false);
        expect(onChange).toHaveBeenCalledTimes(2);
    });

    it("On=bg-primary / Off=bg-border-strong のトラック色になる", async () => {
        render(Toggle, { props: { checked: true, ariaLabel: "通知", testId: "toggle" } });

        const toggle = screen.getByTestId("toggle");
        expect(toggle.className).toContain("bg-primary");

        await fireEvent.click(toggle);
        expect(toggle.className).toContain("bg-border-strong");
    });

    it("disabled では切り替わらず onChange も呼ばれない", async () => {
        const onChange = vi.fn();
        render(Toggle, {
            props: { checked: false, ariaLabel: "通知", disabled: true, onChange, testId: "toggle" },
        });

        const toggle = screen.getByTestId("toggle");
        expect(toggle).toBeDisabled();

        await fireEvent.click(toggle);
        expect(toggle).toHaveAttribute("aria-checked", "false");
        expect(onChange).not.toHaveBeenCalled();
    });

    it("ariaLabel は型レベルで必須", () => {
        // @ts-expect-error ariaLabel が無い ToggleProps はコンパイルエラー
        const _missingLabel: ToggleProps = { checked: true };
        expect(true).toBe(true);
    });
});
