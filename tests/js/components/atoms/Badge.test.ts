import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import Badge from "@/components/atoms/Badge.svelte";
import {
    BORDERED_CLASSES,
    SIZE_CLASSES,
    TONE_CLASSES,
} from "@/components/atoms/Badge.types";
import { THEME_PATTERNS, UNIVERSAL_PATTERNS } from "../../support/ds-purity";

const children = createRawSnippet(() => ({
    render: () => "<span>公開済み</span>",
}));

describe("Badge", () => {
    it("既定で soft primary (bg-primary-soft) + sm + rounded-sm の <span> を描画する", () => {
        render(Badge, { props: { children, testId: "badge" } });

        const badge = screen.getByTestId("badge");
        expect(badge.tagName).toBe("SPAN");
        expect(badge.className).toContain("bg-primary-soft");
        expect(badge.className).toContain("text-primary");
        expect(badge.className).toContain("rounded-sm");
        expect(badge.className).toContain("text-caption");
        expect(badge).toHaveTextContent("公開済み");
    });

    it("tone の soft クラス (tone 色 /10 背景 + tone 色文字) が適用される", () => {
        render(Badge, { props: { tone: "success", children, testId: "badge" } });

        const badge = screen.getByTestId("badge");
        expect(badge.className).toContain("bg-success/10");
        expect(badge.className).toContain("text-success");
        expect(badge.className).not.toContain("border-success");
    });

    it("bordered で tone 色の border が atom 内で付与される", () => {
        render(Badge, {
            props: { tone: "danger", bordered: true, children, testId: "badge" },
        });

        expect(screen.getByTestId("badge").className).toContain("border-danger");
    });

    it("size=md のクラスが適用される", () => {
        render(Badge, { props: { size: "md", children, testId: "badge" } });

        const badge = screen.getByTestId("badge");
        expect(badge.className).toContain("px-3");
        expect(badge.className).toContain("text-body");
    });

    it("icon snippet が左側の aria-hidden wrapper 内に描画される", () => {
        const icon = createRawSnippet(() => ({
            render: () => '<i data-testid="badge-icon"></i>',
        }));
        render(Badge, { props: { icon, children, testId: "badge" } });

        const iconEl = screen.getByTestId("badge-icon");
        const wrapper = iconEl.parentElement;
        expect(wrapper?.getAttribute("aria-hidden")).toBe("true");
        // icon wrapper は children より前 (左側) に位置する
        expect(screen.getByTestId("badge").firstElementChild).toBe(wrapper);
    });

    it("tone/bordered/size のクラス定義が DS-pure (禁止パターンを含まない)", () => {
        const allClasses = [
            ...Object.values(TONE_CLASSES),
            ...Object.values(BORDERED_CLASSES),
            ...Object.values(SIZE_CLASSES),
        ].join(" ");

        for (const [pattern] of [...UNIVERSAL_PATTERNS, ...THEME_PATTERNS]) {
            expect(allClasses).not.toMatch(pattern);
        }
    });
});
