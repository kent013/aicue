<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\DataTransferObjects\Billing\BillingDashboardDto;
use App\DataTransferObjects\Billing\BillingPlansPageDto;
use App\DataTransferObjects\Billing\QuotaLimitsDto;
use App\DataTransferObjects\Marketing\PricingPlanDto;
use App\Enums\Billing\OnboardingBillingState;
use App\Enums\Billing\PlanPriceKind;
use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Exceptions\Billing\CheckoutInProgressException;
use App\Exceptions\Billing\StripePriceNotSyncedException;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingCheckoutRequest;
use App\Http\Requests\Billing\StartAutoRechargeSetupRequest;
use App\Http\Requests\Billing\UpdateAutoRechargeRequest;
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
use App\Services\Marketing\PricingService;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Services\Onboarding\OnboardingReturnResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Webmozart\Assert\Assert;

/**
 * 課金画面 (current org スコープ)。
 *
 * - プラン変更は Stripe Checkout / Customer Portal 経由のみ (アプリは plan_code を
 *   直接書かない。organizations.plan_code は webhook で同期される)
 * - 閲覧は組織メンバー全員、Checkout / Portal / オートリチャージ設定は
 *   manageBilling (owner / admin) のみ
 */
class BillingController extends Controller
{
    use ResolvesCurrentOrganization;

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
     */
    public function index(
        Request $request,
        TicketLedgerService $tickets,
        QuotaService $quota,
        PricingService $pricing,
    ): Response|RedirectResponse {
        $organization = $this->resolveCurrentOrganization($request);
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

        $canManageBilling = $user->can('manageBilling', $organization);
        $subscription = $organization->subscription('default');

        $dto = new BillingDashboardDto(
            plan: $this->resolveCurrentPlan($organization, $pricing),
            billingState: $this->access->state($organization),
            currentPeriodEnd: $subscription instanceof Subscription
                ? $subscription->current_period_end?->toIso8601String()
                : null,
            balance: $tickets->balance($organization),
            quotas: QuotaLimitsDto::fromLimits($quota->limits($organization)),
            canManageBilling: $canManageBilling,
            continueUrl: $this->resolveOnboardingContinue($organization),
            // P8a: オートリチャージ設定カード。subscription 有無に依存せず常に非 null
            // (無料パーソナル含む全プランが対象。**既定は enabled=false の opt-in**)。
            autoRecharge: $this->autoRecharge->settingsFor($organization, $canManageBilling),
            // カード登録開始 POST の attempt_token (render 単位。setup は課金を伴わないため
            // 購入導線のようなサーバ側安定化は不要 — 同一 token の再送は台帳 unique で冪等)。
            autoRechargeSetupToken: strtolower((string) Str::ulid()),
        );

        return Inertia::render('Billing/Index', ['page' => $dto->toArray()]);
    }

    /**
     * プラン比較ページ (P8b / bs-6)。閲覧は組織メンバー全員、変更は manageBilling のみ。
     *
     * プラン台帳 → DTO の mapper は公開料金表と共有する (新 DTO を発明しない)。
     */
    public function plans(Request $request, PricingService $pricing): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $dto = new BillingPlansPageDto(
            plans: $pricing->listPublicPlans(),
            currentPlanCode: $this->resolveCurrentPlanCode($organization),
            billingState: $this->access->state($organization),
            canManage: $user->can('manageBilling', $organization),
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
     * Stripe Checkout を開始し、Checkout URL へリダイレクトする
     * (戻り型に RedirectResponse を含むのは price 不在 / 開始不可時の back() 分岐のため)
     */
    public function checkout(BillingCheckoutRequest $request, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        $planCode = $request->validated('plan_code');
        Assert::string($planCode);
        $plan = Plan::query()->where('code', $planCode)->firstOrFail();

        $price = $plan->currentPrice(PlanPriceKind::Base);
        if ($price === null) {
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        }

        try {
            $redirect = $subscriptions->startCheckout(
                $organization,
                $price,
                route('billing.index'),
                route('billing.index'),
            );
        } catch (StripePriceNotSyncedException) {
            // production の sync 漏れ。500 にせず現行と同一文言で差し戻す
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        } catch (InvalidArgumentException $e) {
            // 既に有効なサブスクリプションがある (service 層の fail-closed ガード)
            return back()->with('error', $e->getMessage());
        }

        // 契約開始が成立したのでプラン意図を消費する (checkout URL 取得後・遷移前)。
        // price 不在 / 開始不可の back() 経路では forget しない = 意図を維持して再試行できる。
        $this->intendedPlanResolver->forgetForOrganization($organization);

        // 外部 URL への遷移は Inertia::location (full page redirect)
        return Inertia::location($redirect->url);
    }

    /**
     * P8a: オートリチャージ設定の更新 (有効化 / 停止 / 閾値・上限の変更)。
     *
     * 有効化は Service 側で fail-closed (default PM 必須 + 同意必須)。停止は同一 lock 下で
     * pending attempt をキャンセルする (停止後課金の禁止)。
     */
    public function updateAutoRecharge(UpdateAutoRechargeRequest $request): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
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
    public function startAutoRechargeSetup(StartAutoRechargeSetupRequest $request): SymfonyResponse|RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $token = $request->validated('attempt_token');
        Assert::stringNotEmpty($token);

        $result = $this->autoRecharge->startSetupCheckout(
            $organization,
            $user,
            route('billing.index').'?setup_session_id={CHECKOUT_SESSION_ID}',
            route('billing.index'),
            $token,
        );

        if ($result['url'] === null) {
            return back()->with('warning', '既に進行中のカード登録があります。');
        }

        return Inertia::location($result['url']);
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
            return redirect()->route('billing.index', [], 303);
        }

        $message = $session->status === CheckoutSessionStatus::Completed->value
            ? 'お支払いカードを登録しました。'
            : 'お支払いカードの登録を受け付けました。反映まで少しお待ちください。';

        return redirect()->route('billing.index', [], 303)->with('success', $message);
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
    public function portal(Request $request, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        if ($this->access->state($organization) === OnboardingBillingState::ActiveFreePlan
            || ! $organization->subscription('default') instanceof Subscription) {
            return back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
        }

        return Inertia::location($subscriptions->createPortalSession($organization, route('billing.index'))->url);
    }
}
