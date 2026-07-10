import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import Card from "@/components/atoms/Card.svelte";
import { THEME_PATTERNS, UNIVERSAL_PATTERNS } from "../../support/ds-purity";

const children = createRawSnippet(() => ({
    render: () => "<p>カード本文</p>",
}));

describe("Card", () => {
    it("既定で bg-surface + border-border + rounded-lg + p-4 を描画する", () => {
        render(Card, { props: { children, testId: "card" } });

        const card = screen.getByTestId("card");
        expect(card.className).toContain("bg-surface");
        expect(card.className).toContain("border-border");
        expect(card.className).toContain("rounded-lg");
        expect(card.className).toContain("p-4");
        expect(card).toHaveTextContent("カード本文");
    });

    it("影 (shadow) を使わない", () => {
        render(Card, { props: { children, testId: "card" } });

        expect(screen.getByTestId("card").className).not.toContain("shadow");
    });

    it("padding=none で padding クラスを当てない", () => {
        render(Card, { props: { padding: "none", children, testId: "card" } });

        expect(screen.getByTestId("card").className).not.toMatch(/\bp-\d/);
    });

    it("padding=sm / lg のクラスが適用される", () => {
        render(Card, { props: { padding: "lg", children, testId: "card" } });

        expect(screen.getByTestId("card").className).toContain("p-6");
    });

    it("class prop が追記される", () => {
        render(Card, { props: { class: "mt-4", children, testId: "card" } });

        expect(screen.getByTestId("card").className).toContain("mt-4");
    });

    it("描画クラスが DS-pure (禁止パターンを含まない)", () => {
        render(Card, { props: { children, testId: "card" } });

        const allClasses = screen.getByTestId("card").className;
        for (const [pattern] of [...UNIVERSAL_PATTERNS, ...THEME_PATTERNS]) {
            expect(allClasses).not.toMatch(pattern);
        }
    });
});
