import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import Index from "@/pages/Billing/Index.svelte";
import type { BillingDashboardProps } from "@/types/billing";

const { routerPostMock, pageState } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock, patch: vi.fn() },
    page: pageState,
}));

/*
 * 課金ダッシュボード。プラン一覧は /billing/plans へ移設済み (plan-list は持たない)。
 * per-bucket 残高 / quota 上限 / portal 出し分けを固定する。
 */

const basePage: BillingDashboardProps = {
    plan: {
        code: "personal",
        name: "Personal",
        baseAmountJpy: null,
        maxProjects: 1,
        maxMembers: 3,
        maxStorageGb: 1,
    },
    billingState: "active_free_plan",
    currentPeriodEnd: null,
    balance: {
        monthlyRemaining: 4,
        purchasedRemaining: 6,
        totalAvailable: 10,
        activeReservations: 0,
        nextExpireAt: null,
    },
    quotas: { maxProjects: 1, maxMembers: 3, maxStorageGb: 1 },
    canManageBilling: true,
    continueUrl: null,
    autoRecharge: {
        enabled: false,
        thresholdCount: 5,
        maxCount: 50,
        minCount: 1,
        maxCountLimit: 1000,
        canManage: true,
        hasPaymentMethod: false,
        paymentMethodBrand: null,
        paymentMethodLast4: null,
        setupPending: false,
        requiresReconsent: false,
        pendingAutoEnable: false,
        disabledReason: null,
        failureCount: 0,
        consentVersion: "v2",
        baseUnitAmountJpy: 100,
        tiers: [{ minCount: 1, unitAmount: 100 }],
    },
    autoRechargeSetupToken: "01j0000000000000000000test",
    feedback: null,
    billingContact: { email: null, name: null, fallbackEmail: "owner@example.test" },
};

afterEach(() => {
    cleanup();
    routerPostMock.mockReset();
    pageState.props = {};
});

describe("Billing/Index", () => {
    it("プラン一覧を持たず「プラン比較」導線を出す", () => {
        render(Index, { props: { page: basePage } });

        expect(screen.queryByTestId("plan-list")).toBeNull();
        expect(screen.getByTestId("billing-plans-link").getAttribute("href")).toContain(
            "/billing/plans",
        );
    });

    it("active_free_plan では portal ボタンを出さず「月額 無料（チケット代のみ）」を出す", () => {
        render(Index, { props: { page: basePage } });

        expect(screen.queryByTestId("billing-portal-button")).toBeNull();
        expect(screen.getByTestId("current-plan-card")).toHaveTextContent(
            "月額 無料（チケット代のみ）",
        );
        expect(screen.queryByTestId("current-period-end")).toBeNull();
    });

    it("subscribed では portal ボタンと次回請求日を出す", () => {
        render(Index, {
            props: {
                page: {
                    ...basePage,
                    billingState: "subscribed",
                    currentPeriodEnd: "2026-09-01T00:00:00+09:00",
                    plan: { ...basePage.plan!, code: "standard", name: "Standard", baseAmountJpy: 4980 },
                },
            },
        });

        expect(screen.getByTestId("billing-portal-button")).toBeInTheDocument();
        expect(screen.getByTestId("current-period-end")).toHaveTextContent("2026");
        expect(screen.getByTestId("current-plan-card")).toHaveTextContent("月額 ¥4,980");
    });

    it("per-bucket 残高を描画し、債務行を持たない", () => {
        render(Index, {
            props: {
                page: {
                    ...basePage,
                    balance: { ...basePage.balance, nextExpireAt: "2026-09-01T00:00:00+09:00" },
                },
            },
        });

        expect(screen.getByTestId("ticket-balance")).toHaveTextContent("10");
        expect(screen.getByTestId("balance-monthly")).toHaveTextContent("4");
        expect(screen.getByTestId("balance-purchased")).toHaveTextContent("6");
        expect(screen.getByTestId("balance-next-expire")).toHaveTextContent("次の失効");
        expect(screen.getByTestId("billing-balance").textContent ?? "").not.toContain("債務");
    });

    it("plan=null では未契約の案内を出す", () => {
        render(Index, {
            props: { page: { ...basePage, plan: null, billingState: "no_subscription" } },
        });

        expect(screen.getByTestId("no-plan-note")).toHaveTextContent("まだプランに契約していません");
    });

    it("quota 上限 (プロジェクト / メンバー / ストレージ) を描画する", () => {
        render(Index, { props: { page: basePage } });

        expect(screen.getByTestId("quota-max-projects")).toHaveTextContent("1");
        expect(screen.getByTestId("quota-max-members")).toHaveTextContent("3");
        expect(screen.getByTestId("quota-max-storage")).toHaveTextContent("1 GB");
    });

    it("auto-recharge カードの差し込み位置を持つ (実体は P8a 所管)", () => {
        render(Index, { props: { page: basePage } });

        expect(screen.getByTestId("auto-recharge-card")).toBeInTheDocument();
    });
});

describe("Billing/Index — 着地 feedback (P9)", () => {
    // T088 で PurchaseFormState::Completed を撤去したため、この one-shot が
    // 「購入完了をユーザーに知らせる唯一の経路」になっている。
    it.each([
        ["purchase_received", "お支払いを受け付けました。"],
        ["purchase_processing", "お支払いを確認しています。"],
        ["purchase_already_received", "既に受け付け済みです。"],
        ["checkout_retry_required", "有効期限が切れました。"],
        ["portal_returned", "お支払い管理画面から戻りました。"],
    ] as const)("kind=%s のバナーをサーバ文言で描画する", (kind, message) => {
        render(Index, { props: { page: { ...basePage, feedback: { kind, message } } } });

        expect(screen.getByTestId("billing-feedback")).toHaveTextContent(message);
        expect(screen.getByTestId(`billing-feedback-${kind}`)).toBeInTheDocument();
    });

    it("feedback=null では何も描画しない (リロードで消える one-shot)", () => {
        render(Index, { props: { page: basePage } });

        expect(screen.queryByTestId("billing-feedback")).toBeNull();
    });

    it("raw query を参照しない (?session_id があっても feedback=null なら描画しない)", () => {
        window.history.replaceState({}, "", "/billing?session_id=cs_test&replayed=1");
        render(Index, { props: { page: basePage } });

        expect(screen.queryByTestId("billing-feedback")).toBeNull();
        window.history.replaceState({}, "", "/billing");
    });

    it("?highlight=auto-recharge でオートリチャージカードを強調する", async () => {
        window.history.replaceState({}, "", "/billing?highlight=auto-recharge");
        render(Index, { props: { page: basePage } });

        await vi.waitFor(() => {
            expect(screen.getByTestId("auto-recharge-card")).toHaveAttribute(
                "data-highlighted",
                "true",
            );
        });
        window.history.replaceState({}, "", "/billing");
    });
});
