<script lang="ts">
    import { page as inertiaPage } from "@inertiajs/svelte";
    import { ChevronDown, ExternalLink, Info, LayoutDashboard, Mail } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";
    import type { PricingFeature } from "@/components/molecules/PricingPlanCard.types";
    import GuestLayout from "@/components/templates/GuestLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { PricingPageProps, PricingPlanShape } from "@/types/marketing";

    /**
     * 公開料金表 (/pricing)。プラン基本料 (free / standard) + 共通チケット制の説明 +
     * チケット傾斜料金表 + FAQ。title / description はサーバ SEO が正本のため
     * svelte:head は付けない。
     */
    interface Props {
        page: PricingPageProps;
    }

    let { page }: Props = $props();

    const shared = $derived(inertiaPage.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
    const formatLimit = (v: number | null): string => (v === null ? "無制限" : String(v));

    const buildFeatures = (plan: PricingPlanShape): PricingFeature[] => [
        { text: `月 ${plan.monthlyTicketGrant} 枚のチケット付与` },
        { text: `プロジェクト ${formatLimit(plan.maxProjects)}` },
        { text: `メンバー ${formatLimit(plan.maxMembers)} 名` },
        {
            text:
                plan.maxStorageGb === null
                    ? "ストレージ 無制限"
                    : `ストレージ ${plan.maxStorageGb} GB`,
        },
    ];

    // チケット料金表: 傾斜段を「X〜Y 枚」の帯へ変換する (最終段は「X 枚以上」)。
    const tierRows = $derived(
        page.ticketTiers.map((tier, i) => {
            const next = page.ticketTiers[i + 1];
            return {
                label: next ? `${tier.minCount}〜${next.minCount - 1} 枚` : `${tier.minCount} 枚以上`,
                unitAmount: tier.unitAmount,
            };
        }),
    );

    const faqs = $derived([
        {
            q: "無料で試せますか？",
            a: `はい。Free プランは基本料金なしでご利用いただけます。さらに新規登録でチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
        },
        {
            q: "チケットは何に使いますか？",
            a: `AI によるシナリオ解析に ${page.analysisTicketCost} 枚、完成動画のレンダリングに ${page.renderTicketCost} 枚を使います。プレビュー生成は無料です。`,
        },
        {
            q: "追加チケットはどのように購入できますか？",
            a: `1 枚あたり ${page.spotUnitAmountJpy} 円から、組織のオーナー・管理者が必要な分だけ購入できます。まとめ買いの割引単価はチケット料金表をご覧ください。`,
        },
        {
            q: "解約・プラン変更はできますか？",
            a: "いつでも可能です。プラン変更・解約は請求ダッシュボード (Stripe) から行えます。変更は次回更新のタイミングで反映されます。",
        },
    ]);

    let openFaqIndex = $state<number | null>(null);
    const toggleFaq = (i: number): void => {
        openFaqIndex = openFaqIndex === i ? null : i;
    };
</script>

<GuestLayout {appName}>
    {#snippet nav()}
        {#if page.isAuthenticated}
            <a href="/dashboard" class="text-text-secondary hover:text-primary">ダッシュボード</a>
        {:else}
            <a href="/login" class="text-text-secondary hover:text-primary">ログイン</a>
            <a href="/register" class="text-primary hover:text-primary-hover">無料で始める</a>
        {/if}
    {/snippet}

    <section class="py-6">
        <div class="text-center">
            <h1 class="text-h1 text-text">料金プラン</h1>
            <p class="mt-3 text-body text-text-secondary">
                無料で始めて、必要になったらチームで広げる。シンプルな 2 プランです。
            </p>
        </div>

        <!-- 料金構造の注記: 基本料金 + 共通チケット制 (全プラン共通のためページ冒頭で説明) -->
        <div
            class="mx-auto mt-6 flex max-w-3xl items-start gap-3 rounded-lg border border-border bg-surface px-4 py-3 text-left"
            data-testid="pricing-structure-note"
        >
            <Info class="mt-0.5 size-4 shrink-0 text-text-secondary" aria-hidden="true" />
            <p class="text-body text-text-secondary">
                表示は各プランの基本料金 (月額) です。AI 解析・動画レンダにはどのプランでも共通のチケットを使います
                (AI 解析 {page.analysisTicketCost} 枚・動画レンダ {page.renderTicketCost} 枚。<a
                    href="#ticket-pricing"
                    aria-label="チケット料金セクションへ移動"
                    class="text-primary underline">チケット料金</a
                >をご覧ください)。
            </p>
        </div>

        <!-- プランカード -->
        <div class="mx-auto mt-10 grid max-w-3xl gap-4 sm:grid-cols-2" data-testid="pricing-plan-grid">
            {#each page.plans as plan (plan.code)}
                <PricingPlanCard
                    name={plan.name}
                    priceAmount={plan.baseAmountJpy}
                    priceCaption="基本料金"
                    features={buildFeatures(plan)}
                    testId={`pricing-plan-${plan.code}`}
                >
                    {#snippet footerCta()}
                        {#if page.isAuthenticated}
                            <Button href="/billing" fullWidth inertia>プランを変更</Button>
                        {:else}
                            <Button href="/register" fullWidth>このプランで始める</Button>
                        {/if}
                    {/snippet}
                </PricingPlanCard>
            {/each}
        </div>

        <!-- 大規模利用バナー -->
        <div
            class="mx-auto mt-4 flex max-w-3xl flex-col gap-4 rounded-lg border border-border bg-surface p-6 sm:flex-row sm:items-center sm:justify-between"
            data-testid="pricing-enterprise-banner"
        >
            <div>
                <h2 class="text-h3 text-text">より大きな組織・拠点展開のご相談</h2>
                <p class="mt-1 text-body text-text-secondary">
                    メンバー数・ストレージ・運用設計のご要望に応じて個別にご案内します。
                </p>
            </div>
            <Button
                href={page.contactUrl}
                variant="ghost"
                class="shrink-0"
                target={page.contactIsExternal ? "_blank" : undefined}
            >
                {#if page.contactIsExternal}
                    <ExternalLink class="size-4" aria-hidden="true" />
                {:else}
                    <Mail class="size-4" aria-hidden="true" />
                {/if}
                お問い合わせ
            </Button>
        </div>

        <!-- チケット料金表 -->
        <div id="ticket-pricing" class="mx-auto mt-12 max-w-2xl">
            <h2 class="text-center text-h2 text-text">チケット料金</h2>
            <p class="mt-3 text-center text-body text-text-secondary">
                チケットは AI 解析 ({page.analysisTicketCost} 枚)・動画レンダ ({page.renderTicketCost} 枚)
                に使います。組織のオーナー・管理者が必要な分だけ購入でき、まとめ買いで 1 枚あたりの料金が下がります。
            </p>
            <p
                class="mt-4 rounded-lg border border-primary/30 bg-primary-soft px-4 py-3 text-center text-body text-text"
                data-testid="signup-grant-note"
            >
                新規登録でチケット {page.signupGrantTickets} 枚が無料でついてきます (付与から {page.signupGrantExpiryDays}
                日間有効)
            </p>
            {#if tierRows.length > 0}
                <ul
                    class="mt-4 rounded-lg border border-border bg-surface px-6 py-2 text-body"
                    data-testid="ticket-tier-table"
                >
                    {#each tierRows as row (row.label)}
                        <li class="flex justify-between border-b border-border py-2 last:border-b-0">
                            <span class="text-text-secondary">{row.label}</span>
                            <span class="text-text">¥{formatYen(row.unitAmount)} ／ 枚</span>
                        </li>
                    {/each}
                </ul>
            {/if}
        </div>

        {#if page.isAuthenticated}
            <div class="mt-8 text-center">
                <Button href="/dashboard" variant="ghost" inertia>
                    <LayoutDashboard class="size-4" aria-hidden="true" /> ダッシュボードへ戻る
                </Button>
            </div>
        {/if}
    </section>

    <!-- FAQ -->
    <section class="py-10">
        <div class="mx-auto max-w-3xl">
            <h2 class="text-center text-h2 text-text">よくあるご質問</h2>
            <div class="mt-6 space-y-2" data-testid="pricing-faq">
                {#each faqs as faq, i (faq.q)}
                    <div class="rounded-lg border border-border bg-surface">
                        <button
                            type="button"
                            onclick={() => toggleFaq(i)}
                            class="flex w-full items-center justify-between px-4 py-3 text-left"
                            aria-expanded={openFaqIndex === i}
                        >
                            <span class="text-h3 text-text">{faq.q}</span>
                            <ChevronDown
                                class="size-4 text-text-secondary transition-transform {openFaqIndex === i
                                    ? 'rotate-180'
                                    : ''}"
                                aria-hidden="true"
                            />
                        </button>
                        {#if openFaqIndex === i}
                            <div class="px-4 pb-4 text-body text-text">{faq.a}</div>
                        {/if}
                    </div>
                {/each}
            </div>
        </div>
    </section>

    {#snippet footerLinks()}
        <a href="/" class="hover:text-primary">トップ</a>
        <a href="/terms" class="hover:text-primary">利用規約</a>
        <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
        <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
    {/snippet}
</GuestLayout>
