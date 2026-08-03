<!--
  PlanCard — Billing/Plans の page-local helper (aigenba `Billing/_helpers/PlanCard.svelte` 移植)。

  page-local 配置は維持する: Billing 固有 props (isCurrent / canSwitch / onSwitch 等) を束ね、
  共通の plan カード構造 (枠・価格・feature バレット) は molecules/PricingPlanCard へ委譲する
  アダプタ。本 file は Billing/Plans 以外から import しない (= page-local 規約)。

  D4 適合 (AGENTS.md 禁止事項 #8): aigenba は canSwitch=false を disabled ボタン +「変更不可」で
  表現するが、AI-CUE では **CTA を enabled のまま**描画し、押下時に理由を Alert で表示する。
  理由文言はカード内 caption としても常時可視にし、情報を失わない (意図的な非 parity)。
-->
<script lang="ts">
    import Alert from "@/components/atoms/Alert.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";
    import type { PricingFeature } from "@/components/molecules/PricingPlanCard.types";
    import type { PricingPlanShape } from "@/types/marketing";

    interface Props {
        plan: PricingPlanShape;
        isCurrent: boolean;
        canSwitch: boolean;
        /** canSwitch=false の理由 (常時 caption 表示 + 押下時 Alert)。canSwitch=true では空文字 */
        switchBlockedReason: string;
        formatLimit: (value: number | null) => string;
        onSwitch: (planCode: string) => void;
    }

    let { plan, isCurrent, canSwitch, switchBlockedReason, formatLimit, onSwitch }: Props = $props();

    // 押下時にだけ立てる transient state (押せる状態は維持し、理由は押下後に明示する)。
    let blockedShown = $state(false);

    // 月次のチケット付与は廃止済 (常に 0 枚) のため表記しない (料金ページと同一方針。D28)。
    // 語彙は公開料金表 (Guest/Pricing の buildFeatures) と同一出典。
    const features = $derived<PricingFeature[]>([
        { text: `プロジェクト ${formatLimit(plan.maxProjects)}` },
        { text: `メンバー ${formatLimit(plan.maxMembers)} 名` },
        {
            text:
                plan.maxStorageGb === null
                    ? "ストレージ 無制限"
                    : `ストレージ ${plan.maxStorageGb} GB`,
        },
    ]);

    // baseAmountJpy null = plan_prices (base) を持たない無料プラン → PricingPlanCard が「無料」表示。
    const priceAmount = $derived(plan.baseAmountJpy);

    function handleClick(): void {
        if (!canSwitch) {
            blockedShown = true;
            return;
        }
        blockedShown = false;
        onSwitch(plan.code);
    }
</script>

<PricingPlanCard
    name={plan.name}
    {priceAmount}
    priceCaption="基本料金"
    isHighlighted={isCurrent}
    {features}
    testId={`plan-card-${plan.code}`}
>
    {#snippet headerBadges()}
        {#if isCurrent}
            <Badge tone="primary" testId={`plan-current-badge-${plan.code}`}>現在のプラン</Badge>
        {/if}
    {/snippet}
    {#snippet footerCta()}
        {#if blockedShown && switchBlockedReason !== ""}
            <div class="mb-3">
                <Alert type="warning" testId="plan-switch-blocked">{switchBlockedReason}</Alert>
            </div>
        {/if}
        <Button fullWidth onclick={handleClick} testId={`plan-change-${plan.code}`}>
            このプランへ変更
        </Button>
        {#if switchBlockedReason !== ""}
            <!-- 押下前から理由を可視化する (disabled で情報を失わないための常時 caption) -->
            <p
                class="mt-2 text-caption text-text-secondary"
                data-testid={`plan-switch-reason-${plan.code}`}
            >
                {switchBlockedReason}
            </p>
        {/if}
    {/snippet}
</PricingPlanCard>
