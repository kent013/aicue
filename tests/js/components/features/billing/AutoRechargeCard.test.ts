import { describe, it, expect, beforeEach } from "vitest";
import { render, cleanup, screen, fireEvent } from "@testing-library/svelte";
import AutoRechargeCard from "@/components/features/billing/AutoRechargeCard.svelte";
import { autoRechargeProps } from "../../../support/autoRechargeProps";
import type { AutoRechargeProps } from "@/types/billing";

// P8a: オートリチャージ設定カード。既定 off の opt-in で、有効化は同意を挟む fail-closed。

const renderCard = (overrides: Partial<AutoRechargeProps> = {}) =>
    render(AutoRechargeCard, {
        props: {
            autoRecharge: autoRechargeProps(overrides),
            updateUrl: "/billing/auto-recharge",
            setupUrl: "/billing/auto-recharge/setup",
            setupAttemptToken: "01hzzzzzzzzzzzzzzzzzzzzzzz",
        },
    });

describe("AutoRechargeCard", () => {
    beforeEach(() => cleanup());

    it("既定は無効表示で、有効時のステータス文は出ない", () => {
        renderCard();

        expect(screen.getByTestId("auto-recharge-state-badge").textContent?.trim()).toBe("無効");
        expect(screen.queryByTestId("auto-recharge-status")).toBeNull();
    });

    it("カード未登録ではカード登録 CTA を出し、有効化ボタンは出さない (fail-closed)", () => {
        renderCard({ hasPaymentMethod: false });

        expect(screen.getByTestId("auto-recharge-setup")).not.toBeNull();
        expect(screen.queryByTestId("auto-recharge-enable")).toBeNull();
        // 設定値の保存だけは許可する (カード登録前でも閾値・上限を決められる)
        expect(screen.getByTestId("auto-recharge-save-draft")).not.toBeNull();
    });

    it("カード登録済みなら有効化ボタンとカード情報を出す", () => {
        renderCard({ hasPaymentMethod: true, paymentMethodBrand: "visa", paymentMethodLast4: "4242" });

        expect(screen.getByTestId("auto-recharge-enable")).not.toBeNull();
        expect(screen.getByTestId("auto-recharge-pm").textContent).toContain("4242");
    });

    it("有効化ボタン押下で同意パネルを開く (同意なしに課金設定を確定させない)", async () => {
        renderCard({ hasPaymentMethod: true });

        expect(screen.queryByTestId("auto-recharge-consent")).toBeNull();
        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));

        const consent = screen.getByTestId("auto-recharge-consent");
        expect(consent.textContent).toContain("残高が 5 枚を下回ると");
        expect(consent.textContent).toContain("50 枚まで補充します");
        expect(screen.getByTestId("auto-recharge-consent-confirm")).not.toBeNull();
    });

    it("有効時は「設定を更新する」と「停止する」を出す (停止は常に押せる)", () => {
        renderCard({ enabled: true, hasPaymentMethod: true });

        expect(screen.getByTestId("auto-recharge-update")).not.toBeNull();
        const disable = screen.getByTestId("auto-recharge-disable");
        expect(disable.hasAttribute("disabled")).toBe(false);
        expect(screen.getByTestId("auto-recharge-status")).not.toBeNull();
    });

    it("requiresReconsent で「再同意まで自動購入は行われません」旨のバナーを出す", () => {
        renderCard({ enabled: true, hasPaymentMethod: true, requiresReconsent: true });

        const banner = screen.getByTestId("auto-recharge-reconsent-banner");
        expect(banner.textContent).toContain("再度同意するまで、自動購入は行われません");
    });

    it("連続失敗の自動停止では danger バナーと「自動停止中」バッジを出す", () => {
        renderCard({ disabledReason: "payment_failures", failureCount: 3 });

        expect(screen.getByTestId("auto-recharge-failure-banner")).not.toBeNull();
        expect(screen.getByTestId("auto-recharge-state-badge").textContent?.trim()).toBe(
            "自動停止中",
        );
    });

    it("pendingAutoEnable ではカード登録完了で自動有効化される旨を出す", () => {
        renderCard({ pendingAutoEnable: true });

        expect(screen.getByTestId("auto-recharge-no-pm").textContent).toContain(
            "カード登録が完了すると",
        );
    });

    it("setupPending では処理中表示に切り替わる", () => {
        renderCard({ setupPending: true });

        expect(screen.getByTestId("auto-recharge-setup-pending")).not.toBeNull();
        expect(screen.queryByTestId("auto-recharge-setup")).toBeNull();
    });

    it("canManage=false では操作ボタンを出さず理由を提示する (disabled でブロックしない)", () => {
        renderCard({ canManage: false, hasPaymentMethod: true });

        expect(screen.queryByTestId("auto-recharge-enable")).toBeNull();
        expect(screen.queryByTestId("auto-recharge-disable")).toBeNull();
        expect(screen.getByTestId("auto-recharge-readonly").textContent).toContain(
            "管理者権限が必要です",
        );
    });

    it("上限額は tier 単価で算出して表示する (maxCount=50 → 70 円 × 50)", () => {
        renderCard();

        expect(screen.getByTestId("auto-recharge-max-amount").textContent).toContain("¥3,500");
    });

    it("不正な入力でもボタンは押せて、押下時にエラーを表示する (禁止事項 #8)", async () => {
        renderCard({ hasPaymentMethod: true });

        const maxInput = screen.getByTestId("auto-recharge-max-input");
        await fireEvent.input(maxInput, { target: { value: "0" } });

        const enable = screen.getByTestId("auto-recharge-enable");
        expect(enable.hasAttribute("disabled")).toBe(false);

        await fireEvent.click(enable);
        expect(screen.getByTestId("auto-recharge-range-error")).not.toBeNull();
        // エラー時は同意パネルを開かない
        expect(screen.queryByTestId("auto-recharge-consent")).toBeNull();
    });
});
