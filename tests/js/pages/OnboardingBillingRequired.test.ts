import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import BillingRequired from "@/pages/Onboarding/BillingRequired.svelte";
import type { BillingRequiredShape } from "@/types/onboarding";

const { pageState } = vi.hoisted(() => ({
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    page: pageState,
}));

/*
 * 課金手続き待ちの説明画面 (課金権限を持たないメンバーの着地先)。
 * 403 で突き放さず、組織管理者の連絡先 + 問い合わせ導線を出して「行き先のない詰み」を回避する。
 * owner 不在 org では ownerName / ownerEmail が null になりうる (描画が壊れないこと)。
 */

const organization = { id: 1, name: "テスト組織", slug: "test-org" };

const basePageData: BillingRequiredShape = {
    ownerName: "山田 太郎",
    ownerEmail: "owner@example.com",
    contactUrl: "/contact?source=onboarding",
};

afterEach(() => {
    cleanup();
    pageState.props = {};
});

function renderPage(overrides: Partial<BillingRequiredShape> = {}): void {
    render(BillingRequired, {
        props: { organization, pageData: { ...basePageData, ...overrides } },
    });
}

describe("Onboarding/BillingRequired", () => {
    it("組織名と待機の説明・管理者の連絡先・問い合わせ導線を出す", () => {
        renderPage();

        expect(screen.getByTestId("billing-required-heading")).toHaveTextContent("課金手続き中です");
        expect(screen.getByTestId("billing-required-message")).toHaveTextContent("テスト組織");
        expect(screen.getByTestId("billing-required-owner")).toHaveTextContent("山田 太郎");
        expect(screen.getByTestId("billing-required-owner-email")).toHaveAttribute(
            "href",
            "mailto:owner@example.com",
        );
        expect(screen.getByTestId("billing-required-contact-link")).toHaveAttribute(
            "href",
            "/contact?source=onboarding",
        );
    });

    it("ownerEmail が null でも管理者名の表示は壊れない", () => {
        renderPage({ ownerEmail: null });

        expect(screen.getByTestId("billing-required-owner")).toHaveTextContent("山田 太郎");
        expect(screen.queryByTestId("billing-required-owner-email")).not.toBeInTheDocument();
    });

    it("owner 不在 (ownerName / ownerEmail が null) でも問い合わせ導線は出る", () => {
        renderPage({ ownerName: null, ownerEmail: null });

        expect(screen.queryByTestId("billing-required-owner")).not.toBeInTheDocument();
        expect(screen.getByTestId("billing-required-message")).toHaveTextContent("テスト組織");
        expect(screen.getByTestId("billing-required-contact-link")).toHaveAttribute(
            "href",
            "/contact?source=onboarding",
        );
    });
});
