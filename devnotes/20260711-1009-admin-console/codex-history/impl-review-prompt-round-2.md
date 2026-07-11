# T006 最終 impl-review Round 2

Round 1 で「実装本文未提示のため評価保留」との回答を受け、要求された最小セットの実コードを以下に全文で提示する。役割・使命・禁止事項・レビュー観点・出力形式は Round 1 プロンプトと同一 (このプロンプト末尾に再掲) 。以下のコードに基づき、マージ可否を判定する最終レビューを完成させよ。

## ファイル: routes/web.php
```
<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Auth\ConfirmRecentAuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Capture\CaptureManualController;
use App\Http\Controllers\Capture\CaptureSyncController;
use App\Http\Controllers\Capture\CaptureTakeController;
use App\Http\Controllers\Capture\TakeUploadUrlController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DebugLoginController;
use App\Http\Controllers\HomeController;
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
use App\Http\Controllers\Webhooks\SesNotificationController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LocalOnly;
use App\Http\Middleware\NoIndex;
use App\Models\User;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Response;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

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
    ->middleware('sns.signature')
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
| /pricing は公開 Inertia 雛形 (SEO minimal 分類。SeoComposer が title を供給)。
| /terms /privacy /commerce-disclosure は Route::view の薄い Blade スタブ。文面が
| 未確定のプレースホルダのため noindex (blade の <meta robots> + NoIndex middleware の
| X-Robots-Tag で二重防御)。正式文面へ差し替えて公開する際に noindex を外すこと。
*/
Route::get('/pricing', fn () => Inertia::render('Pricing'))->name('pricing');
Route::middleware(NoIndex::class)->group(function (): void {
    Route::view('/terms', 'legal.terms')->name('legal.terms');
    Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
    Route::view('/commerce-disclosure', 'legal.commerce-disclosure')->name('legal.commerce-disclosure');
});

/*
|--------------------------------------------------------------------------
| SSO (Socialite)
|--------------------------------------------------------------------------
| 開始導線は GET の anchor リンク (form POST にしない。CSP form-action が
| リダイレクト先 IdP に適用されるため)。
*/
Route::get('/auth/{provider}/redirect/{intent}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->name('social.callback');

/*
|--------------------------------------------------------------------------
| 認証済み
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

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

    Route::get('/settings', function () {
        return Inertia::render('Settings/Index');
    })->name('settings');

    Route::get('/settings/security', function () {
        // admin guard 追加で user() は User|AdminUser の union になるため narrowing する
        $user = request()->user();
        $linkedProviders = $user instanceof User
            ? $user->socialAccounts()->pluck('provider')->all()
            : [];

        return Inertia::render('Settings/Security', [
            'socialProviders' => array_keys(config()->array('template.social_providers')),
            'linkedProviders' => $linkedProviders,
        ]);
    })->name('settings.security');

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
    // {user} は URL 整合 guard で認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
    Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
        ->name('organizations.members.update');
    Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
        ->name('organizations.members.destroy');
    // メンバーの 2FA リセット (ロックアウト救済。Owner/Admin + step-up + 理由必須。
    // {user} は URL 整合 guard で認可より前に 404)
    Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
        ->middleware('recent-auth')
        ->name('organizations.members.two-factor.reset');
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
    | 課金 (current org スコープ)。プラン変更は Stripe Checkout / Customer Portal 経由のみ。
    | Stripe webhook ルート (POST /stripe/webhook) は Cashier が自動登録する
    | (CSRF 除外は bootstrap/app.php の validateCsrfTokens except 'stripe/*')。
    | billing / webhook / 組織管理系は課金ゲート (require-active-subscription) の
    | allowlist (gate group に含めない)。未契約でも checkout に到達できることを保証する。
    */
    Route::get('/billing', [BillingController::class, 'index'])
        ->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])
        ->name('billing.checkout');
    Route::post('/billing/portal', [BillingController::class, 'portal'])
        ->name('billing.portal');

    /*
    | 組織配下の業務 route (課金ゲート対象)。有効な subscription (BillingAccess 判定)
    | を持たない組織は billing へ redirect される (JSON は 402)。
    | 新しい業務ドメインの route はこの group 内に追加すること。
    */
    Route::middleware(['require-active-subscription', 'project.in-current-org'])->group(function (): void {
        /*
        | プロジェクト (current org スコープ。URL に org / team セグメントを含めない =
        | Default Team パターンのルーティング仕様)。
        | {project} の URL 整合 guard ({project} ∈ current org) は 2 層:
        | (1) project.in-current-org middleware — FormRequest の DB ルール (unique/exists) より
        |     前に cross-org を 404 に落とす (存在オラクル防止。{project} を持たない route では
        |     no-op のため group 一括付与。網羅性は ProjectRouteCurrentOrgGuardTest が固定)
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
        });

        // プロジェクトメンバー管理 (追加は payload の user_id、削除は URL の {user})。
        // {user} は URL 整合 guard (org member か) で認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
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
                Route::post('/projects/{project}/manuals/{manual}/sync', [CaptureSyncController::class, 'store'])
                    ->name('manuals.sync');
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
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.accept');
Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware('auth')
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
```

## ファイル: app/Http/Controllers/Admin/UserManagementController.php
```
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataTransferObjects\Admin\InvitationRowData;
use App\DataTransferObjects\Admin\MemberRowData;
use App\Enums\ProjectRole;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Project\DefaultProjectResolver;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 管理メニュー > ユーザー管理 (doc/04 §4.2。GET のみ)。
 * 書き込みは既存 organizations.* endpoint (招待 / ロール変更 / 削除 / 2FA リセット) を使う。
 * URL は /manage/* (Filament panel が /admin/* を占有しているため。詳細設計 §リファレンス)。
 * current org スコープ解決のみで org URL param を持たない = cross-org 越境不能。
 */
class UserManagementController extends Controller
{
    use ResolvesCurrentOrganization;

    public function index(Request $request, DefaultProjectResolver $defaultProjects): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageMembers', $organization); // 撮影者・一般メンバーは 403

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $project = $defaultProjects->resolve($organization);

        // Default Project の pivot ロールを 1 クエリで引く (user_id => ProjectRole)
        /** @var array<int, ProjectRole> $pivotRoles */
        $pivotRoles = [];
        if ($project !== null) {
            foreach ($project->members()->get() as $member) {
                $pivot = $member->getRelationValue('pivot');
                $role = $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;
                if (is_string($role)) {
                    $pivotRoles[$member->id] = ProjectRole::from($role);
                }
            }
        }

        $members = [];
        foreach ($organization->users()->get() as $member) {
            // organizationRole null (attach 済みだが Laratrust ロール未付与の異常行) も
            // 非表示にせず「未割当」として可視化する (derive が null を Unassigned へ丸める。
            // 管理者はロール割当コマンドでこの行を修復できる = applyConsoleRole の修復経路)
            $members[] = MemberRowData::fromUser(
                $member,
                $member->organizationRole($organization),
                $pivotRoles[$member->id] ?? null,
                $user->id,
            );
        }

        $invitations = $organization->invitations()->active()->get()
            ->map(fn (OrganizationInvitation $invitation): InvitationRowData => InvitationRowData::fromInvitation($invitation))
            ->values()
            ->all();

        return Inertia::render('Admin/Users', [
            'organizationSlug' => $organization->slug,
            'members' => $members,         // list<MemberRowData>
            'invitations' => $invitations, // list<InvitationRowData>
            'hasDefaultProject' => $project !== null,
            // 管理メニュー nav: カテゴリ管理リンク (can 連動 + project 不在は非表示)。
            // URL は route helper で生成 (route 名変更耐性)
            'categoriesUrl' => $project !== null && $user->can('update', $project)
                ? route('projects.categories.index', $project)
                : null,
        ]);
    }
}
```

## ファイル: app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php
```
<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\AdminConsoleRole;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Webmozart\Assert\Assert;

/**
 * メンバー招待 (3 値遷移コマンド)。
 * 認可は Controller の Gate::authorize('manageMembers') が唯一の責務。
 * 重複招待の中立検査・Default Project 存在確認は Service 側 (TOCTOU になる DB 依存検証を
 * FormRequest に置かない)。project_role はクライアントから受けず role コマンドから導出する。
 */
class StoreOrganizationInvitationRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return array_merge([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::enum(AdminConsoleRole::class)],
        ], $this->protectedKeyMissingRules());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // 旧契約値 (organization_admin 等) を送るデプロイ跨ぎタブの回復導線を明示する
            'role.'.Enum::class => 'ロールの指定が不正です。画面を再読み込みしてやり直してください。',
        ];
    }

    /** 型付きアクセサ (validated 後の値を string へ narrow して Service に渡す) */
    public function email(): string
    {
        $email = $this->validated('email');
        Assert::string($email);

        return $email;
    }

    /** 型付きアクセサ (validated 後の値を enum へ narrow して Service に渡す) */
    public function role(): AdminConsoleRole
    {
        $role = $this->enum('role', AdminConsoleRole::class);
        Assert::isInstanceOf($role, AdminConsoleRole::class);

        return $role;
    }
}
```

## ファイル: app/Http/Requests/Organizations/UpdateOrganizationMemberRoleRequest.php
```
<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\AdminConsoleRole;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Webmozart\Assert\Assert;

/**
 * メンバーロール変更 (3 値遷移コマンド)。
 * 認可は Controller の Gate::authorize('manageMembers') が唯一の責務
 * (FormRequest では判定しない。authorize(): true は「入力検証のみ担当」の宣言)。
 * Default Project の存在確認は Service トランザクション内 (TOCTOU 封じ) のため、
 * ここでは enum 妥当性のみを検証する。
 * Owner 指定は enum 外 (AdminConsoleRole に owner がない) のため構造的に不可能
 * (Owner 昇格は transferOwnership のみ、の不変条件を型で表現)。
 */
class UpdateOrganizationMemberRoleRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return array_merge([
            'role' => ['required', 'string', Rule::enum(AdminConsoleRole::class)],
        ], $this->protectedKeyMissingRules());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // 旧契約値 (organization_admin 等) を送るデプロイ跨ぎタブの回復導線を明示する
            'role.'.Enum::class => 'ロールの指定が不正です。画面を再読み込みしてやり直してください。',
        ];
    }

    /** 型付きアクセサ (validated 後の値を enum へ narrow して Service に渡す) */
    public function role(): AdminConsoleRole
    {
        $role = $this->enum('role', AdminConsoleRole::class);
        Assert::isInstanceOf($role, AdminConsoleRole::class);

        return $role;
    }
}
```

