<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\UpdateBillingContactAction;
use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\DataTransferObjects\Billing\BillingContactDto;
use App\DataTransferObjects\Billing\BillingDashboardDto;
use App\DataTransferObjects\Billing\BillingFeedbackDto;
use App\DataTransferObjects\Billing\BillingPlansPageDto;
use App\DataTransferObjects\Billing\QuotaStatusDto;
use App\DataTransferObjects\Billing\UpdateBillingContactData;
use App\DataTransferObjects\Marketing\PricingPlanDto;
use App\Enums\Billing\BillingFeedbackKind;
use App\Enums\Billing\OnboardingBillingState;
use App\Enums\Billing\SignupFundingChoice;
use App\Enums\Billing\SubscriptionSwapOutcome;
use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Exceptions\Billing\CheckoutInProgressException;
use App\Exceptions\Billing\PlanChangeFailedException;
use App\Exceptions\Billing\PlanChangeNotAllowedException;
use App\Exceptions\Billing\StaleCheckoutAttemptException;
use App\Exceptions\Billing\StalePlanChangeException;
use App\Exceptions\Billing\StripePriceNotSyncedException;
use App\Exceptions\Billing\SubscriptionAttemptPlanMismatchException;
use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingCheckoutRequest;
use App\Http\Requests\Billing\ChangePlanRequest;
use App\Http\Requests\Billing\StartAutoRechargeSetupRequest;
use App\Http\Requests\Billing\UpdateAutoRechargeRequest;
use App\Http\Requests\Billing\UpdateBillingContactRequest;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\QuotaService;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TicketLedgerService;
use App\Services\Capture\StorageUsageService;
use App\Services\Marketing\PricingService;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Services\Onboarding\OnboardingReturnResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Webmozart\Assert\Assert;

/**
 * 課金画面 (current org スコープ)。
 *
 * - 新規契約は Stripe Checkout、契約中プランの変更は in-app swap (`changePlan`)、
 *   解約・支払い方法は Customer Portal。いずれもアプリは plan_code を直接書かない
 *   (organizations.plan_code は webhook で同期される)
 * - 閲覧は組織メンバー全員、Checkout / Portal / オートリチャージ設定は
 *   manageBilling (owner / admin) のみ
 */
class BillingController extends Controller
{
    use ResolvesRouteOrganization;

    public function __construct(
        private readonly BillingAccess $access,
        private readonly IntendedPlanResolver $intendedPlanResolver,
        private readonly OnboardingReturnResolver $returnResolver,
        private readonly AutoRechargeService $autoRecharge,
    ) {}

