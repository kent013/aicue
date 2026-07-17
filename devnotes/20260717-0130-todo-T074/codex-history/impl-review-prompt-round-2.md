Round 1 の Critical 1 / Warning 2 を対応した。

## [Critical] `submitPaidPlan()` が `personal` を弾いていない

**対応した**（指摘が正しい。UI 分岐で通常は到達しないが、述語が崩れると無償プランが Stripe checkout へ混入する）。

- **props の `plans` を単一真実源**に `isPaidPlanCode()` を導出（`currentBaseAmount !== null` = 基本料金を持つものだけ有償。
  **personal は null** = Stripe checkout を通らない）。`submitPaidPlan()` の先頭で `if (!isPaidPlanCode(chosenPlanCode)) return;`。
  サーバ側も `assertStripeBillablePlan()` で fail-closed（422）だが**二重防御**（あなたの修正案どおり）。
- **回帰テストを追加**: 「無償プラン (personal) は有償 checkout へ送らない」
  （personal 選択時に `paid-plan-submit` が出ない + 自己申告 submit は `/onboarding/activate-personal` へ行き
  `/billing/checkout` は呼ばれない）。

## [Warning] `selectedPlanCode` の `$derived` 再代入

**対応した**。ただし **あなたの提案（`$state(computeInitialPlan(pageData))`）をそのまま採ると別の問題が出た**ので報告する:
Svelte が `state_referenced_locally`（"This reference only captures the initial value of `pageData`"）を警告し、
**Inertia の partial reload で props が変わっても追随しない**。

→ **override を `$state`・表示値を `$derived`** の正しい runes パターンにした:
```js
let chosenPlanCode = $state<string | null>(null);
const selectedPlanCode = $derived(chosenPlanCode ?? computeInitialPlan(pageData));
```
再代入を撤去し `chosenPlanCode` に一本化。**警告は消え、Onboarding の JS テスト 17 passed**。

## [Warning] owner 解決の効率 / PR 本文への明示

- owner 解決: **見送る**（あなた自身が「いまは変更不要」と明記。既存 `Organization::routeNotificationForMail()` と同一パターン）。
- PR 本文: **対応**（コミットメッセージに「P3 は `BillingAccess` / `RequireActiveSubscription` を未変更」を明記する）。

## テスト結果

- composer test: **1996 tests / 1994 passed / 0 failed / 2 skipped**（8203 assertions）
- composer phpstan: **[OK] No errors**（level 10）/ pint --test / pnpm lint / pnpm typecheck: passed
- Onboarding JS テスト: **17 passed**（Svelte 警告なし）/ pnpm build: 成功
- Architecture suite: 93 tests 緑（**allowlist 追加ゼロ**）/ JS arch: 30 passed（allowlist 追加なし）

## 追加差分

