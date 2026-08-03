import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import Plans from "@/pages/Billing/Plans.svelte";
import type { BillingPlansPageProps } from "@/types/billing";

const { routerPostMock, pageState } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock },
    page: pageState,
}));

/*
 * プラン比較ページ。確認ダイアログ経由で POST /billing/checkout に plan_code +
 * subscription_attempt_token を送る (funding_choice は載せない)。
 * サーバ validation エラー時は dialog を開いたままサーバ文言を出す。
 */

const basePage: BillingPlansPageProps = {
    plans: [
        {
            code: "personal",
            name: "Personal",
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
    currentPlanCode: "personal",
    billingState: "active_free_plan",
    canManage: true,
    subscriptionAttemptToken: "01JQ0000000000000000000000",
    hasChangeableSubscription: false,
    planChangeToken: "01JQ1111111111111111111111",
    planChangeExpectedPlanCode: null,
};

/** 有効な契約がある組織 (starter 契約中 → in-app swap 経路) の props */
const contractedPage: BillingPlansPageProps = {
    ...basePage,
    plans: [
        ...basePage.plans,
        {
            code: "starter",
            name: "Starter",
            baseAmountJpy: 980,
            maxProjects: 3,
            maxMembers: 5,
            maxStorageGb: 10,
        },
    ],
    currentPlanCode: "starter",
    billingState: "subscribed",
    hasChangeableSubscription: true,
    planChangeExpectedPlanCode: "starter",
};

afterEach(() => {
    cleanup();
    routerPostMock.mockReset();
    pageState.props = {};
});

describe("Billing/Plans", () => {
    it("plans-grid に全プランを描画し、現在プランにバッジを出す", () => {
        render(Plans, { props: { page: basePage } });

        expect(screen.getByTestId("plans-grid")).toBeInTheDocument();
        expect(screen.getByTestId("plan-card-personal")).toBeInTheDocument();
        expect(screen.getByTestId("plan-card-standard")).toBeInTheDocument();
        expect(screen.getByTestId("plan-current-badge-personal")).toHaveTextContent("現在のプラン");
    });

    it("「このプランへ変更」→ 確認 → plan_code + 冪等 token を POST する (funding_choice は載せない)", async () => {
        render(Plans, { props: { page: basePage } });

        await fireEvent.click(screen.getByTestId("plan-change-standard"));
        const dialog = await screen.findByTestId("plan-change-confirm");
        expect(dialog).toHaveTextContent("Standard");

        await fireEvent.click(screen.getByText("変更する"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        const [url, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe("/billing/checkout");
        expect(payload).toEqual({
            plan_code: "standard",
            subscription_attempt_token: "01JQ0000000000000000000000",
        });
        expect(payload).not.toHaveProperty("funding_choice");
    });

    it("errors.plan_code があるとき dialog にサーバ文言を描画する", async () => {
        pageState.props = { errors: { plan_code: "選択したプランは現在お申し込みいただけません。" } };
        render(Plans, { props: { page: basePage } });

        await fireEvent.click(screen.getByTestId("plan-change-standard"));
        await screen.findByTestId("plan-change-confirm");

        expect(screen.getByTestId("plan-change-error")).toHaveTextContent(
            "選択したプランは現在お申し込みいただけません。",
        );
    });

    it("有効な契約があるときは /billing/plan へ swap payload を POST する", async () => {
        render(Plans, { props: { page: contractedPage } });

        await fireEvent.click(screen.getByTestId("plan-change-standard"));
        await screen.findByTestId("plan-change-confirm");
        await fireEvent.click(screen.getByText("変更する"));

        const [url, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe("/billing/plan");
        expect(payload).toEqual({
            plan_code: "standard",
            current_plan_code: "starter",
            plan_change_token: "01JQ1111111111111111111111",
        });
        expect(payload).not.toHaveProperty("subscription_attempt_token");
    });

    it("current_plan_code は表示用 currentPlanCode ではなく planChangeExpectedPlanCode を送る", async () => {
        // grace period 契約では表示用 (personal) と競合制御値 (starter) が食い違う
        render(Plans, {
            props: {
                page: {
                    ...contractedPage,
                    currentPlanCode: "personal",
                    planChangeExpectedPlanCode: "starter",
                },
            },
        });

        await fireEvent.click(screen.getByTestId("plan-change-standard"));
        await screen.findByTestId("plan-change-confirm");
        await fireEvent.click(screen.getByText("変更する"));

        const [, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
        expect(payload.current_plan_code).toBe("starter");
    });

    it("downgrade の確認ダイアログは上限低下を告知し、upgrade では出さない", async () => {
        const downgrading = {
            ...contractedPage,
            currentPlanCode: "standard",
            planChangeExpectedPlanCode: "standard",
        };
        render(Plans, { props: { page: downgrading } });

        await fireEvent.click(screen.getByTestId("plan-change-starter"));
        const dialog = await screen.findByTestId("plan-change-confirm");
        expect(dialog).toHaveTextContent("上限内に収まるまで新規作成とアップロードができません");
        cleanup();

        render(Plans, { props: { page: contractedPage } });
        await fireEvent.click(screen.getByTestId("plan-change-standard"));
        const upgradeDialog = await screen.findByTestId("plan-change-confirm");
        expect(upgradeDialog).not.toHaveTextContent(
            "上限内に収まるまで新規作成とアップロードができません",
        );
        expect(upgradeDialog).toHaveTextContent("日割り");
    });

    it("errors.current_plan_code だけでも dialog にサーバ文言を描画する", async () => {
        pageState.props = { errors: { current_plan_code: "プランが別の操作で変更されました。" } };
        render(Plans, { props: { page: contractedPage } });

        await fireEvent.click(screen.getByTestId("plan-change-standard"));
        await screen.findByTestId("plan-change-confirm");

        expect(screen.getByTestId("plan-change-error")).toHaveTextContent(
            "プランが別の操作で変更されました。",
        );
    });

    it("canManage=false でも CTA は enabled のまま (押下で理由を出す)", async () => {
        render(Plans, { props: { page: { ...basePage, canManage: false } } });

        const cta = screen.getByTestId("plan-change-standard");
        expect(cta.hasAttribute("disabled")).toBe(false);

        await fireEvent.click(cta);
        expect(routerPostMock).not.toHaveBeenCalled();
        expect(screen.getByTestId("plan-switch-blocked")).toHaveTextContent(
            "プランを変更する権限がありません",
        );
    });

    it("personal は本画面から変更できない旨を常時 caption で示す", () => {
        render(Plans, { props: { page: basePage } });

        expect(screen.getByTestId("plan-switch-reason-personal")).toHaveTextContent(
            "現在ご利用中のプランです",
        );
    });
});
