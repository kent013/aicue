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
     * 変更は既存の Stripe Checkout (POST /billing/checkout。body は plan_code のみ) へ委譲する。
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

    // サーバ validation エラー (旧タブからの送信・未同期プラン等) は dialog 内に出す。
    const planCodeError = $derived.by<string | null>(() => {
        const errors = inertiaPage.props.errors as Record<string, string> | undefined;
        return errors?.plan_code ?? null;
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
        router.post(
            "/billing/checkout",
            { plan_code: planCode },
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
    message={`プランを「${planNameOf(confirmingPlanCode ?? "")}」に変更します。よろしいですか？お支払い手続きの画面 (Stripe) に移動します。`}
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
