import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import Checkout from "@/pages/Onboarding/Checkout.svelte";
import type { OnboardingCheckoutShape } from "@/types/onboarding";

// router.post をモックする。page (Inertia store) も hoisted fake でモックし、props.errors を
// 注入して「押下 → サーバが redirect-back で返した文言を表示する」経路 (D4) を検証する。
const { routerPostMock, pageState } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: {
        post: routerPostMock,
    },
    page: pageState,
}));

/*
 * 課金オンボーディングのプラン選択画面。
 * - plan grid はサーバが露出したプラン (is_active=true ∧ PlanCode 集合) のみを出す
 * - D4 (AGENTS.md 禁止事項 #8): 必須条件未充足でも CTA は押せ、押下後にサーバ文言を表示する
 *   (文言はすべてサーバ確定 = frontend で組み立てない)
 * - D28: 月次チケット付与は廃止済 = 「月 N 枚」表記を出さない
 */

const organization = { id: 1, name: "テスト組織", slug: "test-org" };

const basePageData: OnboardingCheckoutShape = {
    plans: [
        { code: "personal", name: "Personal", currentBaseAmount: null, isActive: true },
        { code: "starter", name: "Starter", currentBaseAmount: 4980, isActive: true },
        { code: "standard", name: "Standard", currentBaseAmount: 19800, isActive: true },
    ],
    recommendedPlanCode: "standard",
    defaultPlanCode: "starter",
    contactUrl: "/contact?source=onboarding",
    personalEligibility: { eligible: true, reason: null, reasonLabel: null },
    signupGrantTickets: 10,
};

afterEach(() => {
    cleanup();
    routerPostMock.mockReset();
    pageState.props = {}; // errors 注入をリセット (テスト間の汚染防止)
});

function renderPage(overrides: Partial<OnboardingCheckoutShape> = {}): void {
    render(Checkout, {
        props: { organization, pageData: { ...basePageData, ...overrides } },
    });
}

async function choosePersonal(): Promise<void> {
    await fireEvent.click(screen.getByTestId("select-plan-personal"));
}

