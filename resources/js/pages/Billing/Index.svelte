<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import { CreditCard } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * 課金ページ (現在プラン / チケット残高 / プラン一覧)。
     * プラン変更は Stripe Checkout、支払い方法・解約は Customer Portal 経由
     * (POST → Inertia::location で Stripe へ full page redirect)。
     * manageBilling 権限 (owner / admin) が無いメンバーは閲覧のみ。
     */
    interface PlanPrice {
        unitAmount: number;
        currency: string;
    }

    interface Plan {
        code: string;
        name: string;
        price: PlanPrice | null;
    }

    interface Props {
        plans: Plan[];
        currentPlanCode: string | null;
        ticketBalance: number;
        canManageBilling: boolean;
    }

    let { plans, currentPlanCode, ticketBalance, canManageBilling }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const currentPlan = $derived(plans.find((plan) => plan.code === currentPlanCode) ?? null);

    let processingPlanCode = $state<string | null>(null);
    let portalProcessing = $state(false);

    function startCheckout(plan: Plan): void {
        router.post(
            "/billing/checkout",
            { plan_code: plan.code },
            {
                onStart: () => {
                    processingPlanCode = plan.code;
                },
                onFinish: () => {
                    processingPlanCode = null;
                },
            },
        );
    }

    function openPortal(): void {
        router.post(
            "/billing/portal",
            {},
            {
                onStart: () => {
                    portalProcessing = true;
                },
                onFinish: () => {
                    portalProcessing = false;
                },
            },
        );
    }

    function formatPrice(price: PlanPrice | null): string {
        if (price === null) {
            return "無料";
        }
        if (price.currency === "jpy") {
            return `¥${price.unitAmount.toLocaleString("ja-JP")} / 月`;
        }
        return `${price.unitAmount.toLocaleString()} ${price.currency.toUpperCase()} / 月`;
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="プランとお支払い"
            description="この組織のプランとチケット残高を管理します。"
            icon={CreditCard}
            testId="billing-heading"
        />
        <PageContent>
            <div class="flex flex-col gap-10">
            <Card padding="lg" testId="billing-summary">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-h3">現在のプラン</h2>
                        <p class="mt-2 text-body" data-testid="current-plan-name">
                            {currentPlan?.name ?? "未契約 (Free 相当)"}
                        </p>
                    </div>
                    <div>
                        <h2 class="text-h3">チケット残高</h2>
                        <p class="mt-2 text-body" data-testid="ticket-balance">
                            {ticketBalance.toLocaleString("ja-JP")} 枚
                        </p>
                        <!-- 遷移先が role-aware (非管理者には購入依頼の案内) のため権限に依らず表示 -->
                        <p class="mt-1">
                            <TextLink href="/purchase-tickets" testId="purchase-tickets-link">
                                チケットを購入
                            </TextLink>
                        </p>
                    </div>
                </div>
                {#if canManageBilling}
                    <div class="mt-6">
                        <Button
                            variant="ghost"
                            loading={portalProcessing}
                            onclick={openPortal}
                            testId="billing-portal-button"
                        >
                            お支払い方法を管理 (Stripe)
                        </Button>
                    </div>
                {:else}
                    <p class="mt-6 text-caption text-text-secondary">
                        プランの変更には組織の管理者権限が必要です。
                    </p>
                {/if}
            </Card>

            <section>
                <h2 class="text-h3">プラン一覧</h2>
                <ul class="mt-4 flex flex-col gap-4" data-testid="plan-list">
                    {#each plans as plan (plan.code)}
                        <li>
                            <Card padding="lg">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h3 class="text-h3">{plan.name}</h3>
                                            {#if plan.code === currentPlanCode}
                                                <Badge tone="primary">現在のプラン</Badge>
                                            {/if}
                                        </div>
                                        <p class="mt-1 text-caption text-text-secondary">
                                            {formatPrice(plan.price)}
                                        </p>
                                    </div>
                                    {#if canManageBilling && plan.price !== null && plan.code !== currentPlanCode}
                                        <Button
                                            loading={processingPlanCode === plan.code}
                                            onclick={() => startCheckout(plan)}
                                            testId={`checkout-${plan.code}`}
                                        >
                                            このプランにする
                                        </Button>
                                    {/if}
                                </div>
                            </Card>
                        </li>
                    {/each}
                </ul>
            </section>
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
