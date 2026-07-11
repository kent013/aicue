<!--
  PricingPlanCard — 料金プランカードの共通分子 (aigenba 移植)。

  DTO 非依存 (primitive props)。feature 文言・CTA は呼び出し側が props / snippet で供給する。
  AI-CUE の props 契約: priceAmount null は「plan_prices (base) を持たない = 無料プラン」
  (PlanSeeder の台帳意味論。Billing/Index.svelte の formatPrice(null)=>「無料」と同じ表示契約)。
  0 も防御的に同一表示。aigenba の contactLabel (null=お問い合わせ) 分岐は移植しない
  (該当プランが存在しない機能を作らない。大規模利用の問い合わせはカード外バナーの責務)。
-->
<script lang="ts">
    import type { Snippet } from "svelte";
    import { Check } from "@lucide/svelte";
    import type { PricingFeature } from "./PricingPlanCard.types";

    interface Props {
        name: string;
        /** null = 基本料金なし = 無料表示 (0 も防御的に同一表示) */
        priceAmount: number | null;
        /** 価格サフィックス (既定 '／月') */
        priceSuffix?: string;
        /** 価格の直上に小さく載せる説明 (例: '基本料金')。表示価格が総額と誤解されるのを防ぐ。 */
        priceCaption?: string;
        /** 現在のプランなど強調枠 (border-primary) */
        isHighlighted?: boolean;
        features: PricingFeature[];
        testId?: string;
        /** card footer 下部 CTA 専用 */
        footerCta: Snippet;
    }

    let {
        name,
        priceAmount,
        priceSuffix = "／月",
        priceCaption,
        isHighlighted = false,
        features,
        testId,
        footerCta,
    }: Props = $props();

    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
    const borderClass = $derived(isHighlighted ? "border-primary" : "border-border");
    const isFree = $derived(priceAmount === null || priceAmount === 0);
</script>

<div class="flex flex-col rounded-lg border bg-surface p-5 {borderClass}" data-testid={testId}>
    <h3 class="text-h3 text-text">{name}</h3>
    {#if priceCaption !== undefined && !isFree}
        <!-- 表示価格が総額と誤解されるのを防ぐ (例: 基本料金)。 -->
        <p class="mt-3 text-caption text-text-secondary" data-testid="price-caption">
            {priceCaption}
        </p>
    {/if}
    <p class="{priceCaption !== undefined && !isFree ? 'mt-0.5' : 'mt-3'} text-h2 text-text">
        {#if isFree}
            <!-- 無料プラン: ¥0 表記でなく「無料」を価格として掲示する -->
            無料
        {:else if priceAmount !== null}
            ¥{formatYen(priceAmount)}
            <span class="text-caption text-text-secondary">{priceSuffix}</span>
        {/if}
    </p>

    <ul class="mt-4 flex-1 space-y-2 text-body text-text">
        {#each features as feature, i (`${feature.variant ?? "default"}:${i}:${feature.text}`)}
            {#if feature.variant === "warning"}
                <li
                    class="flex items-start gap-2 rounded-sm border border-warning bg-warning/10 p-2 text-text"
                >
                    <Check class="mt-0.5 size-4 shrink-0 text-warning" />
                    {feature.text}
                </li>
            {:else}
                <li class="flex items-start gap-2">
                    <Check class="mt-0.5 size-4 shrink-0 text-success" />
                    {feature.text}
                </li>
            {/if}
        {/each}
    </ul>

    <div class="mt-5">
        {@render footerCta()}
    </div>
</div>
