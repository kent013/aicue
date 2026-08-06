<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Auth\ConfirmRecentAuthController;
use App\Http\Controllers\Auth\SessionStatusController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\TicketPurchaseController;
use App\Http\Controllers\Capture\CaptureManualController;
use App\Http\Controllers\Capture\CaptureTakeController;
use App\Http\Controllers\Capture\TakeUploadUrlController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebugLoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Marketing\PricingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Onboarding\ActivatePersonalController;
use App\Http\Controllers\Onboarding\BillingRequiredController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Organizations\InvitationAcceptanceController;
use App\Http\Controllers\Organizations\OrganizationApiKeyController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\OrganizationInvitationController;
use App\Http\Controllers\Organizations\OrganizationMemberController;
use App\Http\Controllers\Organizations\OrganizationOauthSessionController;
use App\Http\Controllers\Organizations\OrganizationOnboardingController;
use App\Http\Controllers\Organizations\OrganizationOwnershipController;
use App\Http\Controllers\Organizations\OrganizationSwitchController;
use App\Http\Controllers\Projects\CategoryController;
use App\Http\Controllers\Projects\ItemController;
use App\Http\Controllers\Projects\ManualAnalysisController;
use App\Http\Controllers\Projects\ManualDownloadController;
use App\Http\Controllers\Projects\ManualRenderController;
use App\Http\Controllers\Projects\ManualScenarioController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectMemberController;
use App\Http\Controllers\Projects\SourceDocumentController;
use App\Http\Controllers\Projects\VideoManualController;
use App\Http\Controllers\Seo\AiTxtController;
use App\Http\Controllers\Seo\LlmsTxtController;
use App\Http\Controllers\Seo\RobotsController;
use App\Http\Controllers\Seo\SitemapController;
use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\PasswordSetupController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Webhooks\SesNotificationController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LocalOnly;
use App\Http\Middleware\NoIndex;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Response;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// トップページ (SEO full 分類の参考実装。SeoManager へのメタ供給は HomeController)
Route::get('/', HomeController::class)->name('home');

/*
|--------------------------------------------------------------------------
| 機械可読 SEO リソース (stateless)
|--------------------------------------------------------------------------
| web group の cookie/session/CSRF/Inertia を外し Set-Cookie を一切出さない
| (外部 fetcher が cookie jar を膨らませない・冪等)。SubstituteBindings /
| SecurityHeaders は残す (cookie を出さず、baseline セキュリティヘッダは有益)。
| 除外対象クラスが将来 rename されると無効化されるため、最終防波堤は
| StatelessHeadersTest の Set-Cookie 不在検証。
*/
Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
    HandleInertiaRequests::class,
])->group(function (): void {
    Route::get('/robots.txt', RobotsController::class)->name('seo.robots');
    Route::get('/sitemap.xml', SitemapController::class)->name('seo.sitemap');
    Route::get('/llms.txt', LlmsTxtController::class)->name('seo.llms');
    Route::get('/ai.txt', AiTxtController::class)->name('seo.ai');
});

/*
|--------------------------------------------------------------------------
| Webhook 受信 (無認証 + 署名検証 + CSRF 外)
|--------------------------------------------------------------------------
| SES/SNS 通知 (バウンス/苦情)。SNS 署名検証 (sns.signature alias =
| VerifySnsSignature) が唯一の防御線 (CSRF 除外は bootstrap/app.php の
| validateCsrfTokens except 'ses/*')。session/cookie/Inertia は不要のため外し、
| Set-Cookie を出さない。Stripe webhook (POST /stripe/webhook) は Cashier が
| 自動登録する (署名検証は Cashier middleware)。
*/
Route::post('/ses/notification', SesNotificationController::class)
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        HandleInertiaRequests::class,
    ])
    // throttle は署名検証より**先**に走る (priority list により ThrottleRequests が先行)。
    // これは署名検証コスト (SNS は証明書取得を伴う) の上限であり、正当通知を守る全体天井ではない。
    // 実効順は tests/Feature/Security/AuthThrottleCoverageTest が固定する。
    ->middleware(['throttle:webhook-ses', 'sns.signature'])
    ->name('webhooks.ses');