```diff
diff --git a/resources/js/pages/Onboarding/Checkout.svelte b/resources/js/pages/Onboarding/Checkout.svelte
new file mode 100644
index 0000000..a2fbc94
--- /dev/null
+++ b/resources/js/pages/Onboarding/Checkout.svelte
@@ -0,0 +1,273 @@
+<script lang="ts">
+    import { page as inertiaPage, router } from "@inertiajs/svelte";
+    import { CreditCard } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Checkbox from "@/components/atoms/Checkbox.svelte";
+    import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";
+    import type { PricingFeature } from "@/components/molecules/PricingPlanCard.types";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import type {
+        OnboardingCheckoutShape,
+        OnboardingOrganizationShape,
+        PlanShape,
+    } from "@/types/onboarding";
+
+    /**
+     * 課金オンボーディング: プラン選択 (current org スコープ)。
+     *
+     * - plan grid は PricingPlanCard を再利用する (プラン名・基本料金はサーバ確定値)。
+     * - Personal (無料) は Stripe checkout を通らず activate-personal へ POST する
+     *   (自己申告チェック = declaration が必須。サーバ FormRequest が権威)。
+     * - 有償プランは既存の billing.checkout へ POST する (Stripe Checkout へ full page redirect)。
+     * - ボタンは disabled にしない (DESIGN.md / AGENTS.md 禁止事項 #8)。eligibility 不成立・
+     *   declaration 未チェックでも押下でき、サーバが返した文言をそのまま表示する
+     *   (eligibility は render 後に変化しうるため サーバ判定が唯一の権威)。
+     * - 文言はすべてサーバ確定 (reasonLabel / errors) で frontend では組み立てない。
+     */
+    interface Props {
+        organization: OnboardingOrganizationShape;
+        pageData: OnboardingCheckoutShape;
+    }
+
+    let { organization, pageData }: Props = $props();
+
+    const shared = $derived(inertiaPage.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+    const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);
+
+    // defaultPlanCode は plans への包含を保証しない (コード値) ため、plans にある場合のみ
+    // preselect し、無ければ先頭 plan を強調する (決定的挙動)。
+    const computeInitialPlan = (data: OnboardingCheckoutShape): string | null =>
+        data.plans.some((p) => p.code === data.defaultPlanCode)
+            ? data.defaultPlanCode
+            : (data.plans[0]?.code ?? null);
+
+    let chosenPlanCode = $state<string | null>(null);
+    // 強調するカード = ユーザーが選んだもの。未選択なら props から導出した既定。
+    // $state に初期値を焼くと props 変更 (Inertia partial reload) に追随せず、
+    // $derived を再代入すると runes の再評価と競合するため、override を $state・表示値を $derived で持つ。
+    const selectedPlanCode = $derived(chosenPlanCode ?? computeInitialPlan(pageData));
+    let submitting = $state(false);
+    let declarationChecked = $state(false);
+
+    // サーバ由来エラーを「発生したプラン」にキー付けし、別プランへ切替えると旧エラーが消える。
+    let lastSubmittedPlanCode = $state<string | null>(null);
+
+    const isPersonal = (plan: PlanShape): boolean => plan.code === "personal";
+
+    // 表示寿命を「現在選択中プラン」に結合する: in-flight 中は非表示 (再 submit 時の旧エラー
+    // フラッシュ防止) + submit したプランを選択中のときだけ表示。
+    const planCodeError = $derived(
+        !submitting && chosenPlanCode !== null && chosenPlanCode === lastSubmittedPlanCode
+            ? (serverErrors.plan_code ?? null)
+            : null,
+    );
+    const declarationError = $derived(!submitting ? (serverErrors.declaration ?? null) : null);
+
+    // Personal が選べない理由 (サーバー確定文言)。押下は妨げず、理由を常時提示する。
+    const personalReasonLabel = $derived(
+        pageData.personalEligibility !== null && !pageData.personalEligibility.eligible
+            ? pageData.personalEligibility.reasonLabel
+            : null,
+    );
+
+    const buildFeatures = (plan: PlanShape): PricingFeature[] => {
+        if (!isPersonal(plan)) {
+            return [];
+        }
+
+        // 月次のチケット付与は廃止済 (常に 0 枚) のため表記しない (料金ページと同一方針)。
+        return [
+            { text: "基本料金なし（トレーニングに使うチケット代のみ）" },
+            {
+                text: "個人利用専用です。法人・チームでのご利用は Starter プラン以上をお選びください",
+            },
+        ];
+    };
+
+    const choosePlan = (plan: PlanShape): void => {
+        chosenPlanCode = plan.code;
+    };
+
+    // Personal (無料) の有効化。declaration 未チェックでも送信し、サーバの文言を表示する
+    // (押下時にエラー表示 = 禁止事項 #8)。
+    const submitPersonalFree = (): void => {
+        if (submitting) return; // 多重送信ガード (disabled にはしない)
+        lastSubmittedPlanCode = "personal";
+        router.post(
+            "/onboarding/activate-personal",
+            { declaration: declarationChecked ? "1" : "0" },
+            {
+                onStart: () => {
+                    submitting = true;
+                },
+                onFinish: () => {
+                    submitting = false;
+                },
+            },
+        );
+    };
+
+    // 選択中の code が「有償プラン」か (= billing/checkout へ送ってよいか)。
+    // props の plans を単一真実源にし、基本料金を持つものだけを有償とみなす
+    // (personal は currentBaseAmount が null = Stripe checkout を通らない)。
+    const isPaidPlanCode = (code: string | null): boolean =>
+        code !== null && pageData.plans.some((p) => p.code === code && p.currentBaseAmount !== null);
+
+    // 有償プランの契約開始。既存の課金 checkout (Stripe Checkout へ full page redirect) に載せる。
+    const submitPaidPlan = (): void => {
+        if (submitting || chosenPlanCode === null) return;
+        // 無償プラン (personal) を有償 checkout へ送らない。UI 分岐でも到達しないが、
+        // 述語が崩れたときに Stripe checkout へ無償プランが混入する余地を構造的に消す
+        // (サーバ側も assertStripeBillablePlan で fail-closed だが、二重防御)。
+        if (!isPaidPlanCode(chosenPlanCode)) return;
+        lastSubmittedPlanCode = chosenPlanCode;
+        router.post(
+            "/billing/checkout",
+            { plan_code: chosenPlanCode },
+            {
+                onStart: () => {
+                    submitting = true;
+                },
+                onFinish: () => {
+                    submitting = false;
+                },
+            },
+        );
+    };
+
+    const showRecommendedBadge = (planCode: string): boolean =>
+        planCode === pageData.recommendedPlanCode;
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title={`ようこそ、${organization.name}`}
+            description="利用を開始するにはプランを選択してください。"
+            icon={CreditCard}
+            testId="onboarding-checkout-heading"
+        />
+        <PageContent>
+            <div class="flex flex-col gap-6" data-testid="onboarding-checkout">
+                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="plan-grid">
+                    {#each pageData.plans as plan (plan.code)}
+                        <PricingPlanCard
+                            name={plan.name}
+                            priceAmount={isPersonal(plan) ? 0 : plan.currentBaseAmount}
+                            features={buildFeatures(plan)}
+                            isHighlighted={selectedPlanCode === plan.code}
+                            testId={`plan-card-${plan.code}`}
+                        >
+                            {#snippet footerCta()}
+                                <div class="flex flex-col gap-2">
+                                    {#if showRecommendedBadge(plan.code)}
+                                        <span
+                                            class="self-start rounded-sm bg-primary/10 px-2 py-0.5 text-caption text-primary"
+                                            data-testid={`recommended-badge-${plan.code}`}
+                                        >
+                                            おすすめ
+                                        </span>
+                                    {/if}
+                                    <Button
+                                        onclick={() => choosePlan(plan)}
+                                        testId={`select-plan-${plan.code}`}
+                                    >
+                                        {chosenPlanCode === plan.code ? "選択中" : "選択"}
+                                    </Button>
+                                    {#if isPersonal(plan) && personalReasonLabel !== null}
+                                        <!-- 選択自体は妨げない (サーバが最終判定)。理由を常時提示する。 -->
+                                        <p
+                                            class="text-caption text-text-secondary"
+                                            data-testid="personal-eligibility-reason"
+                                        >
+                                            {personalReasonLabel}
+                                        </p>
+                                    {/if}
+                                </div>
+                            {/snippet}
+                        </PricingPlanCard>
+                    {/each}
+                </div>
+
+                {#if chosenPlanCode === "personal"}
+                    <!-- Personal (無料) は Stripe checkout を通らない。自己申告チェック + 無料開始 CTA。 -->
+                    <div class="flex flex-col gap-4" data-testid="personal-free-step">
+                        {#if planCodeError !== null}
+                            <Alert type="danger" testId="checkout-plan-error">
+                                {planCodeError}
+                            </Alert>
+                        {/if}
+
+                        <div>
+                            <p class="text-body font-medium text-text">
+                                パーソナルプラン（無料）で始める
+                            </p>
+                            <p class="mt-1 text-caption text-text-secondary">
+                                基本料金はかかりません。トレーニングの実行に使うチケットのみ購入制です（新規登録特典として
+                                チケット {pageData.signupGrantTickets} 枚を無償でお付けします）。カード登録なしでも始められます。
+                            </p>
+                        </div>
+
+                        <Checkbox
+                            id="personal-declaration"
+                            bind:checked={declarationChecked}
+                            label="個人での利用であり、法人・チームでの利用ではないことを確認しました"
+                            error={declarationError}
+                            testId="personal-declaration"
+                        />
+
+                        <div>
+                            <Button
+                                onclick={submitPersonalFree}
+                                loading={submitting}
+                                testId="personal-free-submit"
+                            >
+                                無料プランを開始する
+                            </Button>
+                            <p class="mt-2 text-caption text-text-secondary">
+                                決済画面には進みません。すぐに利用を開始できます。
+                            </p>
+                        </div>
+                    </div>
+                {:else if chosenPlanCode !== null}
+                    <div class="flex flex-col gap-4" data-testid="paid-plan-step">
+                        {#if planCodeError !== null}
+                            <Alert type="danger" testId="checkout-plan-error">
+                                {planCodeError}
+                            </Alert>
+                        {/if}
+
+                        <div>
+                            <Button
+                                onclick={submitPaidPlan}
+                                loading={submitting}
+                                testId="paid-plan-submit"
+                            >
+                                この内容で契約を進める
+                            </Button>
+                            <p class="mt-2 text-caption text-text-secondary">
+                                次の画面で決済に進みます。
+                            </p>
+                        </div>
+                    </div>
+                {/if}
+
+                <p class="text-caption text-text-secondary">
+                    <!-- contactUrl は内部 path / 外部 URL / mailto のいずれにもなりうる (ContactUrl が
+                         解決する) ため、素の <a> で全パターンを同じ扱いにする (Welcome と同規約)。 -->
+                    Enterprise プランをご検討の場合は <a
+                        href={pageData.contactUrl}
+                        class="text-primary underline"
+                        data-testid="onboarding-contact-link">お問い合わせ</a
+                    > ください。
+                </p>
+            </div>
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/tests/js/pages/OnboardingCheckout.test.ts b/tests/js/pages/OnboardingCheckout.test.ts
new file mode 100644
index 0000000..2a5b0d4
--- /dev/null
+++ b/tests/js/pages/OnboardingCheckout.test.ts
@@ -0,0 +1,244 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import Checkout from "@/pages/Onboarding/Checkout.svelte";
+import type { OnboardingCheckoutShape } from "@/types/onboarding";
+
+// router.post をモックする。page (Inertia store) も hoisted fake でモックし、props.errors を
+// 注入して「押下 → サーバが redirect-back で返した文言を表示する」経路 (D4) を検証する。
+const { routerPostMock, pageState } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    pageState: { props: {} as Record<string, unknown> },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: {
+        post: routerPostMock,
+    },
+    page: pageState,
+}));
+
+/*
+ * 課金オンボーディングのプラン選択画面。
+ * - plan grid はサーバが露出したプラン (is_active=true ∧ PlanCode 集合) のみを出す
+ * - D4 (AGENTS.md 禁止事項 #8): 必須条件未充足でも CTA は押せ、押下後にサーバ文言を表示する
+ *   (文言はすべてサーバ確定 = frontend で組み立てない)
+ * - D28: 月次チケット付与は廃止済 = 「月 N 枚」表記を出さない
+ */
+
+const organization = { id: 1, name: "テスト組織", slug: "test-org" };
+
+const basePageData: OnboardingCheckoutShape = {
+    plans: [
+        { code: "personal", name: "Personal", currentBaseAmount: null, isActive: true },
+        { code: "starter", name: "Starter", currentBaseAmount: 4980, isActive: true },
+        { code: "standard", name: "Standard", currentBaseAmount: 19800, isActive: true },
+    ],
+    recommendedPlanCode: "standard",
+    defaultPlanCode: "starter",
+    contactUrl: "/contact?source=onboarding",
+    personalEligibility: { eligible: true, reason: null, reasonLabel: null },
+    signupGrantTickets: 10,
+};
+
+afterEach(() => {
+    cleanup();
+    routerPostMock.mockReset();
+    pageState.props = {}; // errors 注入をリセット (テスト間の汚染防止)
+});
+
+function renderPage(overrides: Partial<OnboardingCheckoutShape> = {}): void {
+    render(Checkout, {
+        props: { organization, pageData: { ...basePageData, ...overrides } },
+    });
+}
+
+async function choosePersonal(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("select-plan-personal"));
+}
+
+describe("Onboarding/Checkout", () => {
+    it("サーバが露出したプランのみを plan grid に出す (未露出 code は出ない)", () => {
+        renderPage();
+
+        expect(screen.getByTestId("plan-card-personal")).toBeInTheDocument();
+        expect(screen.getByTestId("plan-card-starter")).toBeInTheDocument();
+        expect(screen.getByTestId("plan-card-standard")).toBeInTheDocument();
+        // business / enterprise / legacy free はサーバの露出規則 (is_active=true ∧ PlanCode 集合)
+        // から外れるため props に来ない = 描画されない
+        expect(screen.queryByTestId("plan-card-business")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("plan-card-free")).not.toBeInTheDocument();
+        expect(screen.getByTestId("recommended-badge-standard")).toBeInTheDocument();
+        // Personal は基本料金なし = 無料表示契約
+        expect(screen.getByTestId("plan-card-personal")).toHaveTextContent("無料");
+    });
+
+    it("defaultPlanCode が plans にあるときはそれを強調する", () => {
+        renderPage();
+
+        expect(screen.getByTestId("plan-card-starter")).toHaveClass("border-primary");
+        expect(screen.getByTestId("plan-card-personal")).not.toHaveClass("border-primary");
+    });
+
+    it("defaultPlanCode が plans に無いときは先頭 plan を強調する", () => {
+        renderPage({ defaultPlanCode: "business" });
+
+        expect(screen.getByTestId("plan-card-personal")).toHaveClass("border-primary");
+        expect(screen.getByTestId("plan-card-starter")).not.toHaveClass("border-primary");
+    });
+
+    it("月次付与は廃止済のため「月 N 枚」表記を出さない (D28)", async () => {
+        renderPage();
+        await choosePersonal();
+
+        expect(screen.getByTestId("onboarding-checkout").textContent ?? "").not.toMatch(
+            /月\s*\d+\s*枚/,
+        );
+    });
+
+    it("declaration 未チェックでも Personal CTA は押せ、declaration=0 で送信する", async () => {
+        renderPage();
+        await choosePersonal();
+
+        const submit = screen.getByTestId("personal-free-submit");
+        expect(submit).not.toBeDisabled();
+
+        await fireEvent.click(submit);
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/onboarding/activate-personal",
+            { declaration: "0" },
+            expect.anything(),
+        );
+    });
+
+    it("declaration 未チェックで押下した後、サーバが返した validation error を表示する", async () => {
+        // redirect-back 着地 (errors 付き props) を再現する
+        pageState.props = { errors: { declaration: "個人利用であることの確認が必要です。" } };
+        renderPage();
+        await choosePersonal();
+
+        expect(screen.getByText("個人利用であることの確認が必要です。")).toBeInTheDocument();
+    });
+
+    it("declaration チェック時は declaration=1 で送信する", async () => {
+        renderPage();
+        await choosePersonal();
+
+        await fireEvent.click(screen.getByTestId("personal-declaration"));
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/onboarding/activate-personal",
+            { declaration: "1" },
+            expect.anything(),
+        );
+    });
+
+    it("eligible=false でも Personal は選択でき CTA も押せる (理由はサーバ由来文言を常時提示)", async () => {
+        renderPage({
+            personalEligibility: {
+                eligible: false,
+                reason: "organization_has_multiple_members",
+                reasonLabel: "メンバーが 2 名以上の組織では選択できません",
+            },
+        });
+
+        expect(screen.getByTestId("personal-eligibility-reason")).toHaveTextContent(
+            "メンバーが 2 名以上の組織では選択できません",
+        );
+
+        const selectPersonal = screen.getByTestId("select-plan-personal");
+        expect(selectPersonal).not.toBeDisabled();
+        await fireEvent.click(selectPersonal);
+
+        const submit = screen.getByTestId("personal-free-submit");
+        expect(submit).not.toBeDisabled();
+        await fireEvent.click(submit);
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+
+    it("eligible=false の押下後にサーバ確定文言 (errors.plan_code) を表示する", async () => {
+        pageState.props = { errors: { plan_code: "有効な契約がある組織では選択できません" } };
+        renderPage({
+            personalEligibility: {
+                eligible: false,
+                reason: "organization_has_active_subscription",
+                reasonLabel: "有効な契約がある組織では選択できません",
+            },
+        });
+        await choosePersonal();
+
+        // 押下前は plan エラーを出さない (押下したプランに紐づけて表示する)
+        expect(screen.queryByTestId("checkout-plan-error")).not.toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+        expect(screen.getByTestId("checkout-plan-error")).toHaveTextContent(
+            "有効な契約がある組織では選択できません",
+        );
+    });
+
+    it("eligibility が null でも描画が壊れず CTA は押せる", async () => {
+        renderPage({ personalEligibility: null });
+        await choosePersonal();
+
+        expect(screen.queryByTestId("personal-eligibility-reason")).not.toBeInTheDocument();
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+
+    it("有償プランは plan_code のみを課金 checkout に送る", async () => {
+        renderPage();
+
+        await fireEvent.click(screen.getByTestId("select-plan-starter"));
+        expect(screen.queryByTestId("personal-free-step")).not.toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("paid-plan-submit"));
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/billing/checkout",
+            { plan_code: "starter" },
+            expect.anything(),
+        );
+    });
+
+    it("無償プラン (personal) は有償 checkout へ送らない (Stripe checkout へ混入させない)", async () => {
+        // props の plans を単一真実源に「基本料金を持つものだけ有償」と判定する。
+        // personal は currentBaseAmount=null なので、仮に有償 submit 経路へ到達しても送信しない。
+        renderPage();
+        await choosePersonal();
+
+        // personal 選択時は有償 submit ボタン自体が出ない (UI 分岐)
+        expect(screen.queryByTestId("paid-plan-submit")).not.toBeInTheDocument();
+        // 無償プランの自己申告 submit は billing/checkout ではなく activate-personal へ行く
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/onboarding/activate-personal",
+            expect.anything(),
+            expect.anything(),
+        );
+        expect(routerPostMock).not.toHaveBeenCalledWith(
+            "/billing/checkout",
+            expect.anything(),
+            expect.anything(),
+        );
+    });
+
+    it("プランを切り替えると前のプランで出たエラーが消える", async () => {
+        pageState.props = { errors: { plan_code: "パーソナルプランは選択できません" } };
+        renderPage();
+        await choosePersonal();
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+        expect(screen.getByTestId("checkout-plan-error")).toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("select-plan-standard"));
+        expect(screen.queryByTestId("checkout-plan-error")).not.toBeInTheDocument();
+    });
+
+    it("問い合わせ導線 (Enterprise) をサーバ由来 URL で出す", () => {
+        renderPage();
+
+        expect(screen.getByTestId("onboarding-contact-link")).toHaveAttribute(
+            "href",
+            "/contact?source=onboarding",
+        );
+    });
+});
```

残る穴があれば指摘し、無ければ APPROVED を出してほしい。