## ファイル: app/Services/Organization/OrganizationMembershipService.php
```
<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Enums\AdminConsoleRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Enums\SecurityEventType;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Project\DefaultProjectResolver;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * 組織メンバーシップ操作の唯一の窓口 (招待 / 受諾 / ロール変更 / 削除 / オーナー移譲)。
 *
 * - ロール操作は必ず laratrust_team_id を明示する (strict_check=true)
 * - 招待 token は平文を保存せず sha256 (token_hash) のみ。平文はメールにだけ載る
 * - 既存メンバー / 既存招待への再招待はアカウント列挙対策の中立メッセージで拒否する
 */
class OrganizationMembershipService
{
    /** 招待の有効期限 (日) */
    private const EXPIRES_DAYS = 7;

    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly DefaultProjectResolver $defaultProjects,
    ) {}

    /**
     * メンバー招待 (3 値ロールコマンド)。招待レコード生成 + 受諾 URL 付きメール送信。
     * 編集者/撮影者は Default Project 存在が必須 (不在は ValidationException = Inertia error bag)。
     *
     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ) / project 不在
     */
    public function inviteMember(Organization $organization, User $invitedBy, string $email, AdminConsoleRole $role): OrganizationInvitation
    {
        if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
            // 既存メンバーか既存招待かを開示しない中立メッセージ (アカウント列挙対策)
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには招待を送信できません。'],
            ]);
        }

        // 編集者/撮影者は Default Project が前提 (送信時点の静的確認。受諾時の最終確認は
        // joinOrganization が resolveForUpdate で行い、不在なら未割当に落とす)
        if ($role->projectRole() !== null && $this->defaultProjects->resolve($organization) === null) {
            throw ValidationException::withMessages([
                'role' => ['編集者・撮影者を招待するには、先にプロジェクトを作成してください。'],
            ]);
        }

        $plainToken = OrganizationInvitation::generateToken();

        $invitation = new OrganizationInvitation(['email' => $email]);
        $invitation->organization()->associate($organization);
        $invitation->invitedBy()->associate($invitedBy);
        // role / project_role / token_hash / expires_at は明示代入 (mass-assignment させない)
        $invitation->forceFill([
            'role' => $role->organizationRole()->value,
            'project_role' => $role->projectRole()?->value,
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
            'expires_at' => now()->addDays(self::EXPIRES_DAYS),
        ]);
        $invitation->save();

        // 受諾はログイン必須 (auth ミドルウェア) のため署名なし URL でよい。平文 token は保存しない
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification(
            organizationName: $organization->name,
            acceptUrl: url('/invitations/accept?token='.$plainToken),
        ));

        return $invitation;
    }

    /**
     * 招待受諾。ログイン中ユーザーが受諾する (招待 email と user の email の一致は要求しない)。
     *
     * @throws ValidationException token 不正 / 取り消し済み / 失効 / 受諾済み / 既メンバー
     */
    public function acceptInvitation(string $plainToken, User $user): Organization
    {
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
            ->first();

        // 取り消し済みは「無効」と区別しない (取り消された事実を token 保持者に開示しない)
        if ($invitation === null || $invitation->isRevoked()) {
            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
        }
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages(['token' => ['この招待は既に使用されています。']]);
        }
        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['token' => ['この招待は有効期限が切れています。']]);
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        if ($organization->users()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages(['token' => ['既にこの組織のメンバーです。']]);
        }

        $role = OrganizationRole::from($invitation->role);

        $this->joinOrganization($invitation, $organization, $user, $role);

        return $organization;
    }

    /**
     * 登録 (register) 経路の招待受諾。CreateNewUser から呼ぶ。
     *
     * acceptInvitation (ログイン後経路) と異なり、失敗しても例外を投げず null を返す
     * (登録そのものは成功させ、呼び出し側が個人組織へ fallback するため)。register 経路は
     * 招待 email と登録 email の一致を要求する (MatchesInvitationEmail rule と対で二重防御)。
     *
     * @return Organization|null 参加した組織 / 招待が受諾不能 (不在・失効・受諾済・取消・
     *                           email 不一致・既メンバー) なら null
     */
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
            ->first();

        // active (未受諾・未失効・期限内) でなければ join しない
        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
            return null;
        }

        // 招待 email と登録 email が一致しない場合は join しない
        if ($invitation->email !== $user->email) {
            return null;
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // 既メンバー (race 等) は個人組織へ fallback
        if ($organization->users()->whereKey($user->getKey())->exists()) {
            return null;
        }

        $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

        return $organization;
    }

    /**
     * 招待の取り消し (論理失効)。行削除ではなく revoked_at を立てる (監査痕跡を残す)。
     * 既に失効/受諾済みなら冪等 no-op (二重取り消しを例外にしない)。
     */
    public function revokeInvitation(OrganizationInvitation $invitation): void
    {
        if ($invitation->isRevoked() || $invitation->isAccepted()) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * 招待受諾の確定処理 (attach + ロール付与 + pivot attach + accepted_at)。両受諾経路の共通コア。
     * accepted_at は $fillable 外のため forceFill で明示代入する。
     *
     * 並行受諾への防御は 2 層:
     * 1. **招待行の lockForUpdate**: 同一招待 (同一トークン二重送信) の並行受諾を直列化し、
     *    accepted_at / revoked_at / expires_at の判定をロック下で再実行する (TOCTOU 封じ。
     *    呼び出し元の事前検証は第 1 層として維持)
     * 2. **organization_user の原子的 INSERT (insertOrIgnore)**: 別招待経由の並行 join
     *    (同一 user × 同一 org) でも unique 違反にならず、勝った側だけが role/pivot を付与する
     *    (affected rows = 0 なら join 済みと判断してスキップ)。値はすべてサーバ側モデル由来
     *    (organization/user は relation 解決済み) で、payload 不信の保護キー規約に反しない。
     *    organization_user は (organization_id, user_id) UNIQUE + timestamps のみの pivot。
     *
     * project_role 付き招待は Default Project (resolveForUpdate = 行ロック) へ pivot attach。
     * 受諾時に project が消えていた場合は org 参加のみ = 「未割当」表示状態に落ちる (可視 degrade)。
     */
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
    {
        DB::transaction(function () use ($organization, $user, $role, $invitation): void {
            // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は冪等 no-op)
            }

            // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role/pivot は変更しない。
            //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
            $joined = DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);

                $projectRole = $locked->project_role;
                if ($projectRole instanceof ProjectRole) {
                    $project = $this->defaultProjects->resolveForUpdate($organization);
                    $project?->members()->syncWithoutDetaching([
                        $user->id => ['role' => $projectRole->value],
                    ]);
                }
            }

            $locked->forceFill(['accepted_at' => now()])->save();
        });
    }

    /**
     * ロール遷移コマンドの適用 (概念設計 D2(b))。1 トランザクションで最終状態を保証する:
     * - Admin:   org Admin + org 配下 project pivot detach (stale 掃除)
     * - Editor:  org Member + Default Project pivot role=project_admin (sync)
     * - Shooter: org Member + Default Project pivot role=project_member (sync)
     * changeRole 再利用により非メンバー拒否・最終 Owner 保護を継承する
     * (DB::transaction のネストは savepoint 扱いのため、changeRole の ValidationException は
     * そのまま外へ伝播し外側 tx ごと rollback される)。
     *
     * @throws ValidationException 非メンバー / 最終 Owner 保護 / Default Project 不在
     */
    public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role): void
    {
        DB::transaction(function () use ($organization, $target, $role): void {
            $projectRole = $role->projectRole();

            if ($projectRole === null) {
                // Admin コマンド: org ロール正規化 → stale pivot 掃除
                // (org 配下 project に限定 = cross-org 不変条件)
                $this->normalizeOrganizationRole($organization, $target, $role);
                $this->detachProjectMemberships($organization, $target);

                return;
            }

            // Editor/Shooter コマンド: 書き込み用解決を先に行う (行ロック保持。
            // 取得〜pivot 更新まで削除競合を排除 + 不在エラーをロール変更より前に確定)
            $project = $this->defaultProjects->resolveForUpdate($organization);
            if ($project === null) {
                throw ValidationException::withMessages([
                    'role' => ['編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。'],
                ]);
            }

            $this->normalizeOrganizationRole($organization, $target, $role);
            $project->members()->syncWithoutDetaching([
                $target->id => ['role' => $projectRole->value],
            ]);
        });
    }

    /**
     * 遷移コマンドの org ロール正規化。attach 済みかつ Laratrust ロール未付与の異常行 (表示状態は
     * 「未割当」= MemberRoleState::derive(null, ...)) は changeRole が「非メンバー」として
     * 拒否するため、修復経路として addRole で直接付与する (管理画面から正規化できる契約)。
     *
     * @throws ValidationException 非メンバー / 最終 Owner 保護 (changeRole 継承)
     */
    private function normalizeOrganizationRole(Organization $organization, User $target, AdminConsoleRole $role): void
    {
        if ($target->organizationRole($organization) === null) {
            // 非 attach は changeRole と同じ契約で拒否 (第 1 層は Controller の URL 整合 guard = 404)
            if (! $organization->users()->whereKey($target->getKey())->exists()) {
                throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
            }
            $target->addRole($role->organizationRole()->value, $organization->laratrust_team_id);

            return;
        }

        // 同値なら changeRole 内で早期 return = 冪等。最終 Owner 保護も継承
        $this->changeRole($organization, $target, $role->organizationRole());
    }

    /**
     * ロール変更。Owner への昇格は transferOwnership のみが正規経路
     * (Controller 側のバリデーションが Owner 指定を拒否する)。
     *
     * @throws ValidationException 非メンバー / 最後の Owner の降格
     */
    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
    {
        $currentRole = $target->organizationRole($organization);
        if ($currentRole === null) {
            throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
        }
        if ($currentRole === $newRole) {
            return;
        }

        // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
        if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $target)) {
            throw ValidationException::withMessages([
                'role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。'],
            ]);
        }

        DB::transaction(function () use ($organization, $target, $currentRole, $newRole): void {
            $target->removeRole($currentRole->value, $organization->laratrust_team_id);
            $target->addRole($newRole->value, $organization->laratrust_team_id);
        });
    }

    /**
     * メンバー削除。Owner は削除不可 (先に transferOwnership が必要)。
     *
     * @throws ValidationException 非メンバー / Owner
     */
    public function removeMember(Organization $organization, User $target): void
    {
        if (! $organization->users()->whereKey($target->getKey())->exists()) {
            throw ValidationException::withMessages(['member' => ['このユーザーは組織のメンバーではありません。']]);
        }

        $role = $target->organizationRole($organization);
        if ($role === OrganizationRole::Owner) {
            throw ValidationException::withMessages([
                'member' => ['オーナーは削除できません。先にオーナーを移譲してください。'],
            ]);
        }

        DB::transaction(function () use ($organization, $target, $role): void {
            $organization->users()->detach($target->getKey());
            if ($role !== null) {
                $target->removeRole($role->value, $organization->laratrust_team_id);
            }
            // project pivot 掃除 (org 配下 project に限定。別 org の pivot は維持)
            $this->detachProjectMemberships($organization, $target);
            // 削除した組織を current にしていた場合は外す (次回アクセス時に選び直す)
            if ($target->current_organization_id === $organization->id) {
                $target->forceFill(['current_organization_id' => null])->save();
            }
        });
    }

    /**
     * org 配下 project の pivot を一括 detach する。対象 project id は必ず
     * $organization->projects() (org-scoped relation) から解決する (cross-org 不変条件)。
     * project_members は pivot テーブルで対応する Eloquent モデル・モデルイベントを持たないため、
     * 意図的に素の delete を使う (belongsToMany::detach も pivot イベントは発火しない = 等価)。
     * 挙動契約は ConsoleRoleTransitionTest が固定する。
     */
    private function detachProjectMemberships(Organization $organization, User $target): void
    {
        /** @var list<int> $projectIds */
        $projectIds = $organization->projects()->pluck('projects.id')->all();
        if ($projectIds === []) {
            return;
        }

        DB::table('project_members')
            ->whereIn('project_id', $projectIds)
            ->where('user_id', $target->getKey())
            ->delete();
    }

    /**
     * オーナー移譲。organization_user の両者の行を lockForUpdate で直列化し、
     * 並行移譲による Owner 0 人 / 2 人の中間状態を防ぐ (spirux 方式)。
     *
     * @throws ValidationException from が Owner でない / to が非メンバー / 自己移譲
     */
    public function transferOwnership(Organization $organization, User $from, User $to): void
    {
        if ($from->getKey() === $to->getKey()) {
            throw ValidationException::withMessages(['user_id' => ['自分自身には移譲できません。']]);
        }

        DB::transaction(function () use ($organization, $from, $to): void {
            // 両者のメンバーシップ行をロック (並行する移譲・削除を直列化)。
            // count() + FOR UPDATE は pgsql が集約関数との併用を拒否するため、行を
            // 取得してロードした上で PHP 側で件数を確認する (organization_user は
            // (organization_id, user_id) UNIQUE のため最大 2 行)。
            $lockedUserIds = DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->whereIn('user_id', [$from->getKey(), $to->getKey()])
                ->lockForUpdate()
                ->pluck('user_id')
                ->all();
            if (count($lockedUserIds) < 2) {
                throw ValidationException::withMessages([
                    'user_id' => ['移譲先は組織のメンバーである必要があります。'],
                ]);
            }

            // ロック取得後に最新状態で Owner を再確認する (TOCTOU 防止)
            if ($from->organizationRole($organization) !== OrganizationRole::Owner) {
                throw ValidationException::withMessages(['user_id' => ['オーナーのみ移譲できます。']]);
            }

            $teamId = $organization->laratrust_team_id;
            $toRole = $to->organizationRole($organization);

            $from->removeRole(OrganizationRole::Owner->value, $teamId);
            $from->addRole(OrganizationRole::Admin->value, $teamId);

            if ($toRole !== null) {
                $to->removeRole($toRole->value, $teamId);
            }
            $to->addRole(OrganizationRole::Owner->value, $teamId);
        });

        $this->recorder->record(SecurityEventType::OwnershipTransferred, $from, [
            'organization_id' => $organization->id,
            'from_user_id' => $from->getKey(),
            'to_user_id' => $to->getKey(),
        ]);
    }

    /**
     * email がこの組織の既存メンバーのものか (blind index 照合)。
     */
    private function emailBelongsToMember(Organization $organization, string $email): bool
    {
        /** @var User|null $user */
        $user = User::whereBlind('email', 'email_index', $email)->first();
        if ($user === null) {
            return false;
        }

        return $organization->users()->whereKey($user->getKey())->exists();
    }

    /**
     * 有効な (未失効・未受諾の) 既存招待があるか。
     */
    private function hasPendingInvitation(Organization $organization, string $email): bool
    {
        return $organization->invitations()
            ->whereBlind('email', 'email_index', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * target 以外に Owner がいるか。
     */
    private function hasAnotherOwner(Organization $organization, User $target): bool
    {
        return $organization->users()
            ->whereKeyNot($target->getKey())
            ->get()
            ->contains(
                fn (User $member): bool => $member->organizationRole($organization) === OrganizationRole::Owner,
            );
    }
}
```

