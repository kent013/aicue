import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import PlanCard from "@/pages/Billing/_helpers/PlanCard.svelte";
import type { PricingPlanShape } from "@/types/marketing";

/*
 * Billing/Plans の page-local カード。
 * - isCurrent で headerBadges に「現在のプラン」バッジが出る
 * - canSwitch=false でも CTA は enabled のまま (禁止事項 #8 / DESIGN.md)。理由は常時 caption +
 *   押下時 Alert で伝える
 * - features に D28 で撤去した「月 N 枚」表記が含まれない
 */

const plan: PricingPlanShape = {
    code: "standard",
    name: "Standard",
    baseAmountJpy: 4980,
    maxProjects: 10,
    maxMembers: 10,
    maxStorageGb: 50,
};

const formatLimit = (v: number | null): string => (v === null ? "無制限" : String(v));

afterEach(cleanup);

describe("Billing/_helpers/PlanCard", () => {
    it("isCurrent で「現在のプラン」バッジを出す", () => {
        render(PlanCard, {
            props: {
                plan,
                isCurrent: true,
                canSwitch: false,
                switchBlockedReason: "現在ご利用中のプランです",
                formatLimit,
                onSwitch: vi.fn(),
            },
        });

        expect(screen.getByTestId("plan-current-badge-standard")).toHaveTextContent("現在のプラン");
    });

    it("canSwitch=false でも disabled にせず、理由を caption と押下時 Alert で伝える", async () => {
        const onSwitch = vi.fn();
        render(PlanCard, {
            props: {
                plan,
                isCurrent: false,
                canSwitch: false,
                switchBlockedReason: "プランを変更する権限がありません",
                formatLimit,
                onSwitch,
            },
        });

        // 常時可視の理由 caption (disabled で情報を失わない)
        expect(screen.getByTestId("plan-switch-reason-standard")).toHaveTextContent(
            "プランを変更する権限がありません",
        );
        // disabled 属性の button は存在しない
        const cta = screen.getByTestId("plan-change-standard");
        expect(cta.hasAttribute("disabled")).toBe(false);
        expect(screen.queryByTestId("plan-switch-blocked")).toBeNull();

        await fireEvent.click(cta);
        expect(onSwitch).not.toHaveBeenCalled();
        expect(screen.getByTestId("plan-switch-blocked")).toHaveTextContent(
            "プランを変更する権限がありません",
        );
    });

    it("canSwitch=true の押下は onSwitch に plan code を渡す", async () => {
        const onSwitch = vi.fn();
        render(PlanCard, {
            props: {
                plan,
                isCurrent: false,
                canSwitch: true,
                switchBlockedReason: "",
                formatLimit,
                onSwitch,
            },
        });

        await fireEvent.click(screen.getByTestId("plan-change-standard"));
        expect(onSwitch).toHaveBeenCalledWith("standard");
        expect(screen.queryByTestId("plan-switch-blocked")).toBeNull();
    });

    it("features は quota 上限のみで「月 N 枚」表記を含まない (D28)", () => {
        render(PlanCard, {
            props: {
                plan,
                isCurrent: false,
                canSwitch: true,
                switchBlockedReason: "",
                formatLimit,
                onSwitch: vi.fn(),
            },
        });

        const card = screen.getByTestId("plan-card-standard");
        expect(card).toHaveTextContent("プロジェクト 10");
        expect(card).toHaveTextContent("メンバー 10 名");
        expect(card).toHaveTextContent("ストレージ 50 GB");
        expect(card.textContent ?? "").not.toMatch(/月\s*\d+\s*枚/);
    });
});