    /**
     * 課金ダッシュボード (現在プラン / per-bucket チケット残高 / quota 上限 / 導線)。
     *
     * P8b (bs-14): プラン一覧は /billing/plans へ移設し、ここは請求ダッシュボードに寄せる。
     * props は BillingDashboardDto の 1 本 (禁止事項 #4)。
     *
     * **着地 (landing) の優先順位** — 先着が redirect を返したら後段は評価しない:
     *   1. `?setup_session_id` … P8a カード登録の戻り
     *   2. `?session_id` かつ funding=auto_recharge の完了行 … T1004 (→ ?highlight=auto-recharge)
     *   3. `?session_id` / `?portal` … P9 着地 feedback (本 one-shot バナー)
     *   4. 着地 query 無し … 通常 render
     * いずれも **DTO 構築より前**に置く: resolveOnboardingContinue() は return_to を
     * peek + forget (消費) するため、hop する request で DTO を組むと復帰先を無音で失う。
     */
    public function index(
        Request $request, Organization $organization,
        TicketLedgerService $tickets,
        QuotaService $quota,
        PricingService $pricing,
        StorageUsageService $storage,
    ): Response|RedirectResponse {
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // カード登録 (mode=setup) の着地。GET で副作用を起こさないよう、検証済みの
        // ?setup_session_id を消費して 303 + flash で canonical URL へ倒す
        // (リロード・共有時に query が残らない)。
        $landing = $this->resolveAutoRechargeSetupLanding($request, $organization);
        if ($landing !== null) {
            return $landing;
        }

        // T1004: funding=auto_recharge の契約完了着地は ?highlight=auto-recharge へ 303 + flash
        // (オートリチャージ設定への導線を成功着地の主役にする)。非該当なら通常 feedback へ委ねる。
        $autoRechargeLanding = $this->resolveAutoRechargeLanding($request, $organization);
        if ($autoRechargeLanding !== null) {
            return $autoRechargeLanding;
        }

        // P9: 決済戻り着地 (?session_id / ?portal) を canonical URL へ畳む (one-shot の担保)。
        $feedbackLanding = $this->resolveBillingFeedbackLanding($request, $organization);
        if ($feedbackLanding !== null) {
            return $feedbackLanding;
        }

        $canManageBilling = $user->can('manageBilling', $organization);
        $subscription = $organization->subscription('default');

        $dto = new BillingDashboardDto(
            plan: $this->resolveCurrentPlan($organization, $pricing),
            billingState: $this->access->state($organization),
            currentPeriodEnd: $subscription instanceof Subscription
                ? $subscription->current_period_end?->toIso8601String()
                : null,
            balance: $tickets->balance($organization),
            // 使用量の数え方は「実際に止まる判定」と同じ経路に揃える
            // (プロジェクト数 = ProjectService::create の判定、容量 = checkAddition に渡す占有量)。
            // 新しい集計機構やキャッシュは作らない (二重帳簿禁止)。
            quotas: QuotaStatusDto::build(
                $quota->limits($organization),
                $organization->projects()->count(),
                $storage->occupiedBytes($organization),
            ),
            canManageBilling: $canManageBilling,
            continueUrl: $this->resolveOnboardingContinue($organization),
            // P8a: オートリチャージ設定カード。subscription 有無に依存せず常に非 null
            // (無料パーソナル含む全プランが対象。**既定は enabled=false の opt-in**)。
            autoRecharge: $this->autoRecharge->settingsFor($organization, $canManageBilling),
            // カード登録開始 POST の attempt_token (render 単位。setup は課金を伴わないため
            // 購入導線のようなサーバ側安定化は不要 — 同一 token の再送は台帳 unique で冪等)。
            autoRechargeSetupToken: strtolower((string) Str::ulid()),
            // P9: 着地 hop が積んだ one-shot フィードバック (flash = 次の 1 render のみ生存)。
            feedback: $this->resolveFlashedFeedback($request),
            // P9: 請求先連絡先 (未設定なら owner email が実際の宛先)。
            billingContact: BillingContactDto::fromOrganization($organization),
        );

        return Inertia::render('Billing/Index', ['page' => $dto->toArray()]);
    }

    /**
     * プラン比較ページ (P8b / bs-6)。閲覧は組織メンバー全員、変更は manageBilling のみ。
     *
     * プラン台帳 → DTO の mapper は公開料金表と共有する (新 DTO を発明しない)。
     */
    public function plans(Request $request, Organization $organization, PricingService $pricing): Response
    {
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $subscription = $organization->subscription('default');

        $dto = new BillingPlansPageDto(
            plans: $pricing->listPublicPlans(),
            currentPlanCode: $this->resolveCurrentPlanCode($organization),
            billingState: $this->access->state($organization),
            canManage: $user->can('manageBilling', $organization),
            // P9: 契約 checkout の冪等 token (画面 render ごとに固定 = 1 render 1 token)。
            subscriptionAttemptToken: (string) Str::ulid(),
            // F-3-01: startCheckout 段 1 の guard と同一述語 (valid()) で経路を分ける
            hasChangeableSubscription: $subscription instanceof Subscription && $subscription->valid(),
            planChangeToken: (string) Str::ulid(),
            // 競合制御の期待値は projection ではなく列そのもの (currentPlanCode と別物)
            planChangeExpectedPlanCode: $organization->plan_code,
        );

        return Inertia::render('Billing/Plans', ['page' => $dto->toArray()]);
    }

    /**
     * 表示用の現在プラン code。
     *
     * ActiveFreePlan は free_plan_code が正 (canceled サブスク行が残る paid→free 経路で
     * plan_code に旧 paid が残るため)。**表示専用**であり gate 判定には使わない
     * (判定は BillingAccess::state() 一本)。
     */
    private function resolveCurrentPlanCode(Organization $organization): ?string
    {
        return $this->access->state($organization) === OnboardingBillingState::ActiveFreePlan
            ? $organization->free_plan_code
            : $organization->plan_code;
    }