/*
|--------------------------------------------------------------------------
| 公開問い合わせフォーム (auth 不要)
|--------------------------------------------------------------------------
| POST は throttle:inquiry (IP 単独 + IP+email の 2 系統) で amplification を抑制。
| 送信完了は専用 thanks ページ (フォームを残さない)。
*/
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:inquiry')
    ->name('contact.store');
Route::get('/contact/thanks', [ContactController::class, 'thanks'])->name('contact.thanks');

/*
|--------------------------------------------------------------------------
| 公開マーケ / 法的ページ (auth 不要)
|--------------------------------------------------------------------------
| /pricing は公開料金表 (SEO full 分類。PricingController が SeoManager にメタを供給)。
| /terms /privacy /commerce-disclosure は Route::view の薄い Blade スタブ。文面が
| 未確定のプレースホルダのため noindex (blade の <meta robots> + NoIndex middleware の
| X-Robots-Tag で二重防御)。正式文面へ差し替えて公開する際に noindex を外すこと。
*/
Route::get('/pricing', PricingController::class)->name('pricing');
Route::middleware(NoIndex::class)->group(function (): void {
    Route::view('/terms', 'legal.terms')->name('legal.terms');
    Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
    Route::view('/commerce-disclosure', 'legal.commerce-disclosure')->name('legal.commerce-disclosure');
});

/*
|--------------------------------------------------------------------------
| セッション有効性の軽量プローブ (bfcache 秘匿・再検証)
|--------------------------------------------------------------------------
| auth グループの **外**に置く。未認証でも 200 + { authenticated: false } を返し、
| クライアント guard (resources/js/lib/bfcache-guard.ts) が「セッション無効」と
| 「endpoint 不在 / 通信障害」を明示 boolean で区別できるようにする。
| 2FA 強制ゲートは RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES で
| 明示的に免除している (免除しないと 2FA 強制中に秘匿が解除できず reload ループになる)。
*/
Route::get('/session/status', SessionStatusController::class)->name('session.status');

