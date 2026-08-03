<script lang="ts">
    import { page as inertiaPage, router } from "@inertiajs/svelte";
    import { CreditCard } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { BillingPlansPageProps } from "@/types/billing";
    import type { PricingPlanShape } from "@/types/marketing";
    import PlanCard from "./_helpers/PlanCard.svelte";

    /**
     * プラン比較 (/billing/plans)。閲覧は組織メンバー全員、変更は manageBilling のみ。
     *
     * 送信先はサーバが決めた `hasChangeableSubscription` (= `Subscription::valid()`) で分岐する:
     * - 有効な契約あり → POST /billing/plan (in-app swap)。body は plan_code +
     *   current_plan_code (stale 検知の期待値) + plan_change_token (冪等 token)
     * - 契約なし → 従来の POST /billing/checkout。body は plan_code +
     *   subscription_attempt_token (funding_choice は載せない)
     * 判定述語がサーバと同一なので「押したら循環エラー」にならない。
     *
     * 変更できないプランでも CTA は enabled のまま描画し、理由は caption + 押下時 Alert で
     * 伝える (DESIGN.md / 禁止事項 #8)。
     */
    interface Props {
        page: BillingPlansPageProps;
    }

    let { page }: Props = $props();

    const shared = $derived(inertiaPage.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // サーバ validation エラー (旧タブからの送信・未同期プラン等) は dialog 内に出す
    // (3 キーのいずれか = 最初に見つかったもの)。
    const planCodeError = $derived.by<string | null>(() => {
        const errors = inertiaPage.props.errors as Record<string, string> | undefined;
        return errors?.plan_code ?? errors?.current_plan_code ?? errors?.plan_change_token ?? null;
    });

    const formatLimit = (value: number | null): string => (value === null ? "無制限" : String(value));

    // Personal は個人専用の無料プラン。有効化は onboarding 経路のため本画面からは変更しない。
    const isPersonal = (plan: PricingPlanShape): boolean => plan.code === "personal";

    const canSwitchTo = (plan: PricingPlanShape): boolean => {
        if (!page.canManage) return false;
        if (page.currentPlanCode === plan.code) return false;
        if (isPersonal(plan)) return false;
        return true;
    };

    // canSwitchTo の各分岐に 1:1 対応する理由文言 (canSwitch=true では空文字)。
    const switchBlockedReasonFor = (plan: PricingPlanShape): string => {
        if (!page.canManage) return "プランを変更する権限がありません";
        if (page.currentPlanCode === plan.code) return "現在ご利用中のプランです";
        if (isPersonal(plan)) {
            return "パーソナルプラン（無料）は個人専用のため、こちらからは変更できません";
        }
        return "";
    };

    let confirmingPlanCode = $state<string | null>(null);
    let confirmOpen = $state(false);
    let submitting = $state(false);

    const planNameOf = (code: string): string =>
        page.plans.find((plan) => plan.code === code)?.name ?? code;

    const targetPlan = $derived(page.plans.find((plan) => plan.code === confirmingPlanCode) ?? null);
    const currentPlanAmount = $derived(
        page.plans.find((plan) => plan.code === page.currentPlanCode)?.baseAmountJpy ?? null,
    );
    // 金額比較は**文言の出し分けにのみ**使う (可否判定はサーバ)。
    const isDowngrade = $derived(
        page.hasChangeableSubscription &&
            targetPlan !== null &&
            currentPlanAmount !== null &&
            (targetPlan.baseAmountJpy ?? 0) < currentPlanAmount,
    );

    const confirmMessage = $derived.by<string>(() => {
        const name = targetPlan?.name ?? planNameOf(confirmingPlanCode ?? "");
        if (!page.hasChangeableSubscription) {
            return `プランを「${name}」に変更します。よろしいですか？お支払い手続きの画面 (Stripe) に移動します。`;
        }
        const base =
            `プランを「${name}」に変更します。変更は Stripe 側に即時反映され` +
            `(画面表示への反映は数分かかる場合があります)、差額は日割りで次回のご請求に調整されます。`;
        return isDowngrade
            ? base +
                  "新しいプランの上限 (プロジェクト数・メンバー数・保存容量) を超えている場合、" +
                  "既存のデータは削除されませんが、上限内に収まるまで新規作成とアップロードができません。"
            : base;
    });

    function openConfirm(planCode: string): void {
        confirmingPlanCode = planCode;
        confirmOpen = true;
    }

    function closeConfirm(): void {
        confirmingPlanCode = null;
    }

    function submitPlanChange(): void {
        const planCode = confirmingPlanCode;
        if (planCode === null || submitting) return;

        // 有効な契約がある組織は in-app swap、無い組織は従来の Checkout。
        // 判定述語はサーバ (Subscription::valid()) と同一なので循環エラーにならない。
        const url = page.hasChangeableSubscription ? "/billing/plan" : "/billing/checkout";
        const payload = page.hasChangeableSubscription
            ? {
                  plan_code: planCode,
                  // 表示用 currentPlanCode ではなく競合制御用の期待値を送る
                  current_plan_code: page.planChangeExpectedPlanCode,
                  plan_change_token: page.planChangeToken,
              }
            : { plan_code: planCode, subscription_attempt_token: page.subscriptionAttemptToken };

        router.post(
            url,
            payload,
            {
                onStart: () => {
                    submitting = true;
                },
                onFinish: () => {
                    submitting = false;
                },
                // 成功時のみ閉じる (validation error 時は開いたままサーバ文言を出す)
                onSuccess: () => {
                    confirmOpen = false;
                    confirmingPlanCode = null;
                },
            },
        );
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="プラン比較"
            description="現在のプランの変更・新規契約ができます"
            icon={CreditCard}
            testId="billing-plans-heading"
        />
        <PageContent>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="plans-grid">
                {#each page.plans as plan (plan.code)}
                    <PlanCard
                        {plan}
                        isCurrent={page.currentPlanCode === plan.code}
                        canSwitch={canSwitchTo(plan)}
                        switchBlockedReason={switchBlockedReasonFor(plan)}
                        {formatLimit}
                        onSwitch={openConfirm}
                    />
                {/each}
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>

<ConfirmDialog
    bind:open={confirmOpen}
    title="プラン変更の確認"
    message={confirmMessage}
    confirmLabel="変更する"
    processing={submitting}
    onConfirm={submitPlanChange}
    onCancel={closeConfirm}
    testId="plan-change-confirm"
>
    {#snippet banner()}
        {#if planCodeError !== null}
            <div class="mb-3">
                <Alert type="danger" testId="plan-change-error">{planCodeError}</Alert>
            </div>
        {/if}
    {/snippet}
</ConfirmDialog>
