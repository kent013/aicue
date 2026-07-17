import { describe, expect, it } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/svelte";
import Pricing from "@/pages/Pricing.svelte";
import type { PricingPageProps } from "@/types/marketing";

/*
 * 公開料金表。free (baseAmountJpy=null) の「無料」表示・standard の月額表示・
 * チケット帯変換・FAQ 開閉・disabled 不使用を固定する。
 */

const basePage: PricingPageProps = {
    plans: [
        {
            code: "free",
            name: "Free",
            baseAmountJpy: null,
            maxProjects: 1,
            maxMembers: 3,
            maxStorageGb: 1,
        },
        {
            code: "standard",
            name: "Standard",
            baseAmountJpy: 4980,
            maxProjects: 10,
            maxMembers: 10,
            maxStorageGb: 50,
        },
    ],
    ticketTiers: [
        { minCount: 1, unitAmount: 100 },
        { minCount: 20, unitAmount: 80 },
        { minCount: 50, unitAmount: 70 },
        { minCount: 100, unitAmount: 65 },
        { minCount: 200, unitAmount: 60 },
        { minCount: 300, unitAmount: 55 },
        { minCount: 500, unitAmount: 50 },
    ],
    spotUnitAmountJpy: 100,
    signupGrantTickets: 10,
    signupGrantExpiryDays: 30,
    analysisTicketCost: 1,
    renderTicketCost: 3,
    isAuthenticated: false,
    contactUrl: "/contact?source=pricing",
    contactIsExternal: false,
};

describe("Pricing", () => {
    it("プランカード 2 枚を描画し free は「無料」、standard は月額を表示する", () => {
        render(Pricing, { props: { page: basePage } });

        const freeCard = screen.getByTestId("pricing-plan-free");
        expect(freeCard).toHaveTextContent("無料");
        expect(freeCard).not.toHaveTextContent("¥");

        const standardCard = screen.getByTestId("pricing-plan-standard");
        expect(standardCard).toHaveTextContent("¥4,980");
        expect(standardCard).toHaveTextContent("基本料金");
        expect(standardCard).toHaveTextContent("ストレージ 50 GB");
    });

    it("チケット帯 (X〜Y 枚 / 最終段 X 枚以上) と signup grant 注記を描画する", () => {
        render(Pricing, { props: { page: basePage } });

        const table = screen.getByTestId("ticket-tier-table");
        expect(table).toHaveTextContent("1〜19 枚");
        expect(table).toHaveTextContent("20〜49 枚");
        expect(table).toHaveTextContent("¥80 ／ 枚");
        expect(table).toHaveTextContent("500 枚以上");
        expect(table).toHaveTextContent("¥50 ／ 枚");

        // 招待経由 (所属組織の残高を共有) は LP CTA の対象外。付与は個人組織を作る
        // 「新規登録」時に走るため、文言も「新規登録で」で挙動と整合させる。
        expect(screen.getByTestId("signup-grant-note")).toHaveTextContent(
            "新規登録でチケット 10 枚が無料でついてきます (付与から 30 日間有効)",
        );
    });

    it("FAQ は aria-expanded 付きの button で開閉できる", async () => {
        render(Pricing, { props: { page: basePage } });

        const question = screen.getByRole("button", { name: /無料で試せますか/ });
        expect(question).toHaveAttribute("aria-expanded", "false");

        await fireEvent.click(question);
        expect(question).toHaveAttribute("aria-expanded", "true");
        expect(
            screen.getByText(/Free プランは基本料金なしでご利用いただけます/),
        ).toBeInTheDocument();

        await fireEvent.click(question);
        expect(question).toHaveAttribute("aria-expanded", "false");
    });

    it("未認証は登録 CTA、認証済みはプラン変更 CTA を出す", () => {
        const { unmount } = render(Pricing, { props: { page: basePage } });
        expect(screen.getAllByRole("link", { name: "このプランで始める" })).toHaveLength(2);
        unmount();

        render(Pricing, {
            props: { page: { ...basePage, isAuthenticated: true } },
        });
        expect(screen.getAllByRole("link", { name: "プランを変更" })).toHaveLength(2);
    });

    it("disabled 属性を持つ button が存在しない (DESIGN.md)", () => {
        const { container } = render(Pricing, { props: { page: basePage } });

        expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
    });

    it("フッターに法的リンク3件 (利用規約→プライバシー→特商法) を href と順序どおり出す", () => {
        render(Pricing, { props: { page: basePage } });

        const footer = screen.getByRole("contentinfo");

        // (a) 法的3リンクを名前で個別取得し href を契約化。
        expect(within(footer).getByRole("link", { name: "利用規約" })).toHaveAttribute(
            "href",
            "/terms",
        );
        expect(
            within(footer).getByRole("link", { name: "プライバシーポリシー" }),
        ).toHaveAttribute("href", "/privacy");
        expect(
            within(footer).getByRole("link", { name: "特定商取引法に基づく表記" }),
        ).toHaveAttribute("href", "/commerce-disclosure");

        // (b) 法的リンクのみを DOM 順で抽出し表示順を固定 (非法的リンクは filter で除外)。
        const legalHrefs = within(footer)
            .getAllByRole("link")
            .map((a) => a.getAttribute("href"))
            .filter((href) =>
                ["/terms", "/privacy", "/commerce-disclosure"].includes(href ?? ""),
            );
        expect(legalHrefs).toEqual(["/terms", "/privacy", "/commerce-disclosure"]);
    });
});