/*
|--------------------------------------------------------------------------
| SSO (Socialite)
|--------------------------------------------------------------------------
| 開始導線は GET の anchor リンク (form POST にしない。CSP form-action が
| リダイレクト先 IdP に適用されるため)。
*/
Route::get('/auth/{provider}/redirect/{intent}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
// callback は SocialAuthController::callback() 内の Socialite::driver()->user() で
// **IdP への外向き HTTP** が起きる (未認証で外部へ HTTP を発射できる唯一の経路)。
// 未認証面のため named limiter で IP レーンを明示する (閾値は passkeys guest と同値)。
// redirect 側は外向き通信をしないため throttle を貼らず、exemption
// (AuthFlowInitiationWithoutOutboundCall) として理由を inventory に残す。
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:social-callback')
    ->name('social.callback');

/*
|--------------------------------------------------------------------------
| 認証済み
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    // ログイン直後の着地点 (課金ゲート外のまま。未契約でも状況把握と復帰導線を提供)
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
    | recent-auth (generic step-up 再認証)。機微操作 route の `recent-auth` middleware が
    | 鮮度切れ時にここへ誘導する。satisfier は password 再入力と再SSO
    | (/auth/{provider}/redirect/step-up)。allowlist は RecentAuthRouteTest が CI 固定。
    */
    Route::get('/recent-auth/confirm', [ConfirmRecentAuthController::class, 'show'])
        ->name('recent-auth.confirm');
    // クライアント主導 step-up の precheck (XHR, no-store)
    Route::get('/recent-auth/status', [ConfirmRecentAuthController::class, 'status'])
        ->name('recent-auth.status');
    Route::post('/recent-auth/password', [ConfirmRecentAuthController::class, 'confirmPassword'])
        ->middleware('throttle:6,1')
        ->name('recent-auth.password');

    Route::get('/settings', [ProfileController::class, 'index'])->name('settings');

    // パスワード**初回設定** (password 未設定ユーザー専用)。認証手段を増やす操作のため
    // step-up (recent-auth) 必須。変更 (current_password 必須) は Fortify の PUT /user/password。
    // EnsureLoginMethodRemains は付けない (手段を減らす操作の関門であり方向が逆)。
    Route::post('/settings/password', [PasswordSetupController::class, 'store'])
        ->middleware(['recent-auth', 'throttle:6,1'])
        ->name('settings.password.store');

    // 2FA / ソーシャル連携 / パスキーの管理面 (passkey 一覧の組み立てに DI が要るため Controller)
    Route::get('/settings/security', SecurityController::class)->name('settings.security');

    // アカウント削除は step-up (recent-auth) 必須
    Route::delete('/settings/account', [AccountController::class, 'destroy'])
        ->middleware('recent-auth')
        ->name('settings.account.destroy');

    /*
    | 組織。`{organization}` / `{organization:slug}` は MembershipScopedOrganizationBinder
    | (AppServiceProvider で Route::bind 登録) が「認証済みユーザーが所属する組織」に
    | スコープして解決する。非メンバー・不在 slug/id は等しく 404 (テナント存在秘匿)。
    | same-org の権限不足 403 は従来どおり Policy の責務。
    */
    Route::get('/organizations/create', [OrganizationController::class, 'create'])
        ->name('organizations.create');
    // 未認証時は /email/verify への沈黙 302 ではなく back + error flash で戻す (verified.or-back)。
    // group の 'verified' を route 単位で外し (将来の group middleware 追加で取りこぼさないため
    // group 外出しではなく withoutMiddleware で override)、verified.or-back を個別付与する。
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->withoutMiddleware('verified')
        ->middleware('verified.or-back:organization-store')
        ->name('organizations.store');
    // 切替は field 無指定 (= id) binding。非所属組織は binder が 404 に倒す
    Route::post('/organizations/{organization}/switch', [OrganizationSwitchController::class, 'store'])
        ->name('organizations.switch');
    Route::get('/organizations/{organization:slug}/settings', [OrganizationController::class, 'settings'])
        ->name('organizations.settings');
    Route::patch('/organizations/{organization:slug}', [OrganizationController::class, 'update'])
        ->name('organizations.update');
    // 招待送信も未認証時は back + error flash で戻す (verified.or-back)。organizations.store と
    // 同様に group の 'verified' を route 単位で外し verified.or-back を個別付与する。
    Route::post('/organizations/{organization:slug}/invitations', [OrganizationInvitationController::class, 'store'])
        ->withoutMiddleware('verified')
        ->middleware('verified.or-back:invite')
        ->name('organizations.invitations.store');
    // 招待取り消し (論理失効)。{invitation} は scopeBindings で $organization->invitations()
    // 経由に解決され、組織を跨ぐ取り消しは認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
    Route::delete('/organizations/{organization:slug}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])
        ->scopeBindings()
        ->name('organizations.invitations.revoke');
    /*
    | {user} は scopeBindings で $organization->users() 経由に解決する。
    | 非メンバー / 不在 id は **binding 段で等しく 404** になり、recent-auth (302) を含む
    | binding 後のどの短絡 middleware よりも前に閉じる (audit-cycle-2 High-1 横断)。
    | implicit binding のままだと「不在 = binding 404 / 実在の非メンバー = 後段短絡の 302」と
    | 分岐し、users.id の存在オラクルになっていた。
    | controller の inline guard (resolveOrganizationMember) は二重防御として残す。
    | 親 {organization:slug} は MembershipScopedOrganizationBinder が引き続き担当する
    | (scopeBindings は子解決のみに作用)。
    */
    Route::scopeBindings()->group(function (): void {
        Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
            ->name('organizations.members.update');
        Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
            ->name('organizations.members.destroy');
        // メンバーの 2FA リセット (ロックアウト救済。Owner/Admin + step-up + 理由必須)
        Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
            ->middleware('recent-auth')
            ->name('organizations.members.two-factor.reset');
    });
    // 組織の 2FA 必須方針トグル (Owner 専権 + step-up)
    Route::patch('/organizations/{organization:slug}/two-factor-requirement', [OrganizationController::class, 'updateTwoFactorRequirement'])
        ->middleware('recent-auth')
        ->name('organizations.two-factor-requirement.update');
    // オーナー移譲は step-up (recent-auth) 必須
    Route::post('/organizations/{organization:slug}/transfer-ownership', [OrganizationOwnershipController::class, 'store'])
        ->middleware('recent-auth')
        ->name('organizations.transfer-ownership');

    /*
    | 管理メニュー (doc/04 §4.2 管理者専用)。ユーザー管理は org メンバー管理の専用画面
    | (書き込みは既存 organizations.* endpoint)。/admin/* は Filament panel が占有するため /manage/*。
    | org 管理系として課金ゲート外 (未契約でもメンバー整理可能 = organizations.members.* と整合)。
    | /manage/ 配下の route は auth+verified 必須 (ManageRouteAuthGuardTest が deny-by-default で強制)。
    */
    Route::get('/manage/users', [UserManagementController::class, 'index'])
        ->name('manage.users.index');

    /*
    | API キー (org スコープ。manageApiKeys = owner / admin)。
    | 平文キーは発行直後の flash 経由 1 度きり表示。{apiKey} は scopeBindings で
    | $organization->apiKeys() 経由の解決 (不整合は認可より前に 404。
    | NestedRouteIdorDefenseTest 登録済み)。
    */
    // 一覧 (専用画面) は閲覧のみのため recent-auth 不要
    Route::get('/organizations/{organization:slug}/api-keys', [OrganizationApiKeyController::class, 'index'])
        ->name('organizations.api-keys.index');
    // 発行 / 失効はいずれも step-up (recent-auth) 必須
    Route::post('/organizations/{organization:slug}/api-keys', [OrganizationApiKeyController::class, 'store'])
        ->middleware('recent-auth')
        ->name('organizations.api-keys.store');

    /*
    | OAuth セッション (CLI/MCP 接続) の組織管理経路。境界は API キー管理と同一
    | (OauthSessionPolicy::manageForOrganization = owner / admin または直接付与メンバー)。
    | 一覧は接続セッションタブ (ApiKeys/Sessions)。sessions の GET は revoke ({oauthSession}) の
    | 前に定義し、静的セグメント 'sessions' が wildcard に食われないようにする。
    */
    Route::get('/organizations/{organization:slug}/api-keys/sessions', [OrganizationOauthSessionController::class, 'index'])
        ->name('organizations.api-keys.sessions.index');
    Route::scopeBindings()->group(function (): void {
        Route::delete('/organizations/{organization:slug}/api-keys/sessions/{oauthSession}', [OrganizationOauthSessionController::class, 'destroy'])
            ->middleware('recent-auth')
            ->name('organizations.api-keys.sessions.revoke');
    });

    // {apiKey} wildcard の revoke は静的セグメント (sessions) の後に定義する。
    // {apiKey} は scopeBindings で $organization->apiKeys() 経由の解決 (不整合は認可より前に 404。
    // NestedRouteIdorDefenseTest 登録済み)。
    Route::scopeBindings()->group(function (): void {
        Route::delete('/organizations/{organization:slug}/api-keys/{apiKey}', [OrganizationApiKeyController::class, 'destroy'])
            ->middleware('recent-auth')
            ->name('organizations.api-keys.revoke');
    });

    /*
    | MCP / CLI 導入オンボーディング (組織メンバーなら閲覧可)。endpoint / 設定 JSON は
    | SnippetBuilder が config('app.url') / config('template.slug') から生成する。
    */
    Route::get('/organizations/{organization:slug}/onboarding/mcp', [OrganizationOnboardingController::class, 'mcp'])
        ->name('organizations.onboarding.mcp');
    Route::get('/organizations/{organization:slug}/onboarding/cli', [OrganizationOnboardingController::class, 'cli'])
        ->name('organizations.onboarding.cli');

    /*
    | 課金 (current org スコープ)。新規契約は Stripe Checkout、契約中プランの変更は
    | in-app swap (billing.plan.change)、解約・支払い方法は Customer Portal。
    | Stripe webhook ルート (POST /stripe/webhook) は Cashier が自動登録する
    | (CSRF 除外は bootstrap/app.php の validateCsrfTokens except 'stripe/*')。
    | billing / webhook / 組織管理系は課金ゲート (require-active-subscription) の
    | allowlist (gate group に含めない)。遮断対象 (有償プラン契約中の支払い不健全) でも
    | checkout / Customer Portal に到達できることを保証する。
    */
    Route::get('/billing', [BillingController::class, 'index'])
        ->name('billing.index');
    // P8b (bs-6): プラン比較。閲覧は組織メンバー全員 (変更操作のみ manageBilling)。
    Route::get('/billing/plans', [BillingController::class, 'plans'])
        ->name('billing.plans');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])
        ->name('billing.checkout');
    // F-3-01: 契約中プランの変更 (in-app swap)。有効な subscription を**持つ**組織の経路で、
    // 持たない組織の billing.checkout と排他。Portal の subscription_update は無効のまま
    // (プラン変更はアプリが所有する = PortalConfigurationSpec の宣言どおり)。
    // 課金ゲート allowlist に置く理由: billing.* と同じく「支払い状態の是正」に到達させるため。
    Route::post('/billing/plan', [BillingController::class, 'changePlan'])
        ->name('billing.plan.change');
    Route::post('/billing/portal', [BillingController::class, 'portal'])
        ->name('billing.portal');
    // P9: 請求先連絡先 (メール / 宛名)。current org スコープ (route parameter なし)。
    // 認可は Controller 冒頭の Gate::authorize('manageBilling')。
    Route::patch('/billing/contact', [BillingController::class, 'updateBillingContact'])
        ->name('billing.contact.update');

    /*
    | オートリチャージ (裏チャージ。P8a)。**opt-in・既定 off**。
    | current org スコープ (route parameter なし) で billing.* と同一の解決規約。
    | 課金ゲート allowlist (require-active-subscription group の外) — 支払い不健全で
    | 遮断中でも停止・カード更新に到達できることを保証する。
    | 認可は Controller 冒頭の Gate::authorize('manageBilling')。
    */
    Route::post('/billing/auto-recharge', [BillingController::class, 'updateAutoRecharge'])
        ->name('billing.auto-recharge.update');
    Route::post('/billing/auto-recharge/setup', [BillingController::class, 'startAutoRechargeSetup'])
        ->name('billing.auto-recharge.setup');

    /*
    | 課金オンボーディング (current org スコープ)。登録直後の Plan 選択 +
    | 未契約 manageBilling なし member 向け説明画面。billing.* と同じく課金ゲート
    | (require-active-subscription) の外に置く = 未契約組織が導線に到達できることを保証する
    | (ゲート内に入れると「契約するための画面が契約してないと見られない」詰みになる)。
    | 組織解決は billing.* と同一 (route parameter なし = URL の org ≠ current org が
    | 構造的に発生しない)。認可は Controller 冒頭の Gate::authorize が担う。
    | MCP/CLI 導入ガイド (organizations.onboarding.{mcp,cli}) とは別責務・別 name。
    */
    Route::get('/onboarding/checkout', [OnboardingController::class, 'show'])
        ->name('onboarding.checkout');
    // Personal (free) の有効化 (Stripe checkout を通らない。自己申告チェック必須)
    Route::post('/onboarding/activate-personal', ActivatePersonalController::class)
        ->middleware('throttle:10,1')
        ->name('onboarding.activate-personal');
    Route::get('/billing-required', [BillingRequiredController::class, 'show'])
        ->name('onboarding.billing-required');

    /*
    | チケットスポット購入 (current org スコープ)。billing.* と同じく課金ゲート
    | (require-active-subscription) の対象外 = 支払い不健全で遮断中の組織でも購入できる
    | (無料枠は free_plan_code='personal' = ActiveFreePlan として BillingAccess が許可する)。
    | 閲覧は組織メンバー全員、Checkout 開始は manageBilling (owner / admin) のみ。
    */
    Route::get('/purchase-tickets', [TicketPurchaseController::class, 'show'])
        ->name('billing.tickets.show');
    Route::post('/purchase-tickets/checkout', [TicketPurchaseController::class, 'checkout'])
        ->name('billing.tickets.checkout');

    /*
    | 通知センター (課金ゲート外 = サブスク失効中でもベルは機能させる。残高/課金系通知の
    | 受け皿として必要)。{notification} は implicit binding を使わず controller が
    | $request->user()->notifications() 経由で解決する (cross-user は構造的に 404 =
    | 存在オラクル封じ。1 param のため NestedRouteIdorDefenseTest の inventory 対象外)。
    | 型制約は RouteBindingTypes に集約 ({notification} は UUID 分類 = Route::pattern で
    | 不正形式 id を route 不一致 = 404 に落とす。pgsql uuid 比較の 22P02 防止)。
    | open は POST + 303 (GET にしない = prefetch による意図しない既読化防止)。
    */
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');
    Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])
        ->name('notifications.open');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');

    /*
    | 組織配下の業務 route (課金ゲート対象)。BillingAccess::state() が許可しない組織は
    | onboarding へ遮断される (manageBilling 保持者は onboarding.checkout、非保持者は
    | onboarding.billing-required)。middleware は error flash を積まない = 遮断理由は
    | 着地ページが持つ。JSON/XHR は 402。無料枠は free_plan_code='personal' の
    | 明示申告 (ActiveFreePlan) で表現し、plan_code は判定に使わない。
    | billing / purchase-tickets / notifications / onboarding は gate group 外の構造的 allowlist。
    | 新しい業務ドメインの route はこの group 内に追加すること。
    */
    Route::middleware(['require-active-subscription', 'project.in-current-org'])->group(function (): void {
        /*
        | プロジェクト (current org スコープ。URL に org / team セグメントを含めない =
        | Default Team パターンのルーティング仕様)。
        | {project} の URL 整合 guard ({project} ∈ current org) は 2 層:
        | (1) project.in-current-org middleware — cross-org を 404 に落とす (存在オラクル防止)。
        |     **実行位置は宣言順ではなく bootstrap/app.php の priority list が正本**で、
        |     SubstituteBindings の**直後** = 課金ゲート 302・verified 302・2FA 強制 302・
        |     Inertia version mismatch 409・FormRequest の DB ルールより前に走る。
        |     間に 404 以外で短絡する middleware が入ると「他組織に実在 = その短絡の応答 /
        |     不在 = 404」の存在オラクルが復活する (audit-cycle-2 High-1)。
        |     {project} を持たない route では no-op のため group 一括付与。
        |     網羅性は ProjectRouteCurrentOrgGuardTest、順序は TenantBoundaryOrderingTest が固定
        | (2) controller の inline guard (resolveOrganizationProject) — 二重防御
        */
        Route::get('/projects', [ProjectController::class, 'index'])
            ->name('projects.index');
        Route::get('/projects/create', [ProjectController::class, 'create'])
            ->name('projects.create');
        Route::post('/projects', [ProjectController::class, 'store'])
            ->name('projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->name('projects.show');
        Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])
            ->name('projects.edit');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])
            ->name('projects.update');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
            ->name('projects.destroy');

        // Item (Project 配下サンプルリソース): nested route。一覧は projects.show が担う。
        // {item} は scopeBindings で $project->items() 経由の解決 (子→親不整合は認可より前に 404。
        // NestedRouteIdorDefenseTest 登録済み)
        Route::post('/projects/{project}/items', [ItemController::class, 'store'])
            ->name('projects.items.store');
        Route::scopeBindings()->group(function (): void {
            Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
                ->name('projects.items.update');
            Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
                ->name('projects.items.destroy');
        });

        // Category (Project 配下の動画マニュアル分類・編集者のみ)。一覧は projects.show が内包する。
        // reorder は {category} を取らない ({project} のみ = 1 param) ため IDOR inventory 対象外。
        // {category} は scopeBindings で $project->categories() 経由の解決
        // (子→親不整合は認可より前に 404。NestedRouteIdorDefenseTest 登録済み)
        // カテゴリ管理画面 (管理メニュー。一覧表示のみ。write は下記既存 route)
        Route::get('/projects/{project}/categories', [CategoryController::class, 'index'])
            ->name('projects.categories.index');
        Route::post('/projects/{project}/categories', [CategoryController::class, 'store'])
            ->name('projects.categories.store');
        Route::patch('/projects/{project}/categories/reorder', [CategoryController::class, 'reorder'])
            ->name('projects.categories.reorder');
        Route::scopeBindings()->group(function (): void {
            Route::patch('/projects/{project}/categories/{category}', [CategoryController::class, 'update'])
                ->name('projects.categories.update');
            Route::delete('/projects/{project}/categories/{category}', [CategoryController::class, 'destroy'])
                ->name('projects.categories.destroy');
        });

        // VideoManual (Project 配下の動画マニュアル)。一覧は projects.show が内包する。
        // {manual} は scopeBindings で $project->manuals() 経由の解決
        // (子→親不整合は認可より前に 404。NestedRouteIdorDefenseTest 登録済み)
        Route::get('/projects/{project}/manuals/create', [VideoManualController::class, 'create'])
            ->name('projects.manuals.create');
        Route::post('/projects/{project}/manuals', [VideoManualController::class, 'store'])
            ->name('projects.manuals.store');
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
                ->name('projects.manuals.show');
            Route::get('/projects/{project}/manuals/{manual}/edit', [VideoManualController::class, 'edit'])
                ->name('projects.manuals.edit');
            Route::patch('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'update'])
                ->name('projects.manuals.update');
            // シナリオ document 一括保存 (doc/09 §9.4 / doc/10 §10.3)。同一オリジン XHR (JSON 応答)。
            // {manual} ∈ {project} は scopeBindings、{project} ∈ current org は
            // project.in-current-org middleware + controller inline guard の 2 層 (既存 group が担保)
            Route::put('/projects/{project}/manuals/{manual}/scenario', [ManualScenarioController::class, 'update'])
                ->name('projects.manuals.scenario.update');
            // SOP アップロード (追記型 immutable。差し替え = 新規行。doc/10 §10.3)
            Route::post('/projects/{project}/manuals/{manual}/source-documents', [SourceDocumentController::class, 'store'])
                ->name('projects.manuals.source-documents.store');
            // AI 解析トリガー (残高事前チェック→job 投入。同一オリジン XHR/JSON。doc/10 §10.3, §10.8-8)
            Route::post('/projects/{project}/manuals/{manual}/analyze', [ManualAnalysisController::class, 'store'])
                ->name('projects.manuals.analyze');
            // job 状態ポーリング ({analysisJob} は $manual->analysisJobs() 経由 = cross-manual 404)
            Route::get('/projects/{project}/manuals/{manual}/jobs/{analysisJob}', [ManualAnalysisController::class, 'show'])
                ->name('projects.manuals.jobs.show');
            // レンダ / プレビュー (チケット消費は render のみ。同一オリジン XHR/JSON。§10.3, §10.8)
            Route::post('/projects/{project}/manuals/{manual}/render', [ManualRenderController::class, 'store'])
                ->middleware('throttle:render-trigger')
                ->name('projects.manuals.render');
            Route::post('/projects/{project}/manuals/{manual}/preview', [ManualRenderController::class, 'preview'])
                ->middleware('throttle:render-trigger')
                ->name('projects.manuals.preview');
            // レンダ job ポーリング (進捗のみ。成果物 URL は含めない = 権限分離。
            // {renderJob} は $manual->renderJobs() 経由 = cross-manual 404)
            Route::get('/projects/{project}/manuals/{manual}/render-jobs/{renderJob}', [ManualRenderController::class, 'show'])
                ->name('projects.manuals.render-jobs.show');
            // preview 再生 (render ability。最新 succeeded preview のみ 302)
            Route::get('/projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback', [ManualRenderController::class, 'playback'])
                ->name('projects.manuals.render-jobs.playback');
            // 完成 mp4 ダウンロード (download ability。published + 最新 succeeded render のみ)
            Route::get('/projects/{project}/manuals/{manual}/download', [ManualDownloadController::class, 'show'])
                ->name('projects.manuals.download');
            Route::delete('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'destroy'])
                ->name('projects.manuals.destroy');
            // マニュアル複製 (別名保存の実体。保存済み cuts を雛形に新タイトル・カテゴリで新規作成)。
            // {manual} は $project->manuals() 経由 (scopeBindings) = cross-manual/cross-project は 404。
            Route::post('/projects/{project}/manuals/{manual}/duplicate', [VideoManualController::class, 'duplicate'])
                ->name('projects.manuals.duplicate');
        });

        /*
        | プロジェクトメンバー管理 (追加は payload の user_id、削除は URL の {user})。
        | destroy の {user} は **implicit binding を使わない** (controller が string で受け、
        | 現在組織の users() から手動解決する)。{user} の意味的な親は {project} ではなく
        | 現在組織であり、scopeBindings が要求する Project::users() は存在しないため。
        | binding 段で解決されない = 不在 id も実在の非メンバーもまったく同じ経路を辿る
        | (= 分岐しない = 存在オラクル不成立)。この性質は TenantBoundaryOrderingTest の
        | 検査 3a が「binding 段で解決されないこと」として機械検証する。
        */
        Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])
            ->name('projects.members.store');
        Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])
            ->name('projects.members.destroy');
    });

    /*
    | 撮影 PWA (/app/*。doc/10 §10.8-3 ルート分離)。web ガード + セッション + CSRF。
    | データ API も /api/v1 (機械用) に混ぜずここに置く。GET は Inertia、書き込みは XHR JSON。
    | {project} guard は業務 group と同じ 2 層 (middleware + inline)。
    | {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は scopeBindings
    | (Project::manuals / VideoManual::cuts / Cut::takes。relation 名は route param の
    |  推論名と一致させる既存規約 = VideoManual.php の manuals() 命名理由と同じ)。
    | ★二重防御: scopeBindings の relation 推論に単独依存しない。全書き込み Service は
    | tx 内で $project->manuals()->…->cuts()->…->takes() の連鎖再解決 (firstOrFail) を必須とし、
    | 推論が外れても cross-parent は 404 に落ちる。挙動担保は各エンドポイントの
    | cross-org/project/manual/cut 404 Feature テスト。
    */
    Route::middleware(['require-active-subscription', 'project.in-current-org'])
        ->prefix('app')->as('capture.')->group(function (): void {
            // PWA エントリ (manifest start_url)。current org の先頭 project へ redirect
            Route::get('/', [CaptureManualController::class, 'home'])->name('home');
            // CSRF cookie 再発行 (419 リトライ用の軽量 GET。web group を通るだけで
            // XSRF-TOKEN cookie が更新される。204 = 仕様固定 endpoint、body なし)
            Route::get('/csrf-cookie', fn (): Response => response()->noContent())
                ->name('csrf-cookie');
            Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])
                ->name('manuals.index');
            Route::scopeBindings()->group(function (): void {
                Route::get('/projects/{project}/manuals/{manual}', [CaptureManualController::class, 'show'])
                    ->name('manuals.show');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url', [TakeUploadUrlController::class, 'store'])
                    ->name('takes.upload-url');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes', [CaptureTakeController::class, 'store'])
                    ->name('takes.store');
                Route::patch('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}', [CaptureTakeController::class, 'update'])
                    ->name('takes.update');
                Route::delete('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}', [CaptureTakeController::class, 'destroy'])
                    ->name('takes.destroy');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt', [CaptureTakeController::class, 'adopt'])
                    ->name('takes.adopt');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded', [CaptureTakeController::class, 'markDownloaded'])
                    ->name('takes.downloaded');
                Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback', [CaptureTakeController::class, 'playback'])
                    ->name('takes.playback');
            });
        });
});

