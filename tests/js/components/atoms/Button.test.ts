import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import Button from "@/components/atoms/Button.svelte";
import type { ButtonProps } from "@/components/atoms/Button.types";
import {
    ICON_ONLY_SIZE_CLASSES,
    SIZE_CLASSES,
    VARIANT_CLASSES,
} from "@/components/atoms/Button.types";
import { THEME_PATTERNS, UNIVERSAL_PATTERNS } from "../../support/ds-purity";

describe("Button", () => {
    it("既定で type=button の <button> を描画する", () => {
        render(Button, { props: {} });

        const button = screen.getByRole("button");
        expect(button.tagName).toBe("BUTTON");
        expect(button).toHaveAttribute("type", "button");
    });

    it("variant のクラスが適用される", () => {
        render(Button, { props: { variant: "primary", testId: "btn" } });

        expect(screen.getByTestId("btn").className).toContain("bg-primary");
    });

    it("href 指定で <a> を描画する", () => {
        render(Button, { props: { href: "/somewhere", testId: "link" } });

        const anchor = screen.getByTestId("link");
        expect(anchor.tagName).toBe("A");
        expect(anchor).toHaveAttribute("href", "/somewhere");
    });

    it('target="_blank" に rel="noopener noreferrer" を自動補完する', () => {
        render(Button, { props: { href: "https://example.com", target: "_blank", testId: "ext" } });

        const rel = screen.getByTestId("ext").getAttribute("rel") ?? "";
        expect(rel).toContain("noopener");
        expect(rel).toContain("noreferrer");
    });

    it("loading 中の <button> は disabled + aria-busy", () => {
        render(Button, { props: { loading: true, testId: "btn" } });

        const button = screen.getByTestId("btn");
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute("aria-busy", "true");
    });

    it("loading 中の anchor は click 抑止 + tabindex=-1 + aria-disabled", async () => {
        const onclick = vi.fn();
        render(Button, { props: { href: "/x", loading: true, onclick, testId: "link" } });

        const anchor = screen.getByTestId("link");
        expect(anchor).toHaveAttribute("tabindex", "-1");
        expect(anchor).toHaveAttribute("aria-disabled", "true");
        await fireEvent.click(anchor);
        expect(onclick).not.toHaveBeenCalled();
    });

    it("ariaExpanded / ariaControls を <button> に反映する", () => {
        render(Button, { props: { ariaExpanded: true, ariaControls: "panel-x", testId: "t" } });

        const btn = screen.getByTestId("t");
        expect(btn).toHaveAttribute("aria-expanded", "true");
        expect(btn).toHaveAttribute("aria-controls", "panel-x");
    });

    it("disclosure props 未指定なら aria-expanded / aria-controls を出さない", () => {
        render(Button, { props: { testId: "t" } });

        const btn = screen.getByTestId("t");
        expect(btn).not.toHaveAttribute("aria-expanded");
        expect(btn).not.toHaveAttribute("aria-controls");
    });

    it("iconOnly は ariaLabel が必須 / anchor で disabled は使えない (型レベル)", () => {
        // @ts-expect-error iconOnly には ariaLabel が必須
        const _missingLabel: ButtonProps = { iconOnly: true };
        // @ts-expect-error anchor モードでは disabled を使えない
        const _anchorDisabled: ButtonProps = { href: "/x", disabled: true };
        // @ts-expect-error anchor モードでは type を使えない
        const _anchorType: ButtonProps = { href: "/x", type: "submit" };
        expect(true).toBe(true);
    });

    it("variant/size のクラス定義が DS-pure (禁止パターンを含まない)", () => {
        const allClasses = [
            ...Object.values(VARIANT_CLASSES),
            ...Object.values(SIZE_CLASSES),
            ...Object.values(ICON_ONLY_SIZE_CLASSES),
        ].join(" ");

        for (const [pattern] of [...UNIVERSAL_PATTERNS, ...THEME_PATTERNS]) {
            expect(allClasses).not.toMatch(pattern);
        }
    });
});