    /** 表示用の現在プラン (台帳に無い code / 未契約は null)。 */
    private function resolveCurrentPlan(Organization $organization, PricingService $pricing): ?PricingPlanDto
    {
        $code = $this->resolveCurrentPlanCode($organization);
        if ($code === null) {
            return null;
        }

        foreach ($pricing->listPublicPlans() as $plan) {
            if ($plan->code === $code) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * P9: Stripe Checkout (サブスク契約) を **冪等** に開始し、Checkout URL へリダイレクトする。
     *
     * 実行順は不変条件 #2 (「不整合は認可より前に 404」) に従う:
     * (1) 他 org / 他 user の token は Gate より前に 404 (403 にしない = 存在オラクル封じ)
     * (2) 認可 (3) T1004 の事前同意記録 (4) plan 解決 → 冪等開始。
     *
     * ボタンを disabled にはしない (禁止事項 #8) ため、ここで返すエラー・422 が
     * 押下時のフィードバックになる。
     */
    public function checkout(
        BillingCheckoutRequest $request, Organization $organization,
        SubscriptionService $subscriptions,
        AutoRechargeService $autoRecharge,
    ): SymfonyResponse|RedirectResponse {

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $attemptToken = $request->validated('subscription_attempt_token');
        Assert::string($attemptToken);

        // (1) 他 org / 他 user の token は 404 (Gate より前 = 存在オラクル封じ)
        abort_if($subscriptions->attemptTokenIsForeign($attemptToken, $organization, $user), 404);

        // (2) 認可
        Gate::authorize('manageBilling', $organization);

        // (3) T1004: funding=auto_recharge は事前同意 (enabled=false) を Checkout 開始前に記録する。
        //     Checkout が後段で失敗・放棄されても同意 row は無害 (enabled=false = 課金は発生しない)。
        $fundingRaw = $request->validated('funding_choice');
        $funding = is_string($fundingRaw) ? SignupFundingChoice::from($fundingRaw) : null;
        if ($funding === SignupFundingChoice::AutoRecharge) {
            $consentVersion = $request->validated('consent_version');
            Assert::stringNotEmpty($consentVersion);
            try {
                $autoRecharge->recordPreConsent($organization, $user, new AutoRechargeConsentDto($consentVersion));
            } catch (CheckoutInProgressException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        // (4) plan 解決 → 冪等開始
        $planCode = $request->validated('plan_code');
        Assert::string($planCode);
        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

        try {
            $result = $subscriptions->startCheckout(
                $organization,
                $user,
                $plan,
                route('billing.index', ['organization' => $organization->slug]).'?session_id={CHECKOUT_SESSION_ID}',
                route('billing.plans', ['organization' => $organization->slug]),
                $attemptToken,
                $funding,
            );
        } catch (SubscriptionAttemptPlanMismatchException $e) {
            // 同 token・別 plan (1 render 1 token のため「戻って別プランを押す」で実在する)
            throw ValidationException::withMessages(['plan_code' => $e->getMessage()]);
        } catch (StaleCheckoutAttemptException) {
            // 着地 query は発明しない: 自前 redirect なので最初から one-shot flash に載せる。
            return $this->redirectWithFeedback($organization, BillingFeedbackKind::CheckoutRetryRequired);
        } catch (CheckoutInProgressException $e) {
            return back()->with('error', $e->getMessage());
        } catch (StripePriceNotSyncedException) {
            // production の sync 漏れ。500 にせず現行と同一文言で差し戻す
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        } catch (InvalidArgumentException $e) {
            // 既に有効なサブスクリプションがある / Price 未設定 (service 層の fail-closed ガード)
            return back()->with('error', $e->getMessage());
        }

        if ($result->url === null) {
            // url=null は「新規 Checkout を作らなかった」= 受付済み replay か live pending dedup。
            return $subscriptions->isAttemptCompleted($organization, $result->stripeSessionId)
                ? $this->redirectWithFeedback($organization, BillingFeedbackKind::PurchaseAlreadyReceived)
                : back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。');
        }

        // 契約開始が成立したのでプラン意図を消費する (checkout URL 取得後・遷移前)。
        // 開始不可の back() 経路では forget しない = 意図を維持して再試行できる。
        $this->intendedPlanResolver->forgetForOrganization($organization);

        // 外部 URL への遷移は Inertia::location (full page redirect)
        return Inertia::location($result->url);
    }

    /**
     * F-3-01: 契約中プランの変更 (in-app swap)。`checkout()` と排他の経路。
     *
     * 実行順: (1) 認可 → (2) 契約の存在確認 (無ければ 422 で新規契約導線へ) →
     * (3) plan 解決 → (4) Service へ委譲。**アプリは plan_code を直接書かない**
     * (反映は webhook 同期)。
     *
     * ボタンを disabled にはしない (禁止事項 #8) ため、ここで返す error flash / 422 が
     * 押下時のフィードバックになる。
     */
    public function changePlan(
        ChangePlanRequest $request, Organization $organization,
        SubscriptionService $subscriptions,
    ): RedirectResponse {
        Gate::authorize('manageBilling', $organization);

        $subscription = $organization->subscription('default');
        if (! $subscription instanceof Subscription || ! $subscription->valid()) {
            // 未契約 / 失効済みは 500 (service 内 guard) に到達させず 422 で新規契約導線へ倒す。
            throw ValidationException::withMessages([
                'plan_code' => '有効なご契約がないため変更できません。プランのお申し込みからお手続きください。',
            ]);
        }

        $planCode = $request->validated('plan_code');
        Assert::string($planCode);
        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

        $token = $request->validated('plan_change_token');
        Assert::string($token);
        $expected = $request->validated('current_plan_code');
        Assert::nullOrString($expected);

        try {
            $outcome = $subscriptions->changePlan($organization, $plan, $token, $expected);
        } catch (StalePlanChangeException) {
            // 競合検知。errors.plan_code は Plans.svelte の確認 modal 内 Alert に出て、
            // redirect-back で props (currentPlanCode) も最新化される。
            throw ValidationException::withMessages([
                'plan_code' => 'プランが別の操作で変更されました。最新の内容をご確認のうえ、必要であれば改めて変更してください。',
            ]);
        } catch (StripePriceNotSyncedException) {
            // production の sync 漏れ。500 にせず checkout と同一文言で差し戻す
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        } catch (PlanChangeNotAllowedException|PlanChangeFailedException|CheckoutInProgressException $e) {
            // 業務拒否 (NotAllowed) / 外部障害 (Failed) / lock 競合のみを利用者向け文言に変換する。
            // **`InvalidArgumentException` は catch しない** — Assert 由来の前提違反・設定不備は
            // 500 に落として調査対象にする (内部文言を flash に載せない)。
            return back()->with('error', $e->getMessage());
        }

        // accepted までを成功とし、projection (plan_code) の追随は webhook が担うことを文言で表す。
        return redirect()->route('billing.index', ['organization' => $organization->slug])->with(
            'success',
            $outcome === SubscriptionSwapOutcome::Applied
                ? 'プラン変更を受け付けました。反映まで数分かかる場合があります。'
                : 'このプランへの変更は受付済みです。反映まで数分かかる場合があります。',
        );
    }

    /**
     * P9: 請求先連絡先 (メール / 宛名) の更新。current-org スコープ
     * (route parameter を持たないため cross-org 指定が構造的に不能)。
     */
    public function updateBillingContact(
        UpdateBillingContactRequest $request, Organization $organization,
        UpdateBillingContactAction $action,
    ): RedirectResponse {
        Gate::authorize('manageBilling', $organization);

        $action->execute($organization, UpdateBillingContactData::fromRequest($request));

        // 操作系 POST/PATCH は back() で完結させる (禁止事項 #7)
        return back()->with('info', '請求先情報を更新しました。');
    }

    /**
     * P8a: オートリチャージ設定の更新 (有効化 / 停止 / 閾値・上限の変更)。
     *
     * 有効化は Service 側で fail-closed (default PM 必須 + 同意必須)。停止は同一 lock 下で
     * pending attempt をキャンセルする (停止後課金の禁止)。
     */
    public function updateAutoRecharge(UpdateAutoRechargeRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageBilling', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $enabled = $request->boolean('enabled');
        // Laravel の integer ルールは値を cast しないため、明示的に型を確定してから渡す
        // (範囲・相関の検証は FormRequest が済ませている)。
        $threshold = $request->integer('threshold_count');
        $max = $request->integer('max_count');

        $consentVersion = $request->validated('consent_version');
        $consent = is_string($consentVersion) && $consentVersion !== ''
            ? new AutoRechargeConsentDto($consentVersion)
            : null;

        $wasEnabled = $this->autoRecharge->isEnabledFor($organization);

        try {
            $this->autoRecharge->updateSettings($organization, $user, $enabled, $threshold, $max, $consent);
        } catch (CheckoutInProgressException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = match (true) {
            $enabled => 'オートリチャージを設定しました。残高が少なくなったら自動で補充します。',
            $wasEnabled => 'オートリチャージを停止しました。今後、自動購入は行われません。再開はいつでもこの画面からできます。',
            default => 'オートリチャージ設定を保存しました。カード登録後にこの内容で有効化できます。',
        };

        // 操作系 POST は back() で完結させる (禁止事項 #7: redirect()->intended() は使わない)
        return back()->with('success', $message);
    }

    /**
     * P8a: オートリチャージ用カード登録 (Checkout mode=setup) を開始する。
     * attempt_token 冪等は purchase-tickets と同型 (二重 submit で別 session を作らない)。
     */
    public function startAutoRechargeSetup(StartAutoRechargeSetupRequest $request, Organization $organization): SymfonyResponse|RedirectResponse
    {
        Gate::authorize('manageBilling', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $token = $request->validated('attempt_token');
        Assert::stringNotEmpty($token);

        $result = $this->autoRecharge->startSetupCheckout(
            $organization,
            $user,
            route('billing.index', ['organization' => $organization->slug]).'?setup_session_id={CHECKOUT_SESSION_ID}',
            route('billing.index', ['organization' => $organization->slug]),
            $token,
        );

        if ($result['url'] === null) {
            return back()->with('warning', '既に進行中のカード登録があります。');
        }

        return Inertia::location($result['url']);
    }

    /**
     * 着地 hop を跨いで **保持する** query (着地 query は畳んで落とす)。
     *
     * `highlight` は「どのカードへスクロールするか」だけを表す**副作用のない anchor**で、
     * 状態を主張しないためリロードで再適用されても嘘にならない = 保持してよい。
     * 逆に**状態を主張する query (`session_id` / `portal` / `setup_session_id`) は保持しない**
     * — 保持すると one-shot 契約が壊れる。query を追加する人はこの基準で振り分けること。
     *
     * @var list<string>
     */
    private const PRESERVED_LANDING_QUERY = ['highlight'];

    /**
     * 着地 query を畳んだ canonical `/billing` への 303 を返す。
     *
     * `/billing` の着地 3 系統 (setup_session_id / T1004 / feedback) が**共通で使う**
     * 唯一の canonical URL 構築点。着地 query (`setup_session_id` / `session_id` / `portal`) は
     * 引き継がず、`PRESERVED_LANDING_QUERY` に載っている query だけを引き継ぐ。
     *
     * **flash には触れない** (純粋な URL 構築)。flash の扱いは各着地の判断
     * (T1004 は「成功着地で error を延命しない」を明示的に選んでいる)。
     *
     * @param  array<string, string>  $extra  呼び出し側が立てる query (保持分より優先)
     */
    private function canonicalBillingRedirect(Request $request, Organization $organization, array $extra = []): RedirectResponse
    {
        $preserved = [];
        foreach (self::PRESERVED_LANDING_QUERY as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $preserved[$key] = $value;
            }
        }

        return redirect()->route('billing.index', ['organization' => $organization->slug, ...$preserved, ...$extra], 303);
    }

    /**
     * カード登録着地 (`?setup_session_id=...`) を検証して 303 + flash に倒す。
     *
     * - session id は**自 org の SetupPaymentMethod 台帳行**に一致する場合のみ成功文言を出す
     *   (cross-org の session id を投げ込んでも成功と誤認させない = IDOR 防御)
     * - 状態の書き込みは webhook (SetDefaultPaymentMethodJob) の管轄。ここは表示のみ
     *   = **GET で副作用を起こさない**
     * - 欠落時は素通し (通常の課金ページ表示)
     */
    private function resolveAutoRechargeSetupLanding(Request $request, Organization $organization): ?RedirectResponse
    {
        $sessionId = $request->query('setup_session_id');
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $session = BillingCheckoutSession::query()
            ->where('organization_id', $organization->getKey())
            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
            ->where('stripe_session_id', $sessionId)
            ->first();

        if ($session === null) {
            // 未追跡 session — 成功文言は出さず canonical URL へ倒すだけ (query を残さない)。
            return $this->canonicalBillingRedirect($request, $organization);
        }

        $message = $session->status === CheckoutSessionStatus::Completed->value
            ? 'お支払いカードを登録しました。'
            : 'お支払いカードの登録を受け付けました。反映まで少しお待ちください。';

        return $this->canonicalBillingRedirect($request, $organization)->with('success', $message);
    }

    /**
     * P9 (T1004): funding=auto_recharge の契約完了着地を `?highlight=auto-recharge` へ 303 する。
     *
     * 自 org の `subscription_start` + `completed` + `funding_choice=auto_recharge` を検証できた
     * ときだけ変換する (他 org / `setup_payment_method` の session_id は素通し = IDOR 防御)。
     * 文言は「実際に PM 流用 Job が dispatch 済み (= 決済確定) かつ有効な事前同意が待機中」の
     * ときだけ確定表現にし、それ以外は fail-closed な誘導文言に落とす。
     */
    private function resolveAutoRechargeLanding(Request $request, Organization $organization): ?RedirectResponse
    {
        $sessionId = $request->query('session_id');
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $session = $organization->checkoutSessions()
            ->where('stripe_session_id', $sessionId)
            ->first();

        if (! $session instanceof BillingCheckoutSession
            || $session->intent !== CheckoutIntent::SubscriptionStart->value
            || $session->status !== CheckoutSessionStatus::Completed->value
            || $session->funding_choice !== SignupFundingChoice::AutoRecharge->value) {
            return null; // それ以外は従来どおり resolveBillingFeedback に委ねる
        }

        $message = $session->pm_reuse_dispatched_at !== null
            && $this->autoRecharge->isAutoEnablePending($organization)
            ? 'お支払いを受け付けました。オートリチャージは、ご契約のお支払いカードで自動的に有効になります。反映されない場合は、この画面から設定できます。'
            : 'お支払いを受け付けました。オートリチャージの設定はこの画面から確認できます。';

        // reflash() はしない: 成功着地で直前の error flash まで延命すると
        // 「成功と失敗が同時に出る」着地を作るため (feedback は本 info 文言だけを主張する)。
        return $this->canonicalBillingRedirect($request, $organization, ['highlight' => 'auto-recharge'])
            ->with('info', $message);
    }

    /**
     * P9: 決済戻り着地 (`?session_id` / `?portal`) を検証して canonical URL へ 303 + flash に畳む。
     *
     * **one-shot の担保はここ** (bug-hunt F-3-04)。着地 query を URL から落とし、kind は
     * `BillingFeedbackKind::FLASH_KEY` の session flash (次の 1 リクエストのみ生存) で運ぶ。
     * 着地 URL が履歴に残らないため、リロード / 戻る / ブックマークでバナーが復活しない。
     *
     * 契約:
     * - 着地 query を認識したら **feedback の有無に関わらず必ず 303** する (query を残さない)
     * - `session_id` は **org スコープ relation** 経由でのみ引く (他 org の id で成功偽装しない)
     * - **intent !== subscription_start は無言** (P8a の setup_payment_method 行が
     *   同一テーブルに実在するため必須。fail-closed)
     * - Failed / Expired も無言 (状態を主張しない。出口は Plans からの新規 token 発行)
     * - error flash がある着地では feedback を出さず、error だけを次 render へ keep する
     *   (成功と失敗を同時に出さない)
     * - **GET で DB を書かない** (状態遷移は webhook の管轄)
     *
     * 着地判定は **キーの存在**で行う (値の妥当性では判定しない)。`?session_id=` (空) や
     * `?session_id[]=` (配列) でも canonical へ畳む = 「バナーは出ないが query は残る」を作らない。
     */
    private function resolveBillingFeedbackLanding(Request $request, Organization $organization): ?RedirectResponse
    {
        // (1) 着地判定 — 着地 query キーが無ければ素通し (通常 render)。
        //     InputBag::has() を使うのは「キーはあるが値が空/配列」も着地として畳むため。
        $isPortalReturn = $request->query->has('portal');
        $isCheckoutReturn = $request->query->has('session_id');

        if (! $isPortalReturn && ! $isCheckoutReturn) {
            return null;
        }

        // (2) error flash がある着地では成功偽装を抑止する。hop で error を消さないよう keep する
        //     (型に依存しないよう has() で判定する)。
        if ($request->session()->has('error')) {
            $request->session()->keep(['error']);

            return $this->canonicalBillingRedirect($request, $organization);
        }

        // (3) kind 解決 (portal 優先。値は自分が発行した形だけを認める = fail-closed)。
        //     canonical 判定 (キー存在) と kind 判定 (値の妥当性) は分離する。
        $sessionId = $request->query('session_id');
        $kind = match (true) {
            // portal() が渡す return_url は route('billing.index', ['portal' => 1]) の 1 形だけ。
            // ?portal[]=x / ?portal=forged / 値なしは状態を主張しない (畳むだけ)。
            $isPortalReturn => $request->query('portal') === '1' ? BillingFeedbackKind::PortalReturned : null,
            // 空文字 / 配列など string でない値は無言 (fail-closed)。畳むことだけはする。
            is_string($sessionId) && $sessionId !== '' => $this->resolveCheckoutReturnKind($organization, $sessionId),
            default => null,
        };

        // (4) 303 — kind が無くても canonical へ倒す (URL に着地 query を残さない)。
        $redirect = $this->canonicalBillingRedirect($request, $organization);

        return $kind === null ? $redirect : $redirect->with(BillingFeedbackKind::FLASH_KEY, $kind->value);
    }

    /**
     * 自 org の `subscription_start` 行の状態から着地 kind を決める (fail-closed)。
     * 未知 / 他 org / intent 不一致 / Failed / Expired はすべて null (無言)。
     */
    private function resolveCheckoutReturnKind(Organization $organization, string $sessionId): ?BillingFeedbackKind
    {
        $session = $organization->checkoutSessions()
            ->where('stripe_session_id', $sessionId)
            ->first();

        if (! $session instanceof BillingCheckoutSession
            || $session->intent !== CheckoutIntent::SubscriptionStart->value) {
            return null;
        }

        return match ($session->status) {
            CheckoutSessionStatus::Completed->value => BillingFeedbackKind::PurchaseReceived,
            CheckoutSessionStatus::Pending->value => BillingFeedbackKind::PurchaseProcessing,
            default => null,
        };
    }

    /**
     * 着地 hop が積んだ feedback flash を DTO 化する (未知値・欠落は null = fail-closed)。
     * flash なので **次の render では自動的に消える** = one-shot。
     */
    private function resolveFlashedFeedback(Request $request): ?BillingFeedbackDto
    {
        $raw = $request->session()->get(BillingFeedbackKind::FLASH_KEY);
        $kind = is_string($raw) ? BillingFeedbackKind::tryFrom($raw) : null;

        return $kind === null ? null : BillingFeedbackDto::fromKind($kind);
    }

    /**
     * 自前 redirect で /billing の one-shot feedback を出す (query を経由しない)。
     * Inertia の POST 応答は middleware が 302 → 303 に変換する。
     */
    private function redirectWithFeedback(Organization $organization, BillingFeedbackKind $kind): RedirectResponse
    {
        return redirect()->route('billing.index', ['organization' => $organization->slug])
            ->with(BillingFeedbackKind::FLASH_KEY, $kind->value);
    }

    /**
     * 契約成立着地でのみ「元の画面に戻る」導線を出す (1 回限り = リロードで CTA が残らない)。
     *
     * 判定は BillingAccess::state()->grantsAccess() 一本 (subscription 直参照も
     * `?session_id` 依存もしない)。未契約 org では peek すらせず return_to を維持する
     * (契約前に消費すると本来の復帰先が失われる)。
     */
    private function resolveOnboardingContinue(Organization $organization): ?string
    {
        if (! $this->access->state($organization)->grantsAccess()) {
            return null;
        }

        $continue = $this->returnResolver->peekForOrganization($organization);
        if ($continue === null) {
            return null;
        }

        $this->returnResolver->forgetForOrganization($organization);

        return $continue;
    }

    /**
     * Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理)。
     *
     * P8b (bs-11): Portal は Stripe customer + サブスク前提。free personal
     * (canceled サブスク行が残る paid→free を含む = billingState で判定) / 未契約 org は
     * Cashier の assertCustomerExists() 例外 (= 500) に到達させず error flash で back する。
     */
    public function portal(Request $request, Organization $organization, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
    {
        Gate::authorize('manageBilling', $organization);

        if ($this->access->state($organization) === OnboardingBillingState::ActiveFreePlan
            || ! $organization->subscription('default') instanceof Subscription) {
            return back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
        }

        // 戻り着地で `portal_returned` feedback を出すため ?portal=1 を付ける (UI は raw query を見ない)。
        return Inertia::location(
            $subscriptions->createPortalSession(
                $organization,
                route('billing.index', ['organization' => $organization->slug, 'portal' => 1]),
            )->url,
        );
    }
}
