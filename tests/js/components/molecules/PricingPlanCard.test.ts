import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";

/*
 * PricingPlanCard: priceAmount null (plan_prices base なし = 無料プラン) と 0 は
 * どちらも「無料」表示 (DTO 契約と対)。有償は ¥N + suffix + priceCaption。
 */

const footerCta = createRawSnippet(() => ({
    render: () => "<button type='button'>CTA</button>",
}));

function renderCard(priceAmount: number | null): void {
    render(PricingPlanCard, {
        props: {
            name: "テストプラン",
            priceAmount,
            priceCaption: "基本料金",
            features: [{ text: "特典 A" }],
            testId: "plan-card",
            footerCta,
        },
    });
}

describe("PricingPlanCard", () => {
    it("priceAmount=null は「無料」を掲示し価格 caption を出さない", () => {
        renderCard(null);

        const card = screen.getByTestId("plan-card");
        expect(card).toHaveTextContent("無料");
        expect(card).not.toHaveTextContent("¥");
        expect(screen.queryByTestId("price-caption")).toBeNull();
    });

    it("priceAmount=0 も防御的に「無料」を掲示する", () => {
        renderCard(0);

        const card = screen.getByTestId("plan-card");
        expect(card).toHaveTextContent("無料");
        expect(card).not.toHaveTextContent("¥");
    });

    it("有償プランは ¥N／月 と priceCaption を掲示する", () => {
        renderCard(4980);

        const card = screen.getByTestId("plan-card");
        expect(card).toHaveTextContent("¥4,980");
        expect(card).toHaveTextContent("／月");
        expect(screen.getByTestId("price-caption")).toHaveTextContent("基本料金");
        expect(card).toHaveTextContent("特典 A");
    });
});
