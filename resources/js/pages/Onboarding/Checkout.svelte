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

    // 料金表由来の intendedPlanCode → defaultPlanCode → 先頭 plan の順で preselect する。
    // どちらも plans への包含を保証しない (コード値) ため、plans にある場合のみ採用する
    // (決定的挙動)。
    const computeInitialPlan = (data: OnboardingCheckoutShape): string | null => {
        const intended = data.intendedPlanCode;
        if (intended !== null && data.plans.some((p) => p.code === intended)) {
            return intended;
        }
        return data.plans.some((p) => p.code === data.defaultPlanCode)
            ? data.defaultPlanCode
            : (data.plans[0]?.code ?? null);
    };

    let chosenPlanCode = $state<string | null>(null);
    // 強調するカード = ユーザーが選んだもの。未選択なら props から導出した既定。
    // $state に初期値を焼くと props 変更 (Inertia partial reload) に追随せず、
    // $derived を再代入すると runes の再評価と競合するため、override を $state・表示値を $derived で持つ。
    const selectedPlanCode = $derived(chosenPlanCode ?? computeInitialPlan(pageData));
    let submitting = $state(false);
    let declarationChecked = $state(false);

    // P8a (D29(i)): 資金選択。既定は「オートリチャージを設定する」(おすすめ)。
    // fundingChoices に含まれる値のみ選べる (サーバ確定の並び)。
    const AUTO_RECHARGE = "auto_recharge";
    const LATER = "later";
    let fundingChoice = $state<string>(AUTO_RECHARGE);
    const fundingChoiceError = $derived(
        !submitting ? (serverErrors.consent_version ?? serverErrors.funding_choice ?? null) : null,
    );
    const consentTerms = $derived(pageData.consentTerms);
    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);

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
            {
                declaration: declarationChecked ? "1" : "0",
                funding_choice: fundingChoice,
                // auto_recharge のときだけ同意 version を送る (金額は送らない = サーバ再計算)。
                ...(fundingChoice === AUTO_RECHARGE
                    ? { consent_version: consentTerms.consentVersion }
                    : {}),
            },
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
            {
                plan_code: chosenPlanCode,
                subscription_attempt_token: pageData.subscriptionAttemptToken,
                funding_choice: fundingChoice,
                // auto_recharge のときだけ同意 version を送る (金額は送らない = サーバ再計算)。
                // 同意アクションは実行ボタンのクリック。
                ...(fundingChoice === AUTO_RECHARGE
                    ? { consent_version: consentTerms.consentVersion }
                    : {}),
            },
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
                            {#snippet headerBadges()}
                                {#if selectedPlanCode === plan.code}
                                    <!-- 青枠 (isHighlighted) が視覚で伝えている状態を、支援技術にも
                                         同じだけ伝える (F-2-01)。role は偽らない: 排他選択なので
                                         aria-pressed は誤りで、radiogroup 化はキーボード操作モデルの
                                         作り替えになる。文言にプラン名を含めるのは、カードが semantic
                                         group ではなくテキスト単位の移動で対象が読み上げ順に依存する
                                         のを避けるため。文言は CTA と同じ基準 (chosenPlanCode) で
                                         切り替え、未押下を「選択済み」と誤認させない。
                                         「プラン」の語は付けない: plan.name の実値 (Personal /
                                         Starter / Standard) に将来「プラン」が含まれても
                                         「プラン プラン」と重複しないようにするため。 -->
                                    <span
                                        class="sr-only"
                                        data-testid={`plan-selected-note-${plan.code}`}
                                    >
                                        {#if chosenPlanCode === plan.code}
                                            {plan.name} を選択中です
                                        {:else}
                                            {plan.name} が初期候補として表示されています
                                        {/if}
                                    </span>
                                {/if}
                            {/snippet}
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

                        {@render fundingChoiceSection(false)}

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

                        {@render fundingChoiceSection(true)}

                        <div>
                            <Button
                                onclick={submitPaidPlan}
                                loading={submitting}
                                testId="paid-plan-submit"
                            >
                                {fundingChoice === AUTO_RECHARGE
                                    ? "自動購入に同意して契約を進める"
                                    : "この内容で契約を進める"}
                            </Button>
                            <p
                                class="mt-2 text-caption text-text-secondary"
                                data-testid="paid-plan-submit-note"
                            >
                                {fundingChoice === AUTO_RECHARGE
                                    ? "次の画面で決済に進みます。お支払いの完了後、オートリチャージが自動で有効になります。いつでも停止できます。"
                                    : "次の画面で決済に進みます。"}
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

{#snippet fundingChoiceSection(paidPlan: boolean)}
                    <!-- P8a (D29(i)): チケットの補充方法の 2 択。既定は自動購入 (おすすめ) だが、
                         「あとで決める」を選べば課金設定なしで始められる (opt-in を強制しない)。
                         P9 (T1004 / consent_version=v2): 有償契約枝では「カードの取得手段」が
                         カード登録ではなく **契約のお支払いカードの流用** に変わるため、開示文言も
                         枝ごとに分ける (版を上げた開示の実体がここにある)。 -->
                    <fieldset class="flex flex-col gap-2" data-testid="funding-choice">
                        <legend class="text-caption font-medium text-text">
                            チケットの補充方法
                        </legend>
                        {#each pageData.fundingChoices as choice (choice)}
                            <label class="flex items-start gap-2">
                                <input
                                    type="radio"
                                    name="funding_choice"
                                    value={choice}
                                    checked={fundingChoice === choice}
                                    onchange={() => {
                                        fundingChoice = choice;
                                    }}
                                    class="mt-1 h-4 w-4 accent-primary"
                                    data-testid={`funding-choice-${choice}`}
                                />
                                <span class="text-body text-text">
                                    {#if choice === AUTO_RECHARGE}
                                        残高が少なくなったら自動で購入する（おすすめ）
                                    {:else}
                                        あとで決める（無償チケットだけで始める）
                                    {/if}
                                </span>
                            </label>
                        {/each}

                        {#if fundingChoice === AUTO_RECHARGE}
                            <div
                                class="rounded-sm border border-border p-3"
                                data-testid="funding-consent-terms"
                            >
                                <p class="text-caption text-text-secondary">
                                    残高が {consentTerms.thresholdCount} 枚を下回ると、登録済みのカードで不足分をまとめて購入し、{consentTerms.maxCount}
                                    枚まで補充します。1 回の自動購入の上限額は ¥{formatYen(
                                        consentTerms.maxAmountJpy,
                                    )}（税込・1 枚あたり ¥{formatYen(consentTerms.unitAmountJpy)}）です。
                                </p>
                                <p
                                    class="mt-1 text-caption text-text-secondary"
                                    data-testid="funding-consent-card-source"
                                >
                                    {#if paidPlan}
                                        お支払いは Stripe で行い、<strong
                                            >そのお支払いカードをオートリチャージにも使います</strong
                                        >。設定はいつでも変更・停止できます。
                                    {:else}
                                        次の画面でカードを登録します。登録しただけでは課金されません。設定はいつでも変更・停止できます。
                                    {/if}
                                </p>
                            </div>
                        {/if}

                        {#if fundingChoiceError !== null}
                            <p class="text-caption text-danger" data-testid="funding-choice-error">
                                {fundingChoiceError}
                            </p>
                        {/if}
                    </fieldset>
{/snippet}