describe("Onboarding/Checkout", () => {
    it("サーバが露出したプランのみを plan grid に出す (未露出 code は出ない)", () => {
        renderPage();

        expect(screen.getByTestId("plan-card-personal")).toBeInTheDocument();
        expect(screen.getByTestId("plan-card-starter")).toBeInTheDocument();
        expect(screen.getByTestId("plan-card-standard")).toBeInTheDocument();
        // business / enterprise / legacy free はサーバの露出規則 (is_active=true ∧ PlanCode 集合)
        // から外れるため props に来ない = 描画されない
        expect(screen.queryByTestId("plan-card-business")).not.toBeInTheDocument();
        expect(screen.queryByTestId("plan-card-free")).not.toBeInTheDocument();
        expect(screen.getByTestId("recommended-badge-standard")).toBeInTheDocument();
        // Personal は基本料金なし = 無料表示契約
        expect(screen.getByTestId("plan-card-personal")).toHaveTextContent("無料");
    });

    it("defaultPlanCode が plans にあるときはそれを強調する", () => {
        renderPage();

        expect(screen.getByTestId("plan-card-starter")).toHaveClass("border-primary");
        expect(screen.getByTestId("plan-card-personal")).not.toHaveClass("border-primary");
    });

    it("defaultPlanCode が plans に無いときは先頭 plan を強調する", () => {
        renderPage({ defaultPlanCode: "business" });

        expect(screen.getByTestId("plan-card-personal")).toHaveClass("border-primary");
        expect(screen.getByTestId("plan-card-starter")).not.toHaveClass("border-primary");
    });

    it("月次付与は廃止済のため「月 N 枚」表記を出さない (D28)", async () => {
        renderPage();
        await choosePersonal();

        expect(screen.getByTestId("onboarding-checkout").textContent ?? "").not.toMatch(
            /月\s*\d+\s*枚/,
        );
    });

    it("declaration 未チェックでも Personal CTA は押せ、declaration=0 で送信する", async () => {
        renderPage();
        await choosePersonal();

        const submit = screen.getByTestId("personal-free-submit");
        expect(submit).not.toBeDisabled();

        await fireEvent.click(submit);
        expect(routerPostMock).toHaveBeenCalledWith(
            "/onboarding/activate-personal",
            { declaration: "0" },
            expect.anything(),
        );
    });

    it("declaration 未チェックで押下した後、サーバが返した validation error を表示する", async () => {
        // redirect-back 着地 (errors 付き props) を再現する
        pageState.props = { errors: { declaration: "個人利用であることの確認が必要です。" } };
        renderPage();
        await choosePersonal();

        expect(screen.getByText("個人利用であることの確認が必要です。")).toBeInTheDocument();
    });

    it("declaration チェック時は declaration=1 で送信する", async () => {
        renderPage();
        await choosePersonal();

        await fireEvent.click(screen.getByTestId("personal-declaration"));
        await fireEvent.click(screen.getByTestId("personal-free-submit"));

        expect(routerPostMock).toHaveBeenCalledWith(
            "/onboarding/activate-personal",
            { declaration: "1" },
            expect.anything(),
        );
    });

    it("eligible=false でも Personal は選択でき CTA も押せる (理由はサーバ由来文言を常時提示)", async () => {
        renderPage({
            personalEligibility: {
                eligible: false,
                reason: "organization_has_multiple_members",
                reasonLabel: "メンバーが 2 名以上の組織では選択できません",
            },
        });

        expect(screen.getByTestId("personal-eligibility-reason")).toHaveTextContent(
            "メンバーが 2 名以上の組織では選択できません",
        );

        const selectPersonal = screen.getByTestId("select-plan-personal");
        expect(selectPersonal).not.toBeDisabled();
        await fireEvent.click(selectPersonal);

        const submit = screen.getByTestId("personal-free-submit");
        expect(submit).not.toBeDisabled();
        await fireEvent.click(submit);
        expect(routerPostMock).toHaveBeenCalledTimes(1);
    });

    it("eligible=false の押下後にサーバ確定文言 (errors.plan_code) を表示する", async () => {
        pageState.props = { errors: { plan_code: "有効な契約がある組織では選択できません" } };
        renderPage({
            personalEligibility: {
                eligible: false,
                reason: "organization_has_active_subscription",
                reasonLabel: "有効な契約がある組織では選択できません",
            },
        });
        await choosePersonal();

        // 押下前は plan エラーを出さない (押下したプランに紐づけて表示する)
        expect(screen.queryByTestId("checkout-plan-error")).not.toBeInTheDocument();

        await fireEvent.click(screen.getByTestId("personal-free-submit"));
        expect(screen.getByTestId("checkout-plan-error")).toHaveTextContent(
            "有効な契約がある組織では選択できません",
        );
    });

    it("eligibility が null でも描画が壊れず CTA は押せる", async () => {
        renderPage({ personalEligibility: null });
        await choosePersonal();

        expect(screen.queryByTestId("personal-eligibility-reason")).not.toBeInTheDocument();
        await fireEvent.click(screen.getByTestId("personal-free-submit"));
        expect(routerPostMock).toHaveBeenCalledTimes(1);
    });

    it("有償プランは plan_code のみを課金 checkout に送る", async () => {
        renderPage();

        await fireEvent.click(screen.getByTestId("select-plan-starter"));
        expect(screen.queryByTestId("personal-free-step")).not.toBeInTheDocument();

        await fireEvent.click(screen.getByTestId("paid-plan-submit"));
        expect(routerPostMock).toHaveBeenCalledWith(
            "/billing/checkout",
            { plan_code: "starter" },
            expect.anything(),
        );
    });

    it("無償プラン (personal) は有償 checkout へ送らない (Stripe checkout へ混入させない)", async () => {
        // props の plans を単一真実源に「基本料金を持つものだけ有償」と判定する。
        // personal は currentBaseAmount=null なので、仮に有償 submit 経路へ到達しても送信しない。
        renderPage();
        await choosePersonal();

        // personal 選択時は有償 submit ボタン自体が出ない (UI 分岐)
        expect(screen.queryByTestId("paid-plan-submit")).not.toBeInTheDocument();
        // 無償プランの自己申告 submit は billing/checkout ではなく activate-personal へ行く
        await fireEvent.click(screen.getByTestId("personal-free-submit"));
        expect(routerPostMock).toHaveBeenCalledWith(
            "/onboarding/activate-personal",
            expect.anything(),
            expect.anything(),
        );
        expect(routerPostMock).not.toHaveBeenCalledWith(
            "/billing/checkout",
            expect.anything(),
            expect.anything(),
        );
    });

    it("プランを切り替えると前のプランで出たエラーが消える", async () => {
        pageState.props = { errors: { plan_code: "パーソナルプランは選択できません" } };
        renderPage();
        await choosePersonal();
        await fireEvent.click(screen.getByTestId("personal-free-submit"));
        expect(screen.getByTestId("checkout-plan-error")).toBeInTheDocument();

        await fireEvent.click(screen.getByTestId("select-plan-standard"));
        expect(screen.queryByTestId("checkout-plan-error")).not.toBeInTheDocument();
    });

    it("問い合わせ導線 (Enterprise) をサーバ由来 URL で出す", () => {
        renderPage();

        expect(screen.getByTestId("onboarding-contact-link")).toHaveAttribute(
            "href",
            "/contact?source=onboarding",
        );
    });
});
