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

    // F-3-01: 範囲エラーは原因フィールドの spinbutton へ aria-invalid + aria-describedby を配線し、
    // 巻き込みを避ける (両欄同時 invalid を作らない)。可視の統合 <p> は撤去し、読み上げは
    // 常在の sr-only polite live region が担う。以下は testId 非依存の利用者視点 assert。
    const thresholdInput = () =>
        screen.getByRole("spinbutton", { name: /リチャージ開始残高/ });
    const maxInput = () => screen.getByRole("spinbutton", { name: /リチャージ後の残高/ });

    it("max の範囲エラーは max spinbutton だけを invalid にする (F-3-01・押下時に提示)", async () => {
        renderCard({ hasPaymentMethod: true });

        // minCount(1) 未満 → parsedMax=null
        await fireEvent.input(maxInput(), { target: { value: "0" } });

        const enable = screen.getByTestId("auto-recharge-enable");
        expect(enable.hasAttribute("disabled")).toBe(false); // 押下でブロックしない (禁止事項 #8)
        await fireEvent.click(enable);

        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
        expect(maxInput()).toHaveAccessibleDescription(/リチャージ後の残高は 1 〜 1000 の整数/);
        // threshold は巻き込まない (値指定なし。Input は false 時に属性省略)
        expect(thresholdInput()).not.toHaveAttribute("aria-invalid");
        // エラー時は同意パネルを開かない
        expect(screen.queryByTestId("auto-recharge-consent")).toBeNull();
    });

    it("threshold の解析エラーは threshold spinbutton だけを invalid にする", async () => {
        renderCard({ hasPaymentMethod: true });

        // 負数 → parsedThreshold=null (非数値文字列は type=number の sanitize が DOM 依存なので使わない)
        await fireEvent.input(thresholdInput(), { target: { value: "-1" } });
        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));

        expect(thresholdInput()).toHaveAttribute("aria-invalid", "true");
        expect(thresholdInput()).toHaveAccessibleDescription(/リチャージ開始残高は 0 以上の整数/);
        expect(maxInput()).not.toHaveAttribute("aria-invalid");
    });

    it("個別有効だが max<=threshold のときは max spinbutton だけを invalid にする", async () => {
        renderCard({ hasPaymentMethod: true });

        // threshold=5(既定)・max=3 (1..1000 で個別有効かつ 3<=5) → 大小関係違反は max 側
        await fireEvent.input(maxInput(), { target: { value: "3" } });
        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));

        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
        expect(maxInput()).toHaveAccessibleDescription(/開始残高より大きい値/);
        expect(thresholdInput()).not.toHaveAttribute("aria-invalid");
    });

    it("押下前は aria-invalid が付かない (禁止事項 #8 の契約: 押下時に初めて提示する)", async () => {
        renderCard({ hasPaymentMethod: true });

        await fireEvent.input(maxInput(), { target: { value: "0" } });

        expect(maxInput()).not.toHaveAttribute("aria-invalid");
    });

    it("押下後に値を有効へ直すと aria-invalid が消える (F-3-05: stale invalid を残さない)", async () => {
        renderCard({ hasPaymentMethod: true });

        await fireEvent.input(maxInput(), { target: { value: "0" } });
        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
        expect(maxInput()).toHaveAttribute("aria-invalid", "true");

        // 値を有効な組み合わせへ直す → 表示中のエラーは現在の入力に追随して消える
        await fireEvent.input(maxInput(), { target: { value: "50" } });
        expect(maxInput()).not.toHaveAttribute("aria-invalid");
    });

    it("sr-only live region は常在し、押下後に本文が出て訂正で消える (可視 <p> 撤去の後退防止)", async () => {
        renderCard({ hasPaymentMethod: true });

        // 同一要素を使い続け、将来 {#if} に戻って要素差し替えになった場合も検出する
        const liveRegion = screen.getByTestId("auto-recharge-range-error");
        // (a) 押下前: 属性が生きていて本文は空 (aria-live が消えても素通りしない)
        expect(liveRegion).toHaveClass("sr-only");
        expect(liveRegion).toHaveAttribute("aria-live", "polite");
        expect(liveRegion).toBeEmptyDOMElement();

        // (b) max "0" + 押下後: 本文が単一アクティブ文言で出る
        await fireEvent.input(maxInput(), { target: { value: "0" } });
        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
        expect(liveRegion).toHaveTextContent(/リチャージ後の残高は 1 〜 1000 の整数/);

        // (c) 訂正後: 本文が消える
        await fireEvent.input(maxInput(), { target: { value: "50" } });
        expect(liveRegion).toBeEmptyDOMElement();
    });

    it("無効のまま別の無効理由に変えると文言と aria-invalid が現在の理由へ追随する (提示中の追随)", async () => {
        renderCard({ hasPaymentMethod: true });

        const liveRegion = screen.getByTestId("auto-recharge-range-error");
        // 範囲外 (minCount 1 未満) → max のみ invalid・「範囲」文言
        await fireEvent.input(maxInput(), { target: { value: "0" } });
        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
        expect(maxInput()).toHaveAccessibleDescription(/リチャージ後の残高は 1 〜 1000 の整数/);

        // 個別有効だが threshold(既定 5) 以下 = 大小関係違反へ理由が変わる (無効のまま)
        await fireEvent.input(maxInput(), { target: { value: "5" } });
        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
        expect(maxInput()).toHaveAccessibleDescription(/開始残高より大きい値/);
        // 同一 live region 要素の本文も同じ理由へ追随する
        expect(liveRegion).toHaveTextContent(/開始残高より大きい値/);
        // threshold は巻き込まない
        expect(thresholdInput()).not.toHaveAttribute("aria-invalid");
    });

    it("sr-only live region は threshold 側経路の文言も運ぶ ({maxError ?? \"\"} 誤実装を落とす)", async () => {
        renderCard({ hasPaymentMethod: true });

        const liveRegion = screen.getByTestId("auto-recharge-range-error");
        await fireEvent.input(thresholdInput(), { target: { value: "-1" } });
        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));

        expect(liveRegion).toHaveTextContent(/リチャージ開始残高は 0 以上の整数/);
    });

    it("canManage=false では両入力が readonly かつ muted になる (F-3-03)", () => {
        renderCard({ canManage: false, hasPaymentMethod: true });

        for (const testId of ["auto-recharge-threshold-input", "auto-recharge-max-input"]) {
            const input = screen.getByTestId(testId);
            expect(input).toHaveAttribute("readonly");
            const tokens = input.className.split(/\s+/);
            expect(tokens).toContain("bg-neutral");
            expect(tokens).toContain("cursor-default");
        }
    });

    it("canManage=true では入力は readonly でない (非退行)", () => {
        renderCard({ canManage: true, hasPaymentMethod: true });

        for (const testId of ["auto-recharge-threshold-input", "auto-recharge-max-input"]) {
            const input = screen.getByTestId(testId);
            expect(input).not.toHaveAttribute("readonly");
            expect(input.className.split(/\s+/)).toContain("bg-surface");
        }
    });
});
