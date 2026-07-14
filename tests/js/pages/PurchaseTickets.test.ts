import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import PurchaseTickets from "@/pages/Billing/PurchaseTickets.svelte";
import type { PurchaseTicketsPageProps } from "@/types/billing";

// router.post をモックする。page (Inertia store) も hoisted fake でモックし、
// props.errors を注入して serverErrors 経路を検証できるようにする (既定は空 = 従来挙動)。
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
 * チケット購入画面。傾斜単価の即時計算・role-aware 案内・purchased バナー・
 * disabled 不使用 (DESIGN.md) を固定する。
 */

const basePage: PurchaseTicketsPageProps = {
    tiers: [
        { minCount: 1, unitAmount: 100 },
        { minCount: 20, unitAmount: 80 },
        { minCount: 50, unitAmount: 70 },
        { minCount: 100, unitAmount: 65 },
        { minCount: 200, unitAmount: 60 },
        { minCount: 300, unitAmount: 55 },
        { minCount: 500, unitAmount: 50 },
    ],
    minCount: 1,
    maxCount: 1000,
    defaultCount: 10,
    balance: 3,
    canManage: true,
    attemptToken: "01J0000000000000000000TEST",
    purchased: false,
};

afterEach(() => {
    cleanup();
    routerPostMock.mockReset();
    pageState.props = {}; // errors 注入をリセット (テスト間の汚染防止)
});

async function setCount(value: string): Promise<void> {
    const input = screen.getByTestId("ticket-count-input");
    await fireEvent.input(input, { target: { value } });
}

describe("Billing/PurchaseTickets", () => {
    it("残高と傾斜表・既定枚数の単価計算 (10 枚 → spot ¥100) を表示する", () => {
        render(PurchaseTickets, { props: { page: basePage } });

        expect(screen.getByTestId("purchase-balance-count")).toHaveTextContent("3 枚");
        expect(screen.getByTestId("purchase-tier-table")).toHaveTextContent("1〜19 枚");
        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
            "単価 ¥100 × 10 枚 = 合計 ¥1,000",
        );
    });

    it("枚数に応じて適用単価が切り替わる (19→¥100 / 20→¥80 / 500→¥50)", async () => {
        render(PurchaseTickets, { props: { page: basePage } });

        await setCount("19");
        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
            "単価 ¥100 × 19 枚 = 合計 ¥1,900",
        );

        await setCount("20");
        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
            "単価 ¥80 × 20 枚 = 合計 ¥1,600",
        );

        await setCount("500");
        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
            "単価 ¥50 × 500 枚 = 合計 ¥25,000",
        );
    });

    it("範囲外は合計を出さず、押下時にエラー表示して送信しない (disabled にしない)", async () => {
        render(PurchaseTickets, { props: { page: basePage } });

        await setCount("1001");
        expect(screen.getByTestId("purchase-total")).toHaveTextContent("合計 —");

        const submit = screen.getByTestId("purchase-submit");
        expect(submit.hasAttribute("disabled")).toBe(false);

        await fireEvent.click(submit);
        expect(routerPostMock).not.toHaveBeenCalled();
        expect(
            screen.getByText("購入枚数は 1〜1000 の整数で入力してください"),
        ).toBeInTheDocument();
    });

    it("妥当な枚数の押下で count + attempt_token を POST する", async () => {
        render(PurchaseTickets, { props: { page: basePage } });

        await setCount("30");
        await fireEvent.click(screen.getByTestId("purchase-submit"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        const [url, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe("/purchase-tickets/checkout");
        expect(payload).toEqual({
            count: 30,
            attempt_token: "01J0000000000000000000TEST",
        });
    });

    it("canManage=false では購入フォームの代わりに管理者依頼の案内を出す", () => {
        render(PurchaseTickets, {
            props: { page: { ...basePage, canManage: false } },
        });

        expect(screen.queryByTestId("purchase-form")).toBeNull();
        expect(screen.getByTestId("purchase-role-note")).toHaveTextContent(
            "チケットの購入は組織のオーナーまたは管理者が行えます",
        );
        // 透明性維持: 残高・料金表は表示する
        expect(screen.getByTestId("purchase-balance-count")).toBeInTheDocument();
        expect(screen.getByTestId("purchase-tier-table")).toBeInTheDocument();
    });

    it("範囲外送信でエラー表示後、有効値に修正するとエラーが消え invalid が外れる", async () => {
        render(PurchaseTickets, { props: { page: basePage } });

        // 範囲外 (1001) を入力して押下 → client-side エラー表示
        await setCount("1001");
        await fireEvent.click(screen.getByTestId("purchase-submit"));
        expect(routerPostMock).not.toHaveBeenCalled();
        expect(
            screen.getByText("購入枚数は 1〜1000 の整数で入力してください"),
        ).toBeInTheDocument();
        // invalid が立っている (FormField 経由で aria-invalid)
        expect(screen.getByTestId("ticket-count-input")).toHaveAttribute("aria-invalid", "true");

        // 送信し直さずに有効値 (20) へ修正 → エラー消失 + invalid 解除 + 合計再計算
        await setCount("20");
        expect(
            screen.queryByText("購入枚数は 1〜1000 の整数で入力してください"),
        ).toBeNull();
        expect(screen.getByTestId("ticket-count-input")).not.toHaveAttribute("aria-invalid");
        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
            "単価 ¥80 × 20 枚 = 合計 ¥1,600",
        );
    });

    it("無効値のまま別の無効値へ変えてもエラーは残る (過剰クリアしない)", async () => {
        render(PurchaseTickets, { props: { page: basePage } });

        await setCount("1001");
        await fireEvent.click(screen.getByTestId("purchase-submit"));
        expect(
            screen.getByText("購入枚数は 1〜1000 の整数で入力してください"),
        ).toBeInTheDocument();

        // 別の無効値 (2002) へ変えてもエラーは残る (押下時にエラー表示の契約維持)
        await setCount("2002");
        expect(
            screen.getByText("購入枚数は 1〜1000 の整数で入力してください"),
        ).toBeInTheDocument();
        expect(screen.getByTestId("purchase-total")).toHaveTextContent("合計 —");
    });

    it("serverErrors.count がある場合は有効値に修正してもエラー表示が残る", async () => {
        pageState.props = { errors: { count: "サーバ側で拒否されました" } };
        render(PurchaseTickets, { props: { page: basePage } });

        // 有効値でも server 由来エラーは残る (本 effect の対象は clientError のみ)
        await setCount("20");
        expect(screen.getByText("サーバ側で拒否されました")).toBeInTheDocument();
        expect(screen.getByTestId("ticket-count-input")).toHaveAttribute("aria-invalid", "true");
    });

    it("purchased=true で反映待ちバナーを表示する", () => {
        render(PurchaseTickets, {
            props: { page: { ...basePage, purchased: true } },
        });

        expect(screen.getByTestId("purchase-success-banner")).toHaveTextContent(
            "決済の確認後、残高に反映されます",
        );
    });
});
