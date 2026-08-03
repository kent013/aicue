import type { AutoRechargeProps } from "@/types/billing";

/**
 * AutoRechargeCard の props factory (P8a)。
 * 既定は「opt-in 未設定」= enabled=false / カード未登録 の状態。
 */
export function autoRechargeProps(overrides: Partial<AutoRechargeProps> = {}): AutoRechargeProps {
    return {
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
        consentVersion: "v1",
        baseUnitAmountJpy: 100,
        tiers: [
            { minCount: 1, unitAmount: 100 },
            { minCount: 20, unitAmount: 80 },
            { minCount: 50, unitAmount: 70 },
        ],
        ...overrides,
    };
}