/*
|--------------------------------------------------------------------------
| 招待受諾 (verified は要求しない)
|--------------------------------------------------------------------------
| GET (確認画面) は guest 可: 未ログインの招待リンクは token を session に fail-secure 保存し
| register へ誘導する (登録完了時に CreateNewUser が招待組織へ参加させる)。
| POST (受諾確定) のみ auth 必須。
*/
// GET も token を sha256 照合して DB を 1 件引き、有効/無効で応答が分岐する
// (未認証で観測できる分、姉妹の POST より攻撃面として広い)。
// POST 側の `10,1` と同値にする。未認証面のため named limiter でキーを明示する。
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:invitation-accept')
    ->name('invitations.accept');
// 招待トークンは hash 照合されるが、総当り試行そのものを有界にする
// (onboarding.activate-personal と同値 = 認証済みの一回性操作)。
Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept.store');

/*
|--------------------------------------------------------------------------
| local 専用デバッグログイン
|--------------------------------------------------------------------------
| route 登録自体を isLocal / runningUnitTests で囲む (staging / production では
| route が存在しない fail-safe。boot 時 env 判定のため後続の config 変更に影響
| されない)。runningUnitTests() は PHPUnit/Pest 実行中のみ true で、「単に
| APP_ENV=testing」の運用誤設定では登録しない。LocalOnly middleware
| (local 以外 404 + Basic 認証 + 未設定 404) は二重防御として常に併用する。
*/
if (app()->isLocal() || app()->runningUnitTests()) {
    Route::middleware(LocalOnly::class)->group(function (): void {
        Route::get('/debug/login', [DebugLoginController::class, 'index'])->name('debug.login');
        Route::post('/debug/login/{userId}', [DebugLoginController::class, 'loginAs'])->name('debug.login-as');
    });
}