## ファイル: app/Enums/AdminConsoleRole.php
```
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 管理メニュー (ユーザー管理) のロール遷移コマンド (doc/02 §2.5 + doc/10 §10.5 の合成)。
 * 保存概念ではない: org ロール + Default Project pivot という既存プリミティブへの
 * 「正規状態への遷移」を表す。表示状態は MemberRoleState (導出) が担う。
 * Owner を含まない = Owner 昇格は transferOwnership のみという不変条件の型表現。
 */
enum AdminConsoleRole: string
{
    case Admin = 'admin';     // 管理者 = org Admin (pivot は掃除)
    case Editor = 'editor';   // 編集者 = org Member + project_admin
    case Shooter = 'shooter'; // 撮影者 = org Member + project_member

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
        };
    }

    /** コマンド適用後の org ロール */
    public function organizationRole(): OrganizationRole
    {
        return $this === self::Admin ? OrganizationRole::Admin : OrganizationRole::Member;
    }

    /** コマンド適用後の Default Project pivot ロール (Admin コマンドは pivot なし = null) */
    public function projectRole(): ?ProjectRole
    {
        return match ($this) {
            self::Admin => null,
            self::Editor => ProjectRole::Admin,
            self::Shooter => ProjectRole::Member,
        };
    }
}
```

## ファイル: app/Enums/MemberRoleState.php
```
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ユーザー管理画面の表示状態 (毎リクエスト導出。DB に保存しない = backfill 不要)。
 * org ロール × Default Project pivot の全組合せを漏れなく 5 値に分類する
 * (概念設計 D2 の canonical mapping)。
 */
enum MemberRoleState: string
{
    case Owner = 'owner';           // 管理者 (オーナー)。変更不可 (transferOwnership のみ)
    case Admin = 'admin';           // 管理者。stale pivot があっても org ロール優先で無視
    case Editor = 'editor';         // 編集者 (org Member + project_admin)
    case Shooter = 'shooter';       // 撮影者 (org Member + project_member)
    case Unassigned = 'unassigned'; // 未割当 (org Member + pivot なし)。割当を促す表示

    /**
     * org ロール null (organization_user attach 済みだが Laratrust ロール未付与の異常行) も
     * Unassigned へ丸める: 異常行を非表示にせず「未割当」として可視化し、管理画面から
     * ロール割当コマンドで修復できるようにする (applyConsoleRole の修復経路と対)。
     * null 判定は project pivot 判定より**必ず先**に評価する (org ロールなし + stale pivot が
     * Editor/Shooter と誤表示され修復契約と食い違うのを防ぐ)。
     */
    public static function derive(?OrganizationRole $orgRole, ?ProjectRole $projectRole): self
    {
        return match (true) {
            $orgRole === null => self::Unassigned,
            $orgRole === OrganizationRole::Owner => self::Owner,
            $orgRole === OrganizationRole::Admin => self::Admin,
            $projectRole === ProjectRole::Admin => self::Editor,
            $projectRole === ProjectRole::Member => self::Shooter,
            default => self::Unassigned,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => '管理者（オーナー）',
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
            self::Unassigned => '未割当',
        };
    }
}
```

## ファイル: app/Services/Project/DefaultProjectResolver.php
```
<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Models\Organization;
use App\Models\Project;

/**
 * Default Project の解決規約の single source of truth (v1 = 単一 Default Project 前提)。
 * 「org の先頭 project (projects.id 昇順の最初)」を Default Project と定義する。
 * 複数 project 化の際はここだけを差し替える (呼び出し側は不変)。
 *
 * read / write の分離 (概念設計 D2):
 * - resolve(): 表示・redirect 用 (ロックなし)。capture.home / 管理メニュー導線 / 一覧表示
 * - resolveForUpdate(): pivot 書き込み用 (lockForUpdate)。呼び出し側トランザクション内で
 *   取得から pivot 更新完了まで Project 行ロックを保持し、解決直後の project 削除競合を
 *   排除する (CategoryService の「Project 行ロック = 直列化点」既存規約と同型)。
 */
class DefaultProjectResolver
{
    public function resolve(Organization $organization): ?Project
    {
        /** @var Project|null */
        return $organization->projects()->orderBy('projects.id')->first();
    }

    /**
     * 必ず DB::transaction 内から呼ぶこと (ロール変更・招待受諾の pivot 書き込み専用)。
     *
     * 「id を先に確定 → 行ロック付き再取得」の 2 段にする: HasManyThrough に直接
     * lockForUpdate() を掛けると JOIN 先 (custom_teams) までロック対象になり、pgsql では
     * FOR UPDATE と JOIN の組合せが複雑化するため、単一テーブルの主キー lock に落とす。
     * id 確定後に行が消えた場合は null が返り、呼び出し側の不在時契約 (error bag / 未割当)
     * に倒れる。
     */
    public function resolveForUpdate(Organization $organization): ?Project
    {
        $id = $organization->projects()->orderBy('projects.id')->value('projects.id');
        if ($id === null) {
            return null;
        }

        /** @var Project|null */
        return Project::query()->whereKey($id)->lockForUpdate()->first();
    }
}
```

## ファイル: app/Http/Controllers/Projects/CategoryController.php
```
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ReorderCategoriesRequest;
use App\Http\Requests\Projects\StoreCategoryRequest;
use App\Http\Requests\Projects\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Services\Manual\CategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * Category (Project 配下の動画マニュアル分類) の管理画面 (index) と書き込み操作。
 * 一覧表示はカテゴリ管理画面 (Admin/Categories) が担う (Projects/Show はフィルタ select のみ)。
 *
 * nested route の URL 整合は 2 層 (Item 見本と同じ):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {category} ∈ {project} (routes 側の Route::scopeBindings() = $project->categories() 経由)
 * いずれも**認可より前に 404** (403 で存在を漏らさない)。
 *
 * sort_order は Service 専有 (作成時末尾採番 + reorder のみ)。payload からは受けない。
 */
class CategoryController extends Controller
{
    use ResolvesCurrentOrganization;

    /** カテゴリ管理画面 (doc/04 §4.2。追加・編集・削除・▲▼ は既存 write endpoint を使う) */
    public function index(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 (cross-org の存在を漏らさない)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('viewAny', [Category::class, $project]);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return Inertia::render('Admin/Categories', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            // sort_order 順 (▲▼ の表示順 = 動画一覧の並び順と同一規約)
            'categories' => array_values($project->categories()->orderBy('sort_order')->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])
                ->all()),
            // 管理メニュー nav: ユーザー管理リンク (org 管理者のみ。can 連動。route helper で生成)
            'usersUrl' => $user->can('manageMembers', $organization) ? route('manage.users.index') : null,
        ]);
    }

    /** Category 作成。project_id は URL から導出し relation 経由で代入する (payload では 422) */
    public function store(StoreCategoryRequest $request, Project $project, CategoryService $categories): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [Category::class, $project]);

        $name = $request->validated('name');
        Assert::string($name);

        $categories->create($project, $name);

        return back()->with('success', 'カテゴリを追加しました');
    }

    /** Category 更新 (name のみ) */
    public function update(UpdateCategoryRequest $request, Project $project, Category $category, CategoryService $categories): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({category} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $category);

        $name = $request->validated('name');
        Assert::string($name);

        $categories->update($project, $category, $name);

        return back()->with('success', 'カテゴリを更新しました');
    }

    /** Category 削除 (所属 manual は FK nullOnDelete で未分類化) */
    public function destroy(Request $request, Project $project, Category $category, CategoryService $categories): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({category} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $category);

        $categories->delete($project, $category);

        return back()->with('success', 'カテゴリを削除しました');
    }

    /**
     * Category 並べ替え (payload = 当該 project の category id 順序配列)。
     * 集合一致検証は Service (Project 行ロック後) が行い、不一致は 422。
     */
    public function reorder(ReorderCategoriesRequest $request, Project $project, CategoryService $categories): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('reorder', [Category::class, $project]);

        $order = $request->validated('order');
        Assert::isArray($order);
        // 'order.*' => integer 検証済み。数値文字列も許容されるため int へ正規化する
        $orderedIds = [];
        foreach ($order as $id) {
            Assert::integerish($id);
            $orderedIds[] = (int) $id;
        }

        $categories->reorder($project, $orderedIds);

        return back()->with('success', 'カテゴリの並び順を更新しました');
    }
}
```

## ファイル: app/Policies/CategoryPolicy.php
```
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;

/**
 * Category (Project 配下の動画マニュアル分類) の認可。
 * 子リソースは親 Policy に委譲する (直 fetch 禁止)。
 *
 * 権限表 (doc/10 §10.5): 編集者 (project_admin / org 管理者) = write 全可、
 * 撮影者 (project_member) = 閲覧のみ。write 判定は ProjectPolicy::update が担う。
 */
class CategoryPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /**
     * カテゴリ管理画面 (専用 index) の閲覧: プロジェクトを操作できる人 (= 編集者以上)。
     * 撮影者の read は一覧 props (projects.show / capture) 経由であり、管理画面には入れない。
     * 対象 Category が無いため Project を追加引数に取る (create/reorder と同じシグネチャ規約)。
     */
    public function viewAny(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }

    /** 閲覧: プロジェクトを閲覧できる人 */
    public function view(User $user, Category $category): bool
    {
        $project = $category->project;

        return $project !== null && $this->projectPolicy->view($user, $project);
    }

    /** 作成: プロジェクトを操作できる人 (対象 Category が無いため Project を追加引数に取る) */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }

    /** 更新: プロジェクトを操作できる人 */
    public function update(User $user, Category $category): bool
    {
        $project = $category->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 削除: プロジェクトを操作できる人 */
    public function delete(User $user, Category $category): bool
    {
        $project = $category->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /**
     * 並べ替え: プロジェクトを操作できる人。
     * 対象 Category が単一でないため Project を追加引数に取る専用シグネチャ
     * (Gate::authorize('reorder', [Category::class, $project]))。
     */
    public function reorder(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }
}
```

## ファイル: tests/Architecture/ManageRouteAuthGuardTest.php
```
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * 管理メニュー (/manage/*) の guard invariant (deny-by-default)。
 *
 * /manage/ 配下の全 named route は auth + verified middleware を持たなければならない
 * (管理メニューは PII (メンバー email) を含む管理者専用画面群。将来 /manage/ 配下へ
 * route を足したときの guard 漏れを構造的に落とす)。
 * 認可 (manageMembers 等) は各 Controller の Gate::authorize の責務 (Feature テストで固定)。
 */
test('/manage/ 配下の全 route は auth + verified middleware を持つ', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'manage/')) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $middleware = $route->gatherMiddleware();

        foreach (['auth', 'verified'] as $required) {
            if (! in_array($required, $middleware, true)) {
                $violations[] = "route {$name} に {$required} middleware が無い";
            }
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    // route が 1 本も検査されない (= /manage/ route が消えた/リネームされた) 場合も fail させ、
    // テスト自体の空振り drift を検知する
    expect($checked)->toBeGreaterThan(0);
});
```

## ファイル: tests/Architecture/ProjectMemberPivotWritePathTest.php
```
<?php

declare(strict_types=1);

/*
 * project_members pivot の書き込み経路 inventory (deny-by-default。
 * ScenarioWritePathInventoryTest と同型の token ベース静的走査)。
 *
 * ロール遷移コマンド (applyConsoleRole) / 招待受諾 (joinOrganization) / removeMember の
 * pivot 掃除は OrganizationMembershipService に、プロジェクト個別のメンバー操作は
 * ProjectMemberController に閉じる。経路が増えると「org ロールと pivot の整合を 1 tx で
 * 保証する」契約 (概念設計 D2) が崩れるため、新規経路はここへの登録 + 設計判断を必須とする。
 *
 * 検出 A: 文字列リテラル 'project_members' の出現 (DB::table 直書き経路の deny)
 * 検出 B: `members()->attach|detach|sync|syncWithoutDetaching|toggle` の呼び出し形
 * いずれも allowlist 外の app/ コードに現れたら fail。
 */

final class ProjectMemberPivotWriteScanner
{
    /**
     * 検出 A の allowlist (app/ 相対パス)。
     * - Models/Project.php: belongsToMany の pivot テーブル名宣言 (書き込みではない)
     * - OrganizationMembershipService: detachProjectMemberships の素 delete (org relation 限定)
     */
    private const PROJECT_MEMBERS_LITERAL_ALLOWED = [
        'Models/Project.php',
        'Services/Organization/OrganizationMembershipService.php',
    ];

    /** 検出 B の allowlist (app/ 相対パス) */
    private const MEMBERS_WRITE_ALLOWED = [
        'Http/Controllers/Projects/ProjectMemberController.php',
        'Services/Organization/OrganizationMembershipService.php',
    ];

    /**
     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
     */
    public static function findViolations(): array
    {
        $appDir = self::appDir();
        $violations = [
            'project_members_literal' => [],
            'members_relation_write' => [],
        ];

        foreach (self::phpFiles($appDir) as $path) {
            $relative = substr($path, strlen($appDir) + 1);
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException("Failed to read PHP source: {$path}");
            }

            if (self::containsProjectMembersLiteral($source)
                && ! in_array($relative, self::PROJECT_MEMBERS_LITERAL_ALLOWED, true)) {
                $violations['project_members_literal'][] = $relative;
            }
            if (self::containsMembersRelationWrite($source)
                && ! in_array($relative, self::MEMBERS_WRITE_ALLOWED, true)) {
                $violations['members_relation_write'][] = $relative;
            }
        }

        return $violations;
    }

    /** 検出 A: 文字列リテラル 'project_members' (コメント・docblock 内は無視) */
    private static function containsProjectMembersLiteral(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING
                && str_contains($token[1], 'project_members')) {
                return true;
            }
        }

        return false;
    }

    /** 検出 B: `members()->attach|detach|sync*|toggle` の呼び出し形 (token 列で判定) */
    private static function containsMembersRelationWrite(string $source): bool
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $writeMethods = ['attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle'];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'members') {
                continue;
            }
            // members ( ) -> {writeMethod} の並びを探す (間の空白/コメントはスキップ)
            $j = self::nextMeaningful($tokens, $i + 1);
            if ($j === null || $tokens[$j] !== '(') {
                continue;
            }
            $j = self::nextMeaningful($tokens, $j + 1);
            if ($j === null || $tokens[$j] !== ')') {
                continue;
            }
            $j = self::nextMeaningful($tokens, $j + 1);
            if ($j === null || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $j = self::nextMeaningful($tokens, $j + 1);
            if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                && in_array(strtolower($tokens[$j][1]), $writeMethods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private static function appDir(): string
    {
        $dir = realpath(__DIR__.'/../../app');
        if ($dir === false) {
            throw new RuntimeException('app directory not found');
        }

        return $dir;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}

test('project_members への書き込みは inventory (OrganizationMembershipService / ProjectMemberController) の外に現れない', function (): void {
    $violations = ProjectMemberPivotWriteScanner::findViolations();

    expect($violations['project_members_literal'])->toBe([]);
    expect($violations['members_relation_write'])->toBe([]);
});
```

