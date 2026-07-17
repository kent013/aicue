<script lang="ts">
    import { page as inertiaPage, router } from "@inertiajs/svelte";
    import { CreditCard } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";
    import type { PricingFeature } from "@/components/molecules/PricingPlanCard.types";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type {
        OnboardingCheckoutShape,
        OnboardingOrganizationShape,
        PlanShape,
    } from "@/types/onboarding";

    /**
     * 課金オンボーディング: プラン選択 (current org スコープ)。
     *
     * - plan grid は PricingPlanCard を再利用する (プラン名・基本料金はサーバ確定値)。
     * - Personal (無料) は Stripe checkout を通らず activate-personal へ POST する
     *   (自己申告チェック = declaration が必須。サーバ FormRequest が権威)。
     * - 有償プランは既存の billing.checkout へ POST する (Stripe Checkout へ full page redirect)。
     * - ボタンは disabled にしない (DESIGN.md / AGENTS.md 禁止事項 #8)。eligibility 不成立・
     *   declaration 未チェックでも押下でき、サーバが返した文言をそのまま表示する
     *   (eligibility は render 後に変化しうるため サーバ判定が唯一の権威)。
     * - 文言はすべてサーバ確定 (reasonLabel / errors) で frontend では組み立てない。
     */
    interface Props {
        organization: OnboardingOrganizationShape;
        pageData: OnboardingCheckoutShape;
    }

    let { organization, pageData }: Props = $props();

    const shared = $derived(inertiaPage.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);

    // defaultPlanCode は plans への包含を保証しない (コード値) ため、plans にある場合のみ
    // preselect し、無ければ先頭 plan を強調する (決定的挙動)。
    const computeInitialPlan = (data: OnboardingCheckoutShape): string | null =>
        data.plans.some((p) => p.code === data.defaultPlanCode)
            ? data.defaultPlanCode
            : (data.plans[0]?.code ?? null);

    let chosenPlanCode = $state<string | null>(null);
    // 強調するカード = ユーザーが選んだもの。未選択なら props から導出した既定。
    // $state に初期値を焼くと props 変更 (Inertia partial reload) に追随せず、
    // $derived を再代入すると runes の再評価と競合するため、override を $state・表示値を $derived で持つ。
    const selectedPlanCode = $derived(chosenPlanCode ?? computeInitialPlan(pageData));
    let submitting = $state(false);
    let declarationChecked = $state(false);

    // サーバ由来エラーを「発生したプラン」にキー付けし、別プランへ切替えると旧エラーが消える。
    let lastSubmittedPlanCode = $state<string | null>(null);

    const isPersonal = (plan: PlanShape): boolean => plan.code === "personal";

    // 表示寿命を「現在選択中プラン」に結合する: in-flight 中は非表示 (再 submit 時の旧エラー
    // フラッシュ防止) + submit したプランを選択中のときだけ表示。
    const planCodeError = $derived(
        !submitting && chosenPlanCode !== null && chosenPlanCode === lastSubmittedPlanCode
            ? (serverErrors.plan_code ?? null)
            : null,
    );
    const declarationError = $derived(!submitting ? (serverErrors.declaration ?? null) : null);

    // Personal が選べない理由 (サーバー確定文言)。押下は妨げず、理由を常時提示する。
    const personalReasonLabel = $derived(
        pageData.personalEligibility !== null && !pageData.personalEligibility.eligible
            ? pageData.personalEligibility.reasonLabel
            : null,
    );

    const buildFeatures = (plan: PlanShape): PricingFeature[] => {
        if (!isPersonal(plan)) {
            return [];
        }

        // 月次のチケット付与は廃止済 (常に 0 枚) のため表記しない (料金ページと同一方針)。
        return [
            { text: "基本料金なし（トレーニングに使うチケット代のみ）" },
            {
                text: "個人利用専用です。法人・チームでのご利用は Starter プラン以上をお選びください",
            },
        ];
    };

    const choosePlan = (plan: PlanShape): void => {
        chosenPlanCode = plan.code;
    };

    // Personal (無料) の有効化。declaration 未チェックでも送信し、サーバの文言を表示する
    // (押下時にエラー表示 = 禁止事項 #8)。
    const submitPersonalFree = (): void => {
        if (submitting) return; // 多重送信ガード (disabled にはしない)
        lastSubmittedPlanCode = "personal";
        router.post(
            "/onboarding/activate-personal",
            { declaration: declarationChecked ? "1" : "0" },
            {
                onStart: () => {
                    submitting = true;
                },
                onFinish: () => {
                    submitting = false;
                },
            },
        );
    };

    // 選択中の code が「有償プラン」か (= billing/checkout へ送ってよいか)。
    // props の plans を単一真実源にし、基本料金を持つものだけを有償とみなす
    // (personal は currentBaseAmount が null = Stripe checkout を通らない)。
    const isPaidPlanCode = (code: string | null): boolean =>
        code !== null && pageData.plans.some((p) => p.code === code && p.currentBaseAmount !== null);

    // 有償プランの契約開始。既存の課金 checkout (Stripe Checkout へ full page redirect) に載せる。
    const submitPaidPlan = (): void => {
        if (submitting || chosenPlanCode === null) return;
        // 無償プラン (personal) を有償 checkout へ送らない。UI 分岐でも到達しないが、
        // 述語が崩れたときに Stripe checkout へ無償プランが混入する余地を構造的に消す
        // (サーバ側も assertStripeBillablePlan で fail-closed だが、二重防御)。
        if (!isPaidPlanCode(chosenPlanCode)) return;
        lastSubmittedPlanCode = chosenPlanCode;
        router.post(
            "/billing/checkout",
            { plan_code: chosenPlanCode },
            {
                onStart: () => {
                    submitting = true;
                },
                onFinish: () => {
                    submitting = false;
                },
            },
        );
    };

    const showRecommendedBadge = (planCode: string): boolean =>
        planCode === pageData.recommendedPlanCode;
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title={`ようこそ、${organization.name}`}
            description="利用を開始するにはプランを選択してください。"
            icon={CreditCard}
            testId="onboarding-checkout-heading"
        />
        <PageContent>
            <div class="flex flex-col gap-6" data-testid="onboarding-checkout">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="plan-grid">
                    {#each pageData.plans as plan (plan.code)}
                        <PricingPlanCard
                            name={plan.name}
                            priceAmount={isPersonal(plan) ? 0 : plan.currentBaseAmount}
                            features={buildFeatures(plan)}
                            isHighlighted={selectedPlanCode === plan.code}
                            testId={`plan-card-${plan.code}`}
                        >
                            {#snippet footerCta()}
                                <div class="flex flex-col gap-2">
                                    {#if showRecommendedBadge(plan.code)}
                                        <span
                                            class="self-start rounded-sm bg-primary/10 px-2 py-0.5 text-caption text-primary"
                                            data-testid={`recommended-badge-${plan.code}`}
                                        >
                                            おすすめ
                                        </span>
                                    {/if}
                                    <Button
                                        onclick={() => choosePlan(plan)}
                                        testId={`select-plan-${plan.code}`}
                                    >
                                        {chosenPlanCode === plan.code ? "選択中" : "選択"}
                                    </Button>
                                    {#if isPersonal(plan) && personalReasonLabel !== null}
                                        <!-- 選択自体は妨げない (サーバが最終判定)。理由を常時提示する。 -->
                                        <p
                                            class="text-caption text-text-secondary"
                                            data-testid="personal-eligibility-reason"
                                        >
                                            {personalReasonLabel}
                                        </p>
                                    {/if}
                                </div>
                            {/snippet}
                        </PricingPlanCard>
                    {/each}
                </div>

                {#if chosenPlanCode === "personal"}
                    <!-- Personal (無料) は Stripe checkout を通らない。自己申告チェック + 無料開始 CTA。 -->
                    <div class="flex flex-col gap-4" data-testid="personal-free-step">
                        {#if planCodeError !== null}
                            <Alert type="danger" testId="checkout-plan-error">
                                {planCodeError}
                            </Alert>
                        {/if}

                        <div>
                            <p class="text-body font-medium text-text">
                                パーソナルプラン（無料）で始める
                            </p>
                            <p class="mt-1 text-caption text-text-secondary">
                                基本料金はかかりません。トレーニングの実行に使うチケットのみ購入制です（新規登録特典として
                                チケット {pageData.signupGrantTickets} 枚を無償でお付けします）。カード登録なしでも始められます。
                            </p>
                        </div>

                        <Checkbox
                            id="personal-declaration"
                            bind:checked={declarationChecked}
                            label="個人での利用であり、法人・チームでの利用ではないことを確認しました"
                            error={declarationError}
                            testId="personal-declaration"
                        />

                        <div>
                            <Button
                                onclick={submitPersonalFree}
                                loading={submitting}
                                testId="personal-free-submit"
                            >
                                無料プランを開始する
                            </Button>
                            <p class="mt-2 text-caption text-text-secondary">
                                決済画面には進みません。すぐに利用を開始できます。
                            </p>
                        </div>
                    </div>
                {:else if chosenPlanCode !== null}
                    <div class="flex flex-col gap-4" data-testid="paid-plan-step">
                        {#if planCodeError !== null}
                            <Alert type="danger" testId="checkout-plan-error">
                                {planCodeError}
                            </Alert>
                        {/if}

                        <div>
                            <Button
                                onclick={submitPaidPlan}
                                loading={submitting}
                                testId="paid-plan-submit"
                            >
                                この内容で契約を進める
                            </Button>
                            <p class="mt-2 text-caption text-text-secondary">
                                次の画面で決済に進みます。
                            </p>
                        </div>
                    </div>
                {/if}

                <p class="text-caption text-text-secondary">
                    <!-- contactUrl は内部 path / 外部 URL / mailto のいずれにもなりうる (ContactUrl が
                         解決する) ため、素の <a> で全パターンを同じ扱いにする (Welcome と同規約)。 -->
                    Enterprise プランをご検討の場合は <a
                        href={pageData.contactUrl}
                        class="text-primary underline"
                        data-testid="onboarding-contact-link">お問い合わせ</a
                    > ください。
                </p>
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
