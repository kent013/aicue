<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
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
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TicketLedgerService;
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

    /** 課金ページ (現在プラン / チケット残高 / プラン一覧 / オートリチャージ設定) */
    public function index(Request $request, TicketLedgerService $tickets): Response|RedirectResponse
    {
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

        $plans = Plan::query()->orderBy('sort_order')->get()
            ->map(function (Plan $plan): array {
                $price = $plan->currentPrice(PlanPriceKind::Base);

                return [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'price' => $price === null ? null : [
                        'unitAmount' => $price->amount,
                        'currency' => $price->currency,
                    ],
                ];
            })
            ->values()
            ->all();

        $canManageBilling = $user->can('manageBilling', $organization);

        return Inertia::render('Billing/Index', [
            'plans' => $plans,
            'currentPlanCode' => $organization->plan_code,
            'ticketBalance' => $tickets->balance($organization)->totalAvailable(),
            'canManageBilling' => $canManageBilling,
            'continueUrl' => $this->resolveOnboardingContinue($organization),
            // P8a: オートリチャージ設定カード。subscription 有無に依存せず常に非 null
            // (無料パーソナル含む全プランが対象。**既定は enabled=false の opt-in**)。
            'autoRecharge' => $this->autoRecharge->settingsFor($organization, $canManageBilling)->toArray(),
            // カード登録開始 POST の attempt_token (render 単位。setup は課金を伴わないため
            // 購入導線のようなサーバ側安定化は不要 — 同一 token の再送は台帳 unique で冪等)。
            'autoRechargeSetupToken' => strtolower((string) Str::ulid()),
        ]);
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

    /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
    public function portal(Request $request, SubscriptionService $subscriptions): SymfonyResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        return Inertia::location($subscriptions->createPortalSession($organization, route('billing.index'))->url);
    }
}
