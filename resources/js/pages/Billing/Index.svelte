<script lang="ts">
    import { onMount } from "svelte";
    import { page as inertiaPage, router } from "@inertiajs/svelte";
    import { CreditCard } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import type { AlertType } from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AutoRechargeCard from "@/components/features/billing/AutoRechargeCard.svelte";
    import BillingContactForm from "@/components/features/billing/BillingContactForm.svelte";
    import { formatBytes } from "@/lib/format-bytes";
    import { formatDate } from "@/lib/date-format";
    import type { SharedProps } from "@/lib/shared-props";
    import type { BillingDashboardProps, BillingFeedbackKind } from "@/types/billing";

    /**
     * 課金ダッシュボード (/billing)。現在のプラン / per-bucket チケット残高 /
     * quota の利用状況 (使用量 / 上限 + 超過警告) / オートリチャージ設定 と、
     * プラン比較・チケット購入への導線を持つ。
     *
     * プラン一覧は /billing/plans (Billing/Plans.svelte) へ移設済み。
     * 支払い方法・解約は Customer Portal (POST → Inertia::location で Stripe へ) 経由。
     */
    interface Props {
        page: BillingDashboardProps;
    }

    let { page }: Props = $props();

    const shared = $derived(inertiaPage.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // Personal (free) はサブスクなし。Stripe portal / 次回請求日などサブスク前提の UI を出さない。
    const isFreePlan = $derived(page.billingState === "active_free_plan");

    let portalProcessing = $state(false);

    /**
     * P9: 決済戻り着地の one-shot フィードバック。**raw query は一切見ない** —
     * kind → variant の写像だけを持ち、文言はサーバ確定値をそのまま描画する。
     *
     * one-shot はサーバが担保する: 着地 query は canonical URL へ 303 で畳まれ、
     * feedback は次の 1 リクエストだけ生きる session flash で届く。
     * したがってリロード / 戻る / ブックマークでは feedback=null になりバナーは復活しない
     * (クライアント側の URL scrub は行わない)。
     */
    const FEEDBACK_VARIANTS = {
        purchase_received: "success",
        purchase_processing: "info",
        purchase_already_received: "info",
        checkout_retry_required: "warning",
        portal_returned: "info",
    } as const satisfies Record<BillingFeedbackKind, AlertType>;

    const feedbackVariant = $derived(
        page.feedback === null ? null : FEEDBACK_VARIANTS[page.feedback.kind],
    );

    const formatYen = (amount: number | null): string =>
        amount === null ? "—" : new Intl.NumberFormat("ja-JP").format(amount);

    const formatLimit = (value: number | null): string => (value === null ? "無制限" : String(value));

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

    // ?highlight=auto-recharge の着地 anchor (購入画面等からの誘導。scroll のみ・副作用なし)。
    onMount(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get("highlight") === "auto-recharge") {
            const card = document.querySelector('[data-testid="auto-recharge-card"]');
            card?.scrollIntoView({ behavior: "smooth" });
            card?.setAttribute("data-highlighted", "true");
        }
    });
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
                {#if page.feedback !== null && feedbackVariant !== null}
                    <Alert type={feedbackVariant} testId="billing-feedback">
                        <span data-testid={`billing-feedback-${page.feedback.kind}`}>
                            {page.feedback.message}
                        </span>
                    </Alert>
                {/if}

                {#if page.continueUrl !== null}
                    <Card padding="lg" testId="billing-continue">
                        <p class="text-body">お手続きが完了しました。中断していた画面に戻れます。</p>
                        <div class="mt-4">
                            <Button href={page.continueUrl} inertia testId="billing-continue-link">
                                元の画面に戻る
                            </Button>
                        </div>
                    </Card>
                {/if}

                <Card padding="lg" testId="current-plan-card">
                    <h2 class="text-h3">現在のプラン</h2>
                    {#if page.plan !== null}
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div>
                                <p class="text-caption text-text-secondary">プラン</p>
                                <p class="text-h2 text-text" data-testid="current-plan-code">
                                    {page.plan.name}
                                </p>
                                <p class="text-body text-text-secondary">
                                    {#if isFreePlan}
                                        月額 無料（チケット代のみ）
                                    {:else}
                                        月額 ¥{formatYen(page.plan.baseAmountJpy)}
                                    {/if}
                                </p>
                            </div>
                            {#if !isFreePlan}
                                <div>
                                    <p class="text-caption text-text-secondary">次回請求日</p>
                                    <p class="text-h3 text-text" data-testid="current-period-end">
                                        {formatDate(page.currentPeriodEnd, "—")}
                                    </p>
                                </div>
                            {/if}
                        </div>
                    {:else}
                        <p class="mt-4 text-body text-text-secondary" data-testid="no-plan-note">
                            まだプランに契約していません。「プラン比較」から新規契約できます。
                        </p>
                    {/if}
                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <Button href="/billing/plans" inertia variant="ghost" testId="billing-plans-link">
                            プラン比較
                        </Button>
                        {#if page.canManageBilling && !isFreePlan}
                            <Button
                                variant="ghost"
                                loading={portalProcessing}
                                onclick={openPortal}
                                testId="billing-portal-button"
                            >
                                お支払い方法を管理 (Stripe)
                            </Button>
                        {/if}
                    </div>
                    {#if !page.canManageBilling}
                        <p class="mt-4 text-caption text-text-secondary">
                            プランの変更には組織の管理者権限が必要です。
                        </p>
                    {/if}
                </Card>

                <Card padding="lg" testId="billing-balance">
                    <h2 class="text-h3">チケット残高</h2>
                    <div class="mt-4">
                        <p class="text-caption text-text-secondary">今すぐ使える残高</p>
                        <p class="text-h2 text-text" data-testid="ticket-balance">
                            {page.balance.totalAvailable.toLocaleString("ja-JP")}
                            <span class="text-caption text-text-secondary">枚</span>
                        </p>
                    </div>
                    <dl class="mt-4 grid gap-4 border-t border-border pt-4 md:grid-cols-2">
                        <div>
                            <dt class="text-caption text-text-secondary">プラン付与残</dt>
                            <dd class="mt-1 text-h3 text-text" data-testid="balance-monthly">
                                {page.balance.monthlyRemaining.toLocaleString("ja-JP")}
                                <span class="text-caption text-text-secondary">枚</span>
                            </dd>
                            <p class="text-caption text-text-secondary">
                                プラン付与・初回特典分の残り（有効期限あり）
                            </p>
                        </div>
                        <div>
                            <dt class="text-caption text-text-secondary">購入済み残</dt>
                            <dd class="mt-1 text-h3 text-text" data-testid="balance-purchased">
                                {page.balance.purchasedRemaining.toLocaleString("ja-JP")}
                                <span class="text-caption text-text-secondary">枚</span>
                            </dd>
                            <p class="text-caption text-text-secondary">追加購入した分の残り</p>
                        </div>
                    </dl>
                    {#if page.balance.nextExpireAt !== null}
                        <p class="mt-3 text-caption text-text-secondary" data-testid="balance-next-expire">
                            次の失効: {formatDate(page.balance.nextExpireAt, "—")}
                        </p>
                    {/if}
                    <!-- 遷移先が role-aware (非管理者には購入依頼の案内) のため権限に依らず表示 -->
                    <p class="mt-4">
                        <TextLink href="/purchase-tickets" testId="purchase-tickets-link">
                            チケットを購入
                        </TextLink>
                    </p>
                </Card>

                <!--
                    P8a: オートリチャージ (裏チャージ) 設定カード。
                    差し込み位置と ?highlight=auto-recharge の着地 anchor は P8b 所管
                    (カード実体は P8a 所管のため、ここでは配置のみを決める)。
                -->
                <AutoRechargeCard
                    autoRecharge={page.autoRecharge}
                    updateUrl="/billing/auto-recharge"
                    setupUrl="/billing/auto-recharge/setup"
                    setupAttemptToken={page.autoRechargeSetupToken}
                />

                <!-- P9: 請求先情報 (請求通知の宛先。未設定時は owner email へ fallback)。 -->
                <BillingContactForm
                    billingContact={page.billingContact}
                    updateUrl="/billing/contact"
                    canManage={page.canManageBilling}
                />

                <Card padding="lg" testId="billing-quotas">
                    <h2 class="text-h3">ご利用状況と上限</h2>

                    {#if page.quotas.exceededLabels.length > 0}
                        <Alert type="warning" class="mt-4" testId="quota-exceeded-alert">
                            現在のプランの上限を超えている項目があります（{page.quotas.exceededLabels.join(
                                "・",
                            )}）。 既存のデータは削除されませんが、<strong
                                >超えている項目に関わる操作</strong
                            >
                            （プロジェクト数ならプロジェクトの新規作成、保存容量なら動画のアップロード）が、上限内に収まるまでできません。
                        </Alert>
                    {/if}

                    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-caption text-text-secondary">プロジェクト</dt>
                            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-projects">
                                {page.quotas.projectsUsed} / {formatLimit(page.quotas.maxProjects)}
                            </dd>
                        </div>
                        <div>
                            <!-- メンバー数は quota として強制されていないため使用量を併記しない
                                 (「超えると止まる」と読める表示をしない。QuotaKey の docblock 参照) -->
                            <dt class="text-caption text-text-secondary">メンバー (上限)</dt>
                            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-members">
                                {formatLimit(page.quotas.maxMembers)}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-caption text-text-secondary">ストレージ</dt>
                            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-storage">
                                {formatBytes(page.quotas.storageUsedBytes)} / {page.quotas
                                    .maxStorageGb === null
                                    ? "無制限"
                                    : `${page.quotas.maxStorageGb} GB`}
                            </dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