## ファイル: tests/Feature/Organization/ConsoleRoleTransitionTest.php
```
<?php

declare(strict_types=1);

use App\Enums\AdminConsoleRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Validation\ValidationException;

/*
 * ロール遷移コマンド (applyConsoleRole) の最終状態契約 (概念設計 D2(b)):
 * - Admin:   org Admin + org 配下 project pivot detach (stale 掃除)
 * - Editor:  org Member + Default Project pivot role=project_admin
 * - Shooter: org Member + Default Project pivot role=project_member
 * 1 トランザクションで保証し、中間状態を残さない。
 * removeMember の pivot 掃除 (org 配下限定 = cross-org 不変条件) もここで固定する。
 */

/**
 * @return array{Organization, User, Project} [organization, owner, defaultProject]
 */
function createOrgWithDefaultProject(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    return [$organization, $owner, $project];
}

test('editor → shooter: pivot role が project_member に更新され org ロールは Member のまま', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Admin);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Shooter);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Member);
});

test('shooter → admin: org Admin へ昇格し pivot は detach される (stale 掃除)', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Admin);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Admin);
    expect($project->memberRole($member))->toBeNull();
});

test('admin → editor: org Member へ降格し pivot project_admin が付与される', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization, OrganizationRole::Admin);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
});

test('未割当 (org Member + pivot なし) → editor: pivot が付与される', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
});

test('editor/shooter コマンドは Default Project 不在なら ValidationException (role)', function (AdminConsoleRole $role): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, $role))
        ->toThrow(ValidationException::class);
    // org ロールは変更されない (1 tx = 中間状態を残さない)
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
})->with([
    'editor' => [AdminConsoleRole::Editor],
    'shooter' => [AdminConsoleRole::Shooter],
]);

test('endpoint 経由: Default Project 不在の editor コマンドは error bag (押下時エラー表示)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => AdminConsoleRole::Editor->value,
    ]);

    $response->assertSessionHasErrors('role');
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});

test('endpoint 経由: editor コマンドで org ロールと pivot が 1 操作で揃う', function (): void {
    [$organization, $owner, $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => AdminConsoleRole::Editor->value,
    ]);

    $response->assertSessionHas('success');
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
});

test('最終 Owner への admin コマンドは changeRole の最終 Owner 保護を継承する', function (): void {
    [$organization, $owner, $project] = createOrgWithDefaultProject();
    attachProjectMember($project, $owner, ProjectRole::Admin);

    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $owner, AdminConsoleRole::Admin))
        ->toThrow(ValidationException::class);
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
    // ロール変更が拒否されたら pivot 掃除にも到達しない (最終状態が部分適用されない)
    expect($project->memberRole($owner))->toBe(ProjectRole::Admin);
});

test('非メンバー (organization_user 非 attach) への適用は ValidationException', function (): void {
    [$organization] = createOrgWithDefaultProject();
    $outsider = User::factory()->create();

    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $outsider, AdminConsoleRole::Shooter))
        ->toThrow(ValidationException::class);
    expect($organization->users()->whereKey($outsider->getKey())->exists())->toBeFalse();
});

test('修復経路: attach 済み + Laratrust ロール未付与の異常行へ shooter コマンドで正規化できる', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    // 異常行を再現: attach のみでロール未付与 (表示状態は「未割当」)
    $broken = User::factory()->create();
    $organization->users()->attach($broken);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $broken, AdminConsoleRole::Shooter);

    expect($broken->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($broken))->toBe(ProjectRole::Member);
});

test('同値コマンドは冪等 (editor → editor で pivot / org ロール不変)', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Admin);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
});

test('admin コマンドは org 配下の全 project の stale pivot を掃除する (別 org の pivot は維持)', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $second = Project::factory()->forOrganization($organization)->create();
    [$otherOrg] = createOrganizationWithOwner('別組織');
    $otherProject = Project::factory()->forOrganization($otherOrg)->create();

    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Admin);
    attachProjectMember($second, $member, ProjectRole::Member);
    // 別 org にも所属させる (cross-org 掃除をしないことの fixture)
    $otherOrg->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $otherOrg->laratrust_team_id);
    attachProjectMember($otherProject, $member, ProjectRole::Member);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Admin);

    expect($project->memberRole($member))->toBeNull();
    expect($second->memberRole($member))->toBeNull();
    expect($otherProject->memberRole($member))->toBe(ProjectRole::Member);
});

test('removeMember は org 配下 project の pivot を掃除し、別 org の pivot は維持する', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    [$otherOrg] = createOrganizationWithOwner('別組織');
    $otherProject = Project::factory()->forOrganization($otherOrg)->create();

    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);
    $otherOrg->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $otherOrg->laratrust_team_id);
    attachProjectMember($otherProject, $member, ProjectRole::Admin);

    app(OrganizationMembershipService::class)->removeMember($organization, $member);

    expect($organization->users()->whereKey($member->getKey())->exists())->toBeFalse();
    expect($project->memberRole($member))->toBeNull();
    expect($otherProject->memberRole($member))->toBe(ProjectRole::Admin);
});
```

## ファイル: tests/Feature/Admin/UserManagementPageTest.php
```
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;

/*
 * 管理メニュー > ユーザー管理 (GET /manage/users)。
 * 読み取り専用画面 (書き込みは既存 organizations.* endpoint)。
 * PII (email) の可視性契約: manageMembers 権限者しか画面自体に到達できない (403 境界)。
 */

test('org Owner は 200 + Admin/Users component で members/invitations shape を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->editorInvitation()
        ->create(['email' => 'pending-editor@example.com']);

    $response = $this->actingAs($owner)->get('/manage/users');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Users')
        ->where('organizationSlug', $organization->slug)
        ->where('members.0.roleState', 'owner')
        ->where('members.0.isSelf', true)
        ->where('invitations.0.email', 'pending-editor@example.com')
        ->where('invitations.0.roleState', 'editor')
        ->where('hasDefaultProject', false)
        ->where('categoriesUrl', null));
});

test('org Admin も閲覧できる (200)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($admin)->get('/manage/users')->assertOk();
});

test('org Member (編集者 = project_admin でも org は Member) は 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    $editor->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($editor)->get('/manage/users')->assertForbidden();
});

test('未ログインは login へ redirect される', function (): void {
    $this->get('/manage/users')->assertRedirect('/login');
});

test('roleState 導出: owner/admin/editor/shooter/unassigned の 5 状態が rows に正しく出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    $shooter = attachOrganizationMember($organization);
    attachProjectMember($project, $shooter, ProjectRole::Member);
    $unassigned = attachOrganizationMember($organization);
    // Laratrust ロール未付与の異常行 (attach のみ) も「未割当」として表示される
    $broken = User::factory()->create();
    $organization->users()->attach($broken);

    $response = $this->actingAs($owner)->get('/manage/users');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($owner, $admin, $editor, $shooter, $unassigned, $broken): void {
        /** @var list<array{id: int, roleState: string}> $members */
        $members = $page->toArray()['props']['members'];
        $states = [];
        foreach ($members as $row) {
            $states[$row['id']] = $row['roleState'];
        }
        expect($states[$owner->id])->toBe('owner');
        expect($states[$admin->id])->toBe('admin');
        expect($states[$editor->id])->toBe('editor');
        expect($states[$shooter->id])->toBe('shooter');
        expect($states[$unassigned->id])->toBe('unassigned');
        expect($states[$broken->id])->toBe('unassigned');
    });
});

test('categoriesUrl: project があり org admin なら URL・project 不在なら null', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/manage/users')
        ->assertInertia(fn ($page) => $page->where('categoriesUrl', null)->where('hasDefaultProject', false));

    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/manage/users')
        ->assertInertia(fn ($page) => $page
            ->where('hasDefaultProject', true)
            ->where('categoriesUrl', route('projects.categories.index', $project)));
});

test('招待一覧は active のみ (失効・受諾済・取消済は出ない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'active@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->expired()->create(['email' => 'expired@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->accepted()->create(['email' => 'accepted@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->revoked()->create(['email' => 'revoked@example.com']);

    $response = $this->actingAs($owner)->get('/manage/users');

    $response->assertInertia(fn ($page) => $page
        ->count('invitations', 1)
        ->where('invitations.0.email', 'active@example.com')
        // 旧招待 (project_role なし) は未割当語彙で表示される
        ->where('invitations.0.roleState', 'unassigned'));
});

test('current org 未設定 (組織未所属状態) は 404', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/manage/users')->assertNotFound();
});
```

## ファイル: resources/js/pages/Admin/Users.svelte
```
<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AdminMenuNav from "@/components/features/admin/AdminMenuNav.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";
    import type { ConsoleRole, InvitationRow, MemberRow } from "@/types/admin";

    /**
     * 管理メニュー > ユーザー管理 (doc/04 §4.2。モック PC_管理メニュー 02〜09)。
     * ロールは 3 値遷移コマンド (管理者/編集者/撮影者) の 1 セレクト。表示状態は 5 値
     * (owner/admin/editor/shooter/unassigned) をサーバが導出して配る。
     * 書き込みは既存 organizations.* endpoint (招待 / ロール変更 / 削除 / 2FA リセット)。
     * ボタンは必須未充足でも disabled にしない (押下時にサーバの error bag を表示 = 禁止事項 8)。
     */
    interface Props {
        organizationSlug: string;
        members: MemberRow[];
        invitations: InvitationRow[];
        hasDefaultProject: boolean;
        categoriesUrl: string | null;
    }

    let { organizationSlug, members, invitations, hasDefaultProject, categoriesUrl }: Props =
        $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 閲覧者は manageMembers 権限者 (owner/admin) のみ。Owner 判定は自分の行から導出する
    const viewerIsOwner = $derived(
        members.find((member) => member.isSelf)?.roleState === "owner",
    );

    /** ロール select の選択肢 (遷移コマンド 3 値。owner は enum 外 = 構造的に指定不可) */
    const ROLE_OPTIONS: { value: ConsoleRole; label: string }[] = [
        { value: "admin", label: "管理者" },
        { value: "editor", label: "編集者" },
        { value: "shooter", label: "撮影者" },
    ];

    /** ロール select を出す行か (owner 行・自分の行はテキスト表示 = 現行 Settings と同じ流儀) */
    function canChangeRole(member: MemberRow): boolean {
        return member.roleState !== "owner" && !member.isSelf;
    }

    /* ---- ロール変更 (3 値遷移コマンド) ---- */
    let roleErrorMemberId = $state<number | null>(null);
    let changingRole = $state(false);

    function changeRole(member: MemberRow, role: string): void {
        if (role === "" || changingRole) return; // 未割当の空 option / 二重送信の冪等ガード
        changingRole = true;
        router.patch(
            `/organizations/${organizationSlug}/members/${member.id}`,
            { role },
            {
                preserveScroll: true,
                onError: () => {
                    roleErrorMemberId = member.id;
                },
                onSuccess: () => {
                    roleErrorMemberId = null;
                },
                onFinish: () => {
                    changingRole = false;
                },
            },
        );
    }

    const pageErrors = $derived((page.props.errors ?? {}) as Record<string, string>);

    /* ---- メンバー削除 (モック 08 削除アラート) ---- */
    let removeTarget = $state<MemberRow | null>(null);
    let removeDialogOpen = $state(false);
    let removing = $state(false);

    function openRemoveDialog(member: MemberRow): void {
        removeTarget = member;
        removeDialogOpen = true;
    }

    function removeMember(): void {
        if (removeTarget === null || removing) return;
        router.delete(`/organizations/${organizationSlug}/members/${removeTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                removing = true;
            },
            onFinish: () => {
                removing = false;
                removeDialogOpen = false;
            },
        });
    }

    /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
    let recentAuthOpen = $state(false);
    let recentAuthStatus = $state<RecentAuthStatus | null>(null);
    let pendingAction: (() => void) | null = null;

    function guardWithRecentAuth(action: () => void): void {
        void withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
        });
    }

    function resumePendingAction(): void {
        const action = pendingAction;
        pendingAction = null;
        action?.();
    }

    /* ---- メンバー 2FA リセット (Owner/Admin。recent-auth + 理由必須。Settings から移設) ---- */
    let resetTwoFactorTarget = $state<MemberRow | null>(null);
    let resetTwoFactorModalOpen = $state(false);
    const resetTwoFactorForm = useForm({ reason: "" });

    function openResetTwoFactorModal(member: MemberRow): void {
        resetTwoFactorTarget = member;
        resetTwoFactorForm.reset();
        resetTwoFactorForm.clearErrors();
        resetTwoFactorModalOpen = true;
    }

    function submitResetTwoFactor(event: SubmitEvent): void {
        event.preventDefault();
        if (resetTwoFactorForm.processing) return; // 二重送信の冪等ガード
        const target = resetTwoFactorTarget;
        if (target === null) return;
        guardWithRecentAuth(() => {
            resetTwoFactorForm.delete(
                `/organizations/${organizationSlug}/members/${target.id}/two-factor`,
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        resetTwoFactorModalOpen = false;
                    },
                },
            );
        });
    }

    /** 2FA リセットを提示できる対象か (自分以外 + 設定済み + Admin は org Member 系のみ対象) */
    function canResetTwoFactor(member: MemberRow): boolean {
        if (member.isSelf || member.twoFactorStatus === "disabled") {
            return false;
        }
        // Owner は誰でも。Admin は org Member (editor/shooter/unassigned) のみ (同格以上は不可)
        return (
            viewerIsOwner ||
            member.roleState === "editor" ||
            member.roleState === "shooter" ||
            member.roleState === "unassigned"
        );
    }

    /* ---- ユーザー追加 (招待。モック 03/04/06) ---- */
    const inviteForm = useForm({ email: "", role: "shooter" });

    function submitInvite(event: SubmitEvent): void {
        event.preventDefault();
        if (inviteForm.processing) return; // 二重送信の冪等ガード
        inviteForm.post(`/organizations/${organizationSlug}/invitations`, {
            preserveScroll: true,
            onSuccess: () => {
                inviteForm.reset();
            },
        });
    }

    /* ---- 招待取り消し ---- */
    let revokeTarget = $state<InvitationRow | null>(null);
    let revokeDialogOpen = $state(false);
    let revoking = $state(false);

    function openRevokeDialog(invitation: InvitationRow): void {
        revokeTarget = invitation;
        revokeDialogOpen = true;
    }

    function revokeInvitation(): void {
        if (revokeTarget === null || revoking) return;
        router.delete(`/organizations/${organizationSlug}/invitations/${revokeTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                revoking = true;
            },
            onFinish: () => {
                revoking = false;
                revokeDialogOpen = false;
            },
        });
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">ユーザー管理</h1>
    <p class="mt-1 text-caption text-text-secondary">
        組織のメンバーと招待を管理します。ロールは「管理者・編集者・撮影者」から選択します。
    </p>

    <div class="mt-6 flex flex-col gap-6 md:flex-row md:items-start">
        <aside class="w-full shrink-0 md:w-56">
            <AdminMenuNav active="users" usersUrl="/manage/users" {categoriesUrl} />
        </aside>

        <div class="flex min-w-0 grow flex-col gap-10">
            <Card padding="lg">
                <h2 class="text-h3">メンバー一覧</h2>
                {#if !hasDefaultProject}
                    <p class="mt-2 text-caption text-text-secondary" data-testid="no-project-note">
                        プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。
                    </p>
                {/if}
                <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                    {#each members as member (member.id)}
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-body">{member.name}</p>
                                    {#if member.twoFactorStatus === "enabled"}
                                        <Badge tone="success">2FA</Badge>
                                    {/if}
                                    {#if member.roleState === "unassigned"}
                                        <Badge tone="warning" testId={`unassigned-${member.id}`}>
                                            未割当
                                        </Badge>
                                    {/if}
                                </div>
                                <p class="truncate text-caption text-text-secondary">
                                    {member.email}
                                </p>
                                {#if roleErrorMemberId === member.id && pageErrors.role}
                                    <FormError
                                        message={pageErrors.role}
                                        testId={`role-error-${member.id}`}
                                    />
                                {/if}
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                {#if canResetTwoFactor(member)}
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openResetTwoFactorModal(member)}
                                        testId={`reset-two-factor-${member.id}`}
                                    >
                                        2FA 解除
                                    </Button>
                                {/if}
                                {#if canChangeRole(member)}
                                    <Select
                                        value={member.roleState === "unassigned"
                                            ? ""
                                            : member.roleState}
                                        aria-label={`${member.name} のロール`}
                                        onchange={(event) =>
                                            changeRole(member, event.currentTarget.value)}
                                        testId={`member-role-${member.id}`}
                                    >
                                        {#if member.roleState === "unassigned"}
                                            <option value="">未割当（選択してください）</option>
                                        {/if}
                                        {#each ROLE_OPTIONS as option (option.value)}
                                            <option value={option.value}>{option.label}</option>
                                        {/each}
                                    </Select>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRemoveDialog(member)}
                                        testId={`remove-member-${member.id}`}
                                    >
                                        削除
                                    </Button>
                                {:else}
                                    <span class="text-caption text-text-secondary">
                                        {member.roleLabel}
                                    </span>
                                {/if}
                            </div>
                        </li>
                    {/each}
                </ul>
            </Card>

            <Card padding="lg">
                <h2 class="text-h3">ユーザーを追加</h2>
                <p class="mt-1 text-caption text-text-secondary">
                    招待メールを送信します。招待の有効期限は 7 日間です。
                </p>
                <form onsubmit={submitInvite} class="mt-4 flex flex-col gap-4">
                    <FormField
                        label="メールアドレス"
                        id="invite-email"
                        error={inviteForm.errors.email}
                    >
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="email"
                                bind:value={inviteForm.email}
                                error={invalid}
                                aria-describedby={describedBy}
                                autocomplete="off"
                            />
                        {/snippet}
                    </FormField>
                    <FormField label="ロール" id="invite-role" error={inviteForm.errors.role}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Select
                                {id}
                                bind:value={inviteForm.role}
                                error={invalid}
                                aria-describedby={describedBy}
                            >
                                {#each ROLE_OPTIONS as option (option.value)}
                                    <option value={option.value}>{option.label}</option>
                                {/each}
                            </Select>
                        {/snippet}
                    </FormField>
                    <div>
                        <Button
                            type="submit"
                            loading={inviteForm.processing}
                            testId="invite-submit"
                        >
                            招待を送信
                        </Button>
                    </div>
                </form>
            </Card>

            <Card padding="lg">
                <h2 class="text-h3">招待中</h2>
                {#if invitations.length === 0}
                    <EmptyState
                        title="有効な招待はありません"
                        description="送信した招待は受諾されるか期限が切れるまでここに表示されます。"
                        testId="invitations-empty"
                    />
                {:else}
                    <ul
                        class="mt-4 flex flex-col divide-y divide-border"
                        data-testid="invitation-list"
                    >
                        {#each invitations as invitation (invitation.id)}
                            <li class="flex items-center justify-between gap-4 py-3">
                                <p class="truncate text-body">{invitation.email}</p>
                                <div class="flex shrink-0 items-center gap-3">
                                    <p class="text-caption text-text-secondary">
                                        {invitation.roleLabel} ・ 期限 {invitation.expiresAt}
                                    </p>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRevokeDialog(invitation)}
                                        testId={`revoke-invitation-${invitation.id}`}
                                    >
                                        取消
                                    </Button>
                                </div>
                            </li>
                        {/each}
                    </ul>
                {/if}
            </Card>
        </div>
    </div>

    <ConfirmDialog
        bind:open={removeDialogOpen}
        title="ユーザー削除"
        message={`${removeTarget?.name ?? ""} さんをこの組織から削除しますか？ この操作は取り消せません。`}
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={removing}
        onConfirm={removeMember}
        testId="remove-member-dialog"
    />

    <ConfirmDialog
        bind:open={revokeDialogOpen}
        title="招待の取り消し"
        message={`${revokeTarget?.email ?? ""} への招待を取り消しますか？ 取り消した招待は受諾できなくなります。`}
        confirmLabel="取り消す"
        confirmVariant="danger"
        processing={revoking}
        onConfirm={revokeInvitation}
        testId="revoke-invitation-dialog"
    />

    <Modal
        bind:open={resetTwoFactorModalOpen}
        title="メンバーの 2FA を解除"
        testId="reset-two-factor-modal"
    >
        <form onsubmit={submitResetTwoFactor} class="flex flex-col gap-4">
            <p class="text-body">
                {resetTwoFactorTarget?.name ?? ""} さんの 2 段階認証を解除します。
                解除はこのアカウント全体に及び、本人へセキュリティ通知が送信されます。
            </p>
            <FormField
                label="理由 (10 文字以上。監査ログに記録されます)"
                id="reset-two-factor-reason"
                error={resetTwoFactorForm.errors.reason}
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="text"
                        bind:value={resetTwoFactorForm.reason}
                        error={invalid}
                        aria-describedby={describedBy}
                        autocomplete="off"
                    />
                {/snippet}
            </FormField>
            <div class="flex justify-end">
                <Button
                    type="submit"
                    variant="danger"
                    loading={resetTwoFactorForm.processing}
                    testId="reset-two-factor-submit"
                >
                    解除する
                </Button>
            </div>
        </form>
    </Modal>

    <RecentAuthModal
        bind:open={recentAuthOpen}
        passwordSet={recentAuthStatus?.passwordSet ?? false}
        availableProviders={recentAuthStatus?.availableProviders ?? []}
        canSatisfy={recentAuthStatus?.canSatisfy ?? true}
        onConfirmed={resumePendingAction}
    />
</AppLayout>
```

## ファイル: resources/js/pages/Admin/Categories.svelte
```
<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import { ChevronDown, ChevronUp } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AdminMenuNav from "@/components/features/admin/AdminMenuNav.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CategoryOption } from "@/types/manual";

    /**
     * 管理メニュー > カテゴリ管理 (doc/04 §4.2。モック PC_管理メニュー 10〜17)。
     * 追加・名称編集・削除・▲▼並べ替え。write は既存 projects.categories.* endpoint を使う
     * (バックエンド重複実装なし。同名 422・削除で未分類化・reorder 集合検証は既存のまま)。
     * Projects/Show から移設 (Show はカテゴリフィルタ select のみ残す)。
     */
    interface Props {
        project: { id: number; name: string };
        categories: CategoryOption[];
        usersUrl: string | null;
    }

    let { project, categories, usersUrl }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /* ---- カテゴリ追加 (モック 11〜13) ---- */
    const addCategoryForm = useForm({ name: "" });

    function submitAddCategory(event: SubmitEvent): void {
        event.preventDefault();
        if (addCategoryForm.processing) return; // 二重送信の冪等ガード
        addCategoryForm.post(`/projects/${project.id}/categories`, {
            preserveScroll: true,
            onSuccess: () => {
                addCategoryForm.reset();
            },
        });
    }

    /* ---- カテゴリ編集 (モック 14〜15) ---- */
    let editCategoryTarget = $state<CategoryOption | null>(null);
    let editCategoryModalOpen = $state(false);
    const editCategoryForm = useForm({ name: "" });

    function openEditCategoryModal(category: CategoryOption): void {
        editCategoryTarget = category;
        editCategoryForm.name = category.name;
        editCategoryForm.clearErrors();
        editCategoryModalOpen = true;
    }

    function submitEditCategory(event: SubmitEvent): void {
        event.preventDefault();
        if (editCategoryForm.processing) return; // 二重送信の冪等ガード
        if (editCategoryTarget === null) return;
        editCategoryForm.patch(`/projects/${project.id}/categories/${editCategoryTarget.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editCategoryModalOpen = false;
            },
        });
    }

    /* ---- カテゴリ削除 (モック 16〜17: 未分類化の警告) ---- */
    let removeCategoryTarget = $state<CategoryOption | null>(null);
    let removeCategoryDialogOpen = $state(false);
    let removingCategory = $state(false);

    function openRemoveCategoryDialog(category: CategoryOption): void {
        removeCategoryTarget = category;
        removeCategoryDialogOpen = true;
    }

    function removeCategory(): void {
        if (removeCategoryTarget === null || removingCategory) return;
        router.delete(`/projects/${project.id}/categories/${removeCategoryTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                removingCategory = true;
            },
            onFinish: () => {
                removingCategory = false;
                removeCategoryDialogOpen = false;
            },
        });
    }

    /* ---- 並べ替え (▲▼): index の要素を direction (±1) 方向へ入れ替えた id 順序配列を送る ---- */
    let reordering = $state(false);

    function moveCategory(index: number, direction: -1 | 1): void {
        const target = index + direction;
        if (target < 0 || target >= categories.length || reordering) return;
        const order = categories.map((category) => category.id);
        [order[index], order[target]] = [order[target], order[index]];
        reordering = true;
        router.patch(
            `/projects/${project.id}/categories/reorder`,
            { order },
            {
                preserveScroll: true,
                onFinish: () => {
                    reordering = false;
                },
            },
        );
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">カテゴリ管理</h1>
    <p class="mt-1 text-caption text-text-secondary">
        {project.name} の動画マニュアルの分類に使うカテゴリを管理します。削除したカテゴリの動画は未分類になります。
    </p>

    <div class="mt-6 flex flex-col gap-6 md:flex-row md:items-start">
        <aside class="w-full shrink-0 md:w-56">
            <AdminMenuNav
                active="categories"
                {usersUrl}
                categoriesUrl={`/projects/${project.id}/categories`}
            />
        </aside>

        <div class="flex min-w-0 grow flex-col gap-10">
            <Card padding="lg">
                <h2 class="text-h3">カテゴリを追加</h2>
                <form onsubmit={submitAddCategory} class="mt-4 flex items-start gap-2">
                    <div class="grow">
                        <FormField
                            label="カテゴリ名"
                            id="category-name"
                            error={addCategoryForm.errors.name}
                            required
                        >
                            {#snippet children({ id, describedBy, invalid })}
                                <Input
                                    {id}
                                    type="text"
                                    bind:value={addCategoryForm.name}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                />
                            {/snippet}
                        </FormField>
                    </div>
                    <div class="pt-6">
                        <Button
                            type="submit"
                            loading={addCategoryForm.processing}
                            testId="category-submit"
                        >
                            追加
                        </Button>
                    </div>
                </form>
            </Card>

            <Card padding="lg">
                <h2 class="text-h3">カテゴリ一覧</h2>
                {#if categories.length === 0}
                    <EmptyState
                        description="カテゴリはまだありません。追加すると動画マニュアルを分類できます。"
                        testId="categories-empty"
                    />
                {:else}
                    <ul
                        class="mt-4 flex flex-col divide-y divide-border"
                        data-testid="category-list"
                    >
                        {#each categories as category, index (category.id)}
                            <li class="flex items-center justify-between gap-4 py-3">
                                <p class="min-w-0 truncate text-body">{category.name}</p>
                                <div class="flex shrink-0 items-center gap-2">
                                    {#if index > 0}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            iconOnly
                                            ariaLabel={`「${category.name}」を上へ移動`}
                                            onclick={() => moveCategory(index, -1)}
                                            testId={`move-up-category-${category.id}`}
                                        >
                                            <ChevronUp class="size-4" aria-hidden="true" />
                                        </Button>
                                    {/if}
                                    {#if index < categories.length - 1}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            iconOnly
                                            ariaLabel={`「${category.name}」を下へ移動`}
                                            onclick={() => moveCategory(index, 1)}
                                            testId={`move-down-category-${category.id}`}
                                        >
                                            <ChevronDown class="size-4" aria-hidden="true" />
                                        </Button>
                                    {/if}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onclick={() => openEditCategoryModal(category)}
                                        testId={`edit-category-${category.id}`}
                                    >
                                        編集
                                    </Button>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRemoveCategoryDialog(category)}
                                        testId={`remove-category-${category.id}`}
                                    >
                                        削除
                                    </Button>
                                </div>
                            </li>
                        {/each}
                    </ul>
                {/if}
            </Card>
        </div>
    </div>

    <Modal
        bind:open={editCategoryModalOpen}
        title="カテゴリを編集"
        processing={editCategoryForm.processing}
        testId="edit-category-modal"
    >
        <form onsubmit={submitEditCategory} class="flex flex-col gap-4">
            <FormField
                label="カテゴリ名"
                id="edit-category-name"
                error={editCategoryForm.errors.name}
                required
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="text"
                        bind:value={editCategoryForm.name}
                        error={invalid}
                        aria-describedby={describedBy}
                    />
                {/snippet}
            </FormField>
            <div class="flex items-center justify-end gap-2">
                <Button
                    variant="ghost"
                    onclick={() => (editCategoryModalOpen = false)}
                    disabled={editCategoryForm.processing}
                >
                    キャンセル
                </Button>
                <Button
                    type="submit"
                    loading={editCategoryForm.processing}
                    testId="edit-category-submit"
                >
                    保存
                </Button>
            </div>
        </form>
    </Modal>

    <ConfirmDialog
        bind:open={removeCategoryDialogOpen}
        title="カテゴリ削除"
        message={`「${removeCategoryTarget?.name ?? ""}」を削除しますか？ このカテゴリの動画マニュアルは未分類になります。`}
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={removingCategory}
        onConfirm={removeCategory}
        testId="remove-category-dialog"
    />
</AppLayout>
```

## ファイル: resources/js/components/features/admin/AdminMenuNav.svelte
```
<script lang="ts">
    import { Tags, Users } from "@lucide/svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";

    /**
     * 管理メニューのサイドナビ (doc/04 §4.2。モック PC_管理メニュー 02/10)。
     * URL は null 許容必須指定 (optional にしない = undefined 混入を型で拒否)。
     * null の項目は非表示 (can 連動をサーバが URL null で表現。撮影者には項目が出ない)。
     */
    interface Props {
        active: "users" | "categories";
        usersUrl: string | null;
        categoriesUrl: string | null;
    }

    let { active, usersUrl, categoriesUrl }: Props = $props();
</script>

<Card padding="lg">
    <h2 class="text-h3">管理メニュー</h2>
    <nav aria-label="管理メニュー" class="mt-4">
        <ul class="flex flex-col gap-3">
            {#if usersUrl !== null}
                <li class="flex items-center gap-2">
                    <Users class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    {#if active === "users"}
                        <span class="text-body font-medium" aria-current="page">ユーザー管理</span>
                    {:else}
                        <TextLink href={usersUrl} testId="admin-nav-users">ユーザー管理</TextLink>
                    {/if}
                </li>
            {/if}
            {#if categoriesUrl !== null}
                <li class="flex items-center gap-2">
                    <Tags class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    {#if active === "categories"}
                        <span class="text-body font-medium" aria-current="page">カテゴリ管理</span>
                    {:else}
                        <TextLink href={categoriesUrl} testId="admin-nav-categories">
                            カテゴリ管理
                        </TextLink>
                    {/if}
                </li>
            {/if}
        </ul>
    </nav>
</Card>
```

## ファイル: database/migrations/2026_07_11_110000_add_project_role_to_organization_invitations_table.php
```
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            // 受諾時に Default Project へ付与する pivot ロール (ProjectRole 値)。
            // null = org 参加のみ (管理者招待 / 旧招待)。値はサーバが AdminConsoleRole から導出し、
            // クライアント payload からは受けない (forceFill 専用)
            $table->string('project_role')->nullable()->after('role');
        });
        // 許容値を DB 層でも固定 (手動更新・バッチ経由の不正値混入を構造的に拒否)
        DB::statement(
            'alter table organization_invitations add constraint organization_invitations_project_role_check '
            ."check (project_role is null or project_role in ('project_admin', 'project_member'))",
        );
    }

    public function down(): void
    {
        DB::statement('alter table organization_invitations drop constraint if exists organization_invitations_project_role_check');
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->dropColumn('project_role');
        });
    }
};
```

## 差分: 移設元 Svelte (git diff main)
```diff
diff --git a/app/Http/Controllers/Organizations/OrganizationController.php b/app/Http/Controllers/Organizations/OrganizationController.php
index f406ec3..eb295d7 100644
--- a/app/Http/Controllers/Organizations/OrganizationController.php
+++ b/app/Http/Controllers/Organizations/OrganizationController.php
@@ -8,12 +8,10 @@
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Organizations\UpdateOrganizationRequest;
 use App\Models\Organization;
-use App\Models\OrganizationInvitation;
 use App\Models\User;
 use App\Services\Organization\OrganizationProvisioningService;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
-use Illuminate\Support\Carbon;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Gate;
 use Illuminate\Validation\ValidationException;
@@ -56,7 +54,10 @@ public function store(Request $request, OrganizationProvisioningService $provisi
     }
 
     /**
-     * 組織設定画面 (name 編集 / メンバー一覧 / 招待一覧)。
+     * 組織設定画面 (name 編集 / 2FA 必須方針 / オーナー移譲)。
+     * メンバー管理 (一覧 / 招待 / ロール / 削除 / 2FA リセット) は管理メニュー > ユーザー管理
+     * (/manage/users) へ移設済み。members はオーナー移譲 select 用の最小 shape (id/name) のみ
+     * (email / 2FA を出さない = PII 最小化)。
      * API キー / 接続セッションは専用画面 (ApiKeys/Index, ApiKeys/Sessions) に分離し、
      * ここからはリンク導線 (canManageApiKeys) のみ出す。
      */
@@ -69,37 +70,15 @@ public function settings(
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
+        // オーナー移譲の移譲先 select 用途のみ (最小 props。email / 2FA は Admin/Users が担う)
         $members = $organization->users()->get()
             ->map(fn (User $member): array => [
                 'id' => $member->id,
                 'name' => $member->name,
-                'email' => $member->email,
-                'role' => $member->organizationRole($organization)?->value,
-                // 2FA 状態 (管理者のリセット導線・必須方針の準拠確認に使う)
-                'twoFactorStatus' => $member->twoFactorStatus()->value,
             ])
             ->values()
             ->all();
 
-        // 有効な (未失効・未受諾の) 招待のみ表示する
-        $invitations = $organization->invitations()
-            ->whereNull('accepted_at')
-            ->where('expires_at', '>', now())
-            ->get()
-            ->map(function (OrganizationInvitation $invitation): array {
-                $expiresAt = $invitation->getAttribute('expires_at');
-                Assert::isInstanceOf($expiresAt, Carbon::class);
-
-                return [
-                    'id' => $invitation->id,
-                    'email' => $invitation->email,
-                    'role' => $invitation->role,
-                    'expiresAt' => $expiresAt->toDateString(),
-                ];
-            })
-            ->values()
-            ->all();
-
         return Inertia::render('Organizations/Settings', [
             'organization' => [
                 'id' => $organization->id,
@@ -109,10 +88,11 @@ public function settings(
                 'twoFactorRequired' => $organization->two_factor_required,
             ],
             'members' => $members,
-            'invitations' => $invitations,
             'currentUserRole' => $user->organizationRole($organization)?->value,
             // API キー / 接続セッション管理画面への導線を出すか (境界は manageApiKeys と同一)
             'canManageApiKeys' => Gate::allows('manageApiKeys', $organization),
+            // ユーザー管理画面 (管理メニュー) への導線 (can 連動。URL は route helper で生成)
+            'usersUrl' => Gate::allows('manageMembers', $organization) ? route('manage.users.index') : null,
         ]);
     }
 
diff --git a/app/Http/Controllers/Organizations/OrganizationInvitationController.php b/app/Http/Controllers/Organizations/OrganizationInvitationController.php
index 9ea613d..480d5be 100644
--- a/app/Http/Controllers/Organizations/OrganizationInvitationController.php
+++ b/app/Http/Controllers/Organizations/OrganizationInvitationController.php
@@ -4,8 +4,8 @@
 
 namespace App\Http\Controllers\Organizations;
 
-use App\Enums\OrganizationRole;
 use App\Http\Controllers\Controller;
+use App\Http\Requests\Organizations\StoreOrganizationInvitationRequest;
 use App\Models\Organization;
 use App\Models\OrganizationInvitation;
 use App\Models\User;
@@ -13,37 +13,23 @@
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
-use Illuminate\Validation\Rule;
 use Webmozart\Assert\Assert;
 
 /**
- * メンバー招待の送信。
+ * メンバー招待の送信 (3 値遷移コマンド: admin/editor/shooter)。
  * 応答は back + success flash で完結する (画面遷移しない。
  * devnotes/20260611-template-extraction/14 §4: 操作系 POST で intended を使わない)。
  */
 class OrganizationInvitationController extends Controller
 {
-    public function store(Request $request, Organization $organization, OrganizationMembershipService $membership): RedirectResponse
+    public function store(StoreOrganizationInvitationRequest $request, Organization $organization, OrganizationMembershipService $membership): RedirectResponse
     {
         Gate::authorize('manageMembers', $organization);
 
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
-        $request->validate([
-            'email' => ['required', 'string', 'email', 'max:255'],
-            // Owner 招待は不可 (Owner は transferOwnership のみが正規経路)
-            'role' => ['required', 'string', Rule::in([
-                OrganizationRole::Admin->value,
-                OrganizationRole::Member->value,
-            ])],
-        ]);
-        $email = $request->input('email');
-        $role = $request->input('role');
-        Assert::string($email);
-        Assert::string($role);
-
-        $membership->inviteMember($organization, $user, $email, OrganizationRole::from($role));
+        $membership->inviteMember($organization, $user, $request->email(), $request->role());
 
         return back()->with('success', '招待メールを送信しました');
     }
diff --git a/app/Http/Controllers/Organizations/OrganizationMemberController.php b/app/Http/Controllers/Organizations/OrganizationMemberController.php
index 212b8e0..875d0c9 100644
--- a/app/Http/Controllers/Organizations/OrganizationMemberController.php
+++ b/app/Http/Controllers/Organizations/OrganizationMemberController.php
@@ -8,6 +8,7 @@
 use App\Enums\SecurityEventType;
 use App\Enums\TwoFactorStatus;
 use App\Http\Controllers\Controller;
+use App\Http\Requests\Organizations\UpdateOrganizationMemberRoleRequest;
 use App\Models\Organization;
 use App\Models\User;
 use App\Notifications\User\TwoFactorResetSecurityNotification;
@@ -17,7 +18,6 @@
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Gate;
-use Illuminate\Validation\Rule;
 use Illuminate\Validation\ValidationException;
 use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
 use Webmozart\Assert\Assert;
@@ -29,23 +29,14 @@
  */
 class OrganizationMemberController extends Controller
 {
-    public function update(Request $request, Organization $organization, User $user, OrganizationMembershipService $membership): RedirectResponse
+    public function update(UpdateOrganizationMemberRoleRequest $request, Organization $organization, User $user, OrganizationMembershipService $membership): RedirectResponse
     {
         // URL 整合 guard: 認可より前に 404 (cross-tenant の存在を漏らさない)
         $this->resolveOrganizationMember($organization, $user);
         Gate::authorize('manageMembers', $organization);
 
-        $request->validate([
-            // Owner への昇格は transferOwnership のみ (ここでは指定不可)
-            'role' => ['required', 'string', Rule::in([
-                OrganizationRole::Admin->value,
-                OrganizationRole::Member->value,
-            ])],
-        ]);
-        $role = $request->input('role');
-        Assert::string($role);
-
-        $membership->changeRole($organization, $user, OrganizationRole::from($role));
+        // 3 値遷移コマンド (admin/editor/shooter)。Owner 指定は enum 外 = 構造的に不可能
+        $membership->applyConsoleRole($organization, $user, $request->role());
 
         return back()->with('success', 'ロールを変更しました');
     }
diff --git a/resources/js/pages/Organizations/Settings.svelte b/resources/js/pages/Organizations/Settings.svelte
index 4471d69..1a9e0c8 100644
--- a/resources/js/pages/Organizations/Settings.svelte
+++ b/resources/js/pages/Organizations/Settings.svelte
@@ -1,5 +1,5 @@
 <script lang="ts">
-    import { page, router, useForm } from "@inertiajs/svelte";
+    import { page, useForm } from "@inertiajs/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -7,28 +7,21 @@
     import Select from "@/components/atoms/Select.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
     import DangerZone from "@/components/molecules/DangerZone.svelte";
-    import EmptyState from "@/components/molecules/EmptyState.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
-    import Modal from "@/components/organisms/Modal.svelte";
     import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
 
+    /**
+     * 組織設定 (名称 / 2FA 必須方針 / API キー導線 / オーナー移譲)。
+     * メンバー管理 (一覧・招待・ロール・削除・2FA リセット) は管理メニュー > ユーザー管理
+     * (/manage/users) へ移設済み。members はオーナー移譲 select 用の最小 shape (id/name)。
+     */
     interface Member {
         id: number;
         name: string;
-        email: string;
-        role: string | null;
-        twoFactorStatus: "disabled" | "pending" | "enabled";
-    }
-
-    interface Invitation {
-        id: number;
-        email: string;
-        role: string;
-        expiresAt: string | null;
     }
 
     interface Props {
@@ -40,19 +33,14 @@
             twoFactorRequired: boolean;
         };
         members: Member[];
-        invitations: Invitation[];
         currentUserRole: string | null;
         /** API キー / 接続セッション管理画面への導線を出すか (境界は manageApiKeys と同一) */
         canManageApiKeys: boolean;
+        /** ユーザー管理画面 (管理メニュー) への導線 URL (manageMembers 権限なしは null = 非表示) */
+        usersUrl: string | null;
     }
 
-    let {
-        organization,
-        members,
-        invitations,
-        currentUserRole,
-        canManageApiKeys,
-    }: Props = $props();
+    let { organization, members, currentUserRole, canManageApiKeys, usersUrl }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -60,13 +48,6 @@
 
     const OWNER = "organization_owner";
     const ADMIN = "organization_admin";
-    const MEMBER = "organization_member";
-
-    const ROLE_LABELS: Record<string, string> = {
-        [OWNER]: "オーナー",
-        [ADMIN]: "管理者",
-        [MEMBER]: "メンバー",
-    };
 
     const canManage = $derived(currentUserRole === OWNER || currentUserRole === ADMIN);
     const isOwner = $derived(currentUserRole === OWNER);
@@ -81,53 +62,6 @@
         nameForm.patch(`/organizations/${organization.slug}`, { preserveScroll: true });
     }
 
-    /* ---- 招待 ---- */
-    const inviteForm = useForm({ email: "", role: MEMBER });
-
-    function submitInvite(event: SubmitEvent): void {
-        event.preventDefault();
-        inviteForm.post(`/organizations/${organization.slug}/invitations`, {
-            preserveScroll: true,
-            onSuccess: () => {
-                inviteForm.reset();
-            },
-        });
-    }
-
-    /* ---- ロール変更 ----
-       Owner への昇格・Owner の降格はこの UI からは行えない (オーナー移譲のみが正規経路)。 */
-    function changeRole(member: Member, role: string): void {
-        router.patch(
-            `/organizations/${organization.slug}/members/${member.id}`,
-            { role },
-            { preserveScroll: true },
-        );
-    }
-
-    /* ---- メンバー削除 ---- */
-    let removeTarget = $state<Member | null>(null);
-    let removeDialogOpen = $state(false);
-    let removing = $state(false);
-
-    function openRemoveDialog(member: Member): void {
-        removeTarget = member;
-        removeDialogOpen = true;
-    }
-
-    function removeMember(): void {
-        if (removeTarget === null) return;
-        router.delete(`/organizations/${organization.slug}/members/${removeTarget.id}`, {
-            preserveScroll: true,
-            onStart: () => {
-                removing = true;
-            },
-            onFinish: () => {
-                removing = false;
-                removeDialogOpen = false;
-            },
-        });
-    }
-
     /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
     let recentAuthOpen = $state(false);
     let recentAuthStatus = $state<RecentAuthStatus | null>(null);
@@ -180,10 +114,6 @@
         });
     }
 
-    function roleLabel(role: string | null): string {
-        return role === null ? "—" : (ROLE_LABELS[role] ?? role);
-    }
-
     /* ---- 組織の 2FA 必須方針 (Owner 専権。recent-auth 必須) ----
        押下前の precheck はしない (未準拠 Owner の有効化はサーバが validation error で拒否し、
        ここに表示する)。 */
@@ -198,49 +128,12 @@
             );
         });
     }
-
-    /* ---- メンバー 2FA リセット (Owner/Admin。recent-auth + 理由必須) ---- */
-    let resetTwoFactorTarget = $state<Member | null>(null);
-    let resetTwoFactorModalOpen = $state(false);
-    const resetTwoFactorForm = useForm({ reason: "" });
-
-    function openResetTwoFactorModal(member: Member): void {
-        resetTwoFactorTarget = member;
-        resetTwoFactorForm.reset();
-        resetTwoFactorForm.clearErrors();
-        resetTwoFactorModalOpen = true;
-    }
-
-    function submitResetTwoFactor(event: SubmitEvent): void {
-        event.preventDefault();
-        const target = resetTwoFactorTarget;
-        if (target === null) return;
-        guardWithRecentAuth(() => {
-            resetTwoFactorForm.delete(
-                `/organizations/${organization.slug}/members/${target.id}/two-factor`,
-                {
-                    preserveScroll: true,
-                    onSuccess: () => {
-                        resetTwoFactorModalOpen = false;
-                    },
-                },
-            );
-        });
-    }
-
-    /** 2FA リセットを提示できる対象か (自分以外 + 設定済み + Admin は Member のみ対象にできる) */
-    function canResetTwoFactor(member: Member): boolean {
-        if (!canManage || member.id === myId || member.twoFactorStatus === "disabled") {
-            return false;
-        }
-        return isOwner || member.role === MEMBER;
-    }
 </script>
 
 <AppLayout {appName}>
     <h1 class="text-h2">組織設定</h1>
     <p class="mt-1 text-caption text-text-secondary">
-        {organization.name} のメンバーと設定を管理します。
+        {organization.name} の設定を管理します。
     </p>
 
     <div class="mt-6 flex max-w-2xl flex-col gap-10">
@@ -299,129 +192,19 @@
             </Card>
         {/if}
 
-        <Card padding="lg">
-            <h2 class="text-h3">メンバー</h2>
-            <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
-                {#each members as member (member.id)}
-                    <li class="flex items-center justify-between gap-4 py-3">
-                        <div class="min-w-0">
-                            <div class="flex items-center gap-2">
-                                <p class="truncate text-body">{member.name}</p>
-                                {#if member.twoFactorStatus === "enabled"}
-                                    <Badge tone="success">2FA</Badge>
-                                {/if}
-                            </div>
-                            <p class="truncate text-caption text-text-secondary">
-                                {member.email}
-                            </p>
-                        </div>
-                        <div class="flex shrink-0 items-center gap-2">
-                            {#if canResetTwoFactor(member)}
-                                <Button
-                                    variant="danger-ghost"
-                                    size="sm"
-                                    onclick={() => openResetTwoFactorModal(member)}
-                                    testId={`reset-two-factor-${member.id}`}
-                                >
-                                    2FA 解除
-                                </Button>
-                            {/if}
-                            {#if canManage && member.role !== OWNER && member.id !== myId}
-                                <Select
-                                    value={member.role ?? MEMBER}
-                                    aria-label={`${member.name} のロール`}
-                                    onchange={(event) =>
-                                        changeRole(member, event.currentTarget.value)}
-                                    testId={`member-role-${member.id}`}
-                                >
-                                    <option value={ADMIN}>管理者</option>
-                                    <option value={MEMBER}>メンバー</option>
-                                </Select>
-                                <Button
-                                    variant="danger-ghost"
-                                    size="sm"
-                                    onclick={() => openRemoveDialog(member)}
-                                    testId={`remove-member-${member.id}`}
-                                >
-                                    削除
-                                </Button>
-                            {:else}
-                                <span class="text-caption text-text-secondary">
-                                    {roleLabel(member.role)}
-                                </span>
-                            {/if}
-                        </div>
-                    </li>
-                {/each}
-            </ul>
-        </Card>
-
-        {#if canManage}
+        {#if usersUrl !== null}
             <Card padding="lg">
-                <h2 class="text-h3">メンバーを招待</h2>
-                <p class="mt-1 text-caption text-text-secondary">
-                    招待メールを送信します。招待の有効期限は 7 日間です。
-                </p>
-                <form onsubmit={submitInvite} class="mt-4 flex flex-col gap-4">
-                    <FormField
-                        label="メールアドレス"
-                        id="invite-email"
-                        error={inviteForm.errors.email}
-                    >
-                        {#snippet children({ id, describedBy, invalid })}
-                            <Input
-                                {id}
-                                type="email"
-                                bind:value={inviteForm.email}
-                                error={invalid}
-                                aria-describedby={describedBy}
-                                autocomplete="off"
-                            />
-                        {/snippet}
-                    </FormField>
-                    <FormField label="ロール" id="invite-role" error={inviteForm.errors.role}>
-                        {#snippet children({ id, describedBy, invalid })}
-                            <Select
-                                {id}
-                                bind:value={inviteForm.role}
-                                error={invalid}
-                                aria-describedby={describedBy}
-                            >
-                                <option value={ADMIN}>管理者</option>
-                                <option value={MEMBER}>メンバー</option>
-                            </Select>
-                        {/snippet}
-                    </FormField>
+                <div class="flex items-start justify-between gap-4">
                     <div>
-                        <Button
-                            type="submit"
-                            loading={inviteForm.processing}
-                            testId="invite-submit"
-                        >
-                            招待を送信
-                        </Button>
+                        <h2 class="text-h3">ユーザー管理</h2>
+                        <p class="mt-1 text-caption text-text-secondary">
+                            メンバーの一覧・招待・ロール変更・削除は管理メニューのユーザー管理画面で行います。
+                        </p>
                     </div>
-                </form>
-
-                <h3 class="mt-8 text-caption font-medium text-text">送信済みの招待</h3>
-                {#if invitations.length === 0}
-                    <EmptyState
-                        title="有効な招待はありません"
-                        description="送信した招待は受諾されるか期限が切れるまでここに表示されます。"
-                    />
-                {:else}
-                    <ul class="mt-2 flex flex-col divide-y divide-border">
-                        {#each invitations as invitation (invitation.id)}
-                            <li class="flex items-center justify-between gap-4 py-3">
-                                <p class="truncate text-body">{invitation.email}</p>
-                                <p class="shrink-0 text-caption text-text-secondary">
-                                    {roleLabel(invitation.role)} ・ 期限 {invitation.expiresAt ??
-                                        "—"}
-                                </p>
-                            </li>
-                        {/each}
-                    </ul>
-                {/if}
+                    <TextLink href={usersUrl} testId="link-manage-users">
+                        管理画面を開く
+                    </TextLink>
+                </div>
             </Card>
         {/if}
 
@@ -485,17 +268,6 @@
         {/if}
     </div>
 
-    <ConfirmDialog
-        bind:open={removeDialogOpen}
-        title="メンバー削除"
-        message={`${removeTarget?.name ?? ""} さんをこの組織から削除しますか？ この操作は取り消せません。`}
-        confirmLabel="削除する"
-        confirmVariant="danger"
-        processing={removing}
-        onConfirm={removeMember}
-        testId="remove-member-dialog"
-    />
-
     <ConfirmDialog
         bind:open={transferDialogOpen}
         title="オーナー移譲"
@@ -507,45 +279,6 @@
         testId="transfer-ownership-dialog"
     />
 
-    <Modal
-        bind:open={resetTwoFactorModalOpen}
-        title="メンバーの 2FA を解除"
-        testId="reset-two-factor-modal"
-    >
-        <form onsubmit={submitResetTwoFactor} class="flex flex-col gap-4">
-            <p class="text-body">
-                {resetTwoFactorTarget?.name ?? ""} さんの 2 段階認証を解除します。
-                解除はこのアカウント全体に及び、本人へセキュリティ通知が送信されます。
-            </p>
-            <FormField
-                label="理由 (10 文字以上。監査ログに記録されます)"
-                id="reset-two-factor-reason"
-                error={resetTwoFactorForm.errors.reason}
-            >
-                {#snippet children({ id, describedBy, invalid })}
-                    <Input
-                        {id}
-                        type="text"
-                        bind:value={resetTwoFactorForm.reason}
-                        error={invalid}
-                        aria-describedby={describedBy}
-                        autocomplete="off"
-                    />
-                {/snippet}
-            </FormField>
-            <div class="flex justify-end">
-                <Button
-                    type="submit"
-                    variant="danger"
-                    loading={resetTwoFactorForm.processing}
-                    testId="reset-two-factor-submit"
-                >
-                    解除する
-                </Button>
-            </div>
-        </form>
-    </Modal>
-
     <RecentAuthModal
         bind:open={recentAuthOpen}
         passwordSet={recentAuthStatus?.passwordSet ?? false}
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index ea14828..9fe9e90 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -1,6 +1,5 @@
 <script lang="ts">
     import { page, router, useForm } from "@inertiajs/svelte";
-    import { ChevronDown, ChevronUp } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -39,12 +38,15 @@
         project: { id: number; name: string; description: string | null };
         items: Item[];
         canManage: boolean;
+        /** 管理メニュー > ユーザー管理への導線を出すか (org owner/admin。単一根拠は Gate) */
+        canManageMembers: boolean;
         manuals: { data: ManualListItem[]; meta: PaginationMeta };
         categories: CategoryOption[];
         manualFilters: ManualFilters;
     }
 
-    let { project, items, canManage, manuals, categories, manualFilters }: Props = $props();
+    let { project, items, canManage, canManageMembers, manuals, categories, manualFilters }: Props =
+        $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -92,77 +94,6 @@
         });
     }
 
-    /* ---- カテゴリ管理 ---- */
-    const addCategoryForm = useForm({ name: "" });
-
-    function submitAddCategory(event: SubmitEvent): void {
-        event.preventDefault();
-        addCategoryForm.post(`/projects/${project.id}/categories`, {
-            preserveScroll: true,
-            onSuccess: () => {
-                addCategoryForm.reset();
-            },
-        });
-    }
-
-    let editCategoryTarget = $state<CategoryOption | null>(null);
-    let editCategoryModalOpen = $state(false);
-    const editCategoryForm = useForm({ name: "" });
-
-    function openEditCategoryModal(category: CategoryOption): void {
-        editCategoryTarget = category;
-        editCategoryForm.name = category.name;
-        editCategoryForm.clearErrors();
-        editCategoryModalOpen = true;
-    }
-
-    function submitEditCategory(event: SubmitEvent): void {
-        event.preventDefault();
-        if (editCategoryTarget === null) return;
-        editCategoryForm.patch(`/projects/${project.id}/categories/${editCategoryTarget.id}`, {
-            preserveScroll: true,
-            onSuccess: () => {
-                editCategoryModalOpen = false;
-            },
-        });
-    }
-
-    let removeCategoryTarget = $state<CategoryOption | null>(null);
-    let removeCategoryDialogOpen = $state(false);
-    let removingCategory = $state(false);
-
-    function openRemoveCategoryDialog(category: CategoryOption): void {
-        removeCategoryTarget = category;
-        removeCategoryDialogOpen = true;
-    }
-
-    function removeCategory(): void {
-        if (removeCategoryTarget === null) return;
-        router.delete(`/projects/${project.id}/categories/${removeCategoryTarget.id}`, {
-            preserveScroll: true,
-            onStart: () => {
-                removingCategory = true;
-            },
-            onFinish: () => {
-                removingCategory = false;
-                removeCategoryDialogOpen = false;
-            },
-        });
-    }
-
-    /** 並べ替え: index の要素を direction (±1) 方向へ入れ替えた id 順序配列を送る */
-    function moveCategory(index: number, direction: -1 | 1): void {
-        const target = index + direction;
-        if (target < 0 || target >= categories.length) return;
-        const order = categories.map((category) => category.id);
-        [order[index], order[target]] = [order[target], order[index]];
-        router.patch(
-            `/projects/${project.id}/categories/reorder`,
-            { order },
-            { preserveScroll: true },
-        );
-    }
-
     /* ---- Item 追加 ---- */
     const addForm = useForm({ name: "", note: "" });
 
@@ -370,96 +301,31 @@
             {/if}
         </Card>
 
-        {#if canManage}
+        {#if canManage || canManageMembers}
             <Card padding="lg">
-                <h2 class="text-h3">カテゴリ管理</h2>
+                <h2 class="text-h3">管理メニュー</h2>
                 <p class="mt-1 text-caption text-text-secondary">
-                    動画マニュアルの分類に使うカテゴリを管理します。削除したカテゴリの動画は未分類になります。
+                    管理者向けの管理画面への導線です。権限のある項目のみ表示されます。
                 </p>
-                {#if categories.length === 0}
-                    <EmptyState
-                        description="カテゴリはまだありません。追加すると動画マニュアルを分類できます。"
-                        testId="categories-empty"
-                    />
-                {:else}
-                    <ul
-                        class="mt-4 flex flex-col divide-y divide-border"
-                        data-testid="category-list"
-                    >
-                        {#each categories as category, index (category.id)}
-                            <li class="flex items-center justify-between gap-4 py-3">
-                                <p class="min-w-0 truncate text-body">{category.name}</p>
-                                <div class="flex shrink-0 items-center gap-2">
-                                    <Button
-                                        variant="ghost"
-                                        size="sm"
-                                        iconOnly
-                                        ariaLabel={`「${category.name}」を上へ移動`}
-                                        onclick={() => moveCategory(index, -1)}
-                                        testId={`move-up-category-${category.id}`}
-                                    >
-                                        <ChevronUp class="size-4" aria-hidden="true" />
-                                    </Button>
-                                    <Button
-                                        variant="ghost"
-                                        size="sm"
-                                        iconOnly
-                                        ariaLabel={`「${category.name}」を下へ移動`}
-                                        onclick={() => moveCategory(index, 1)}
-                                        testId={`move-down-category-${category.id}`}
-                                    >
-                                        <ChevronDown class="size-4" aria-hidden="true" />
-                                    </Button>
-                                    <Button
-                                        variant="ghost"
-                                        size="sm"
-                                        onclick={() => openEditCategoryModal(category)}
-                                        testId={`edit-category-${category.id}`}
-                                    >
-                                        編集
-                                    </Button>
-                                    <Button
-                                        variant="danger-ghost"
-                                        size="sm"
-                                        onclick={() => openRemoveCategoryDialog(category)}
-                                        testId={`remove-category-${category.id}`}
-                                    >
-                                        削除
-                                    </Button>
-                                </div>
-                            </li>
-                        {/each}
-                    </ul>
-                {/if}
-                <form onsubmit={submitAddCategory} class="mt-4 flex items-start gap-2">
-                    <div class="grow">
-                        <FormField
-                            label="カテゴリ名"
-                            id="category-name"
-                            error={addCategoryForm.errors.name}
-                            required
-                        >
-                            {#snippet children({ id, describedBy, invalid })}
-                                <Input
-                                    {id}
-                                    type="text"
-                                    bind:value={addCategoryForm.name}
-                                    error={invalid}
-                                    aria-describedby={describedBy}
-                                />
-                            {/snippet}
-                        </FormField>
-                    </div>
-                    <div class="pt-6">
-                        <Button
-                            type="submit"
-                            loading={addCategoryForm.processing}
-                            testId="category-submit"
-                        >
-                            追加
-                        </Button>
-                    </div>
-                </form>
+                <ul class="mt-4 flex flex-col gap-2">
+                    {#if canManage}
+                        <li>
+                            <TextLink
+                                href={`/projects/${project.id}/categories`}
+                                testId="link-manage-categories"
+                            >
+                                カテゴリ管理
+                            </TextLink>
+                        </li>
+                    {/if}
+                    {#if canManageMembers}
+                        <li>
+                            <TextLink href="/manage/users" testId="link-manage-users">
+                                ユーザー管理
+                            </TextLink>
+                        </li>
+                    {/if}
+                </ul>
             </Card>
         {/if}
 
@@ -558,59 +424,6 @@
         {/if}
     </div>
 
-    <Modal
-        bind:open={editCategoryModalOpen}
-        title="カテゴリを編集"
-        processing={editCategoryForm.processing}
-        testId="edit-category-modal"
-    >
-        <form onsubmit={submitEditCategory} class="flex flex-col gap-4">
-            <FormField
-                label="カテゴリ名"
-                id="edit-category-name"
-                error={editCategoryForm.errors.name}
-                required
-            >
-                {#snippet children({ id, describedBy, invalid })}
-                    <Input
-                        {id}
-                        type="text"
-                        bind:value={editCategoryForm.name}
-                        error={invalid}
-                        aria-describedby={describedBy}
-                    />
-                {/snippet}
-            </FormField>
-            <div class="flex items-center justify-end gap-2">
-                <Button
-                    variant="ghost"
-                    onclick={() => (editCategoryModalOpen = false)}
-                    disabled={editCategoryForm.processing}
-                >
-                    キャンセル
-                </Button>
-                <Button
-                    type="submit"
-                    loading={editCategoryForm.processing}
-                    testId="edit-category-submit"
-                >
-                    保存
-                </Button>
-            </div>
-        </form>
-    </Modal>
-
-    <ConfirmDialog
-        bind:open={removeCategoryDialogOpen}
-        title="カテゴリ削除"
-        message={`「${removeCategoryTarget?.name ?? ""}」を削除しますか？ このカテゴリの動画マニュアルは未分類になります。`}
-        confirmLabel="削除する"
-        confirmVariant="danger"
-        processing={removingCategory}
-        onConfirm={removeCategory}
-        testId="remove-category-dialog"
-    />
-
     <Modal
         bind:open={editModalOpen}
         title="アイテムを編集"
```

---

# 再掲: レビュー観点 (この順に判定せよ)

1. **セキュリティ不変条件**: 管理メニューは admin/owner 専用画面。認可漏れ (member が /manage 配下へ到達できる経路、laratrust_team_id 未明示の権限判定、payload からの tenant/role キー受け取り) がないか。特に UpdateOrganizationMemberRoleRequest / StoreOrganizationInvitationRequest の認可と、招待への project_role 追加が cross-org・権限昇格の穴を開けていないか。
2. **ロール遷移の整合**: MemberRoleState / AdminConsoleRole の遷移ルール (最後の owner 降格禁止、自分自身の降格・削除の扱い等) に論理穴がないか。ConsoleRoleTransitionTest がその不変条件を実際に固定しているか (タウトロジーでないか)。
3. **Architecture テストの実効性**: ManageRouteAuthGuardTest / ProjectMemberPivotWritePathTest が「新規ルート・新規書き込み経路の追加時に fail する」deny-by-default 走査になっているか。
4. **フロント再編の退行**: Organizations/Settings.svelte と Projects/Show.svelte からの機能移設で、既存機能 (メンバー招待・カテゴリ CRUD) の導線やエラー表示が失われていないか。DESIGN.md 規約 (disabled ボタン禁止等) 違反がないか。
5. **マージ阻害の Critical のみを Critical とせよ**。好み・軽微なスタイルは Suggestion に落とすこと。

# 再掲: 出力形式

```
## 総評
(2-4 文)

## Critical
(なければ「なし」)

## Warning
(なければ「なし」)

## Suggestion
(任意)
```

各指摘は「ファイル:該当箇所 / 問題 / 修正案」の形で書くこと。全検証コマンド (composer test 1373 passed / phpstan / pint / eslint / tsc / vitest 380 passed / build) は green 済みである。
