<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\Fortify\EnumerationSafePasswordResetLinkResponse;
use App\Http\Responses\Fortify\LoginResponse;
use App\Http\Responses\Fortify\LogoutResponse;
use App\Http\Responses\Fortify\PasswordResetResponse;
use App\Http\Responses\Fortify\PasswordUpdatedResponse;
use App\Http\Responses\Fortify\ProfileUpdatedResponse;
use App\Http\Responses\Fortify\RecoveryCodesGeneratedResponse;
use App\Http\Responses\Fortify\RegisterResponse;
use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
use App\Http\Responses\Fortify\VerificationNotificationSentResponse;
use App\Http\Responses\Fortify\VerifyEmailResponse;
use App\Models\User;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Services\Organization\OrganizationMembershipService;
use App\Support\Auth\EmailVerificationContinuation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse as RecoveryCodesGeneratedResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
     * いずれも「確立済み第二要素の bypass / 除去」経路であり、通常セッション認証だけで
     * 到達させない (姉妹操作: organizations.members.two-factor.reset /
     * settings.account.destroy 等と同基準)。
     * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
     * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
     *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
     *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
     *     self-disable が許可される非 enforced 組織のユーザー。
     * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
     *
     * @var list<string>
     */
    private const RECENT_AUTH_ROUTE_NAMES = [
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'two-factor.disable',
    ];

    /**
     * email 変更時のみ recent-auth を課す条件付き付与 (氏名のみ変更は素通し)。
     * profile 更新は Fortify 登録ルートのため booted で後付けする。
     *
     * @var array<string, string> route name => middleware alias
     */
    private const CONDITIONAL_RECENT_AUTH_ROUTES = [
        'user-profile-information.update' => 'recent-auth.on-email-change',
    ];

    public function register(): void
    {
        // Fortify Response contract の差し替え (redirect + flash の Inertia 整合化)。
        // 挙動の意図は各 Response クラスの docblock を参照。
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        // verify 完了着地: continuation があれば onboarding.checkout、無ければ Fortify 既定と同値。
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
        $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
        $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
        // profile / password 更新は success flash に統一し保存完了を toast 化する
        // (status キーは flash-to-toast が gating するため toast にならない)。
        $this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileUpdatedResponse::class);
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdatedResponse::class);
        // password reset は Fortify が constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(PasswordResetResponseContract::class, PasswordResetResponse::class);
        // forgot-password は成功/失敗の両契約を enumeration-safe な同一応答へ差し替える。
        // Fortify は constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
        // ログアウト着地で Inertia::clearHistory() を発火させる (bug-hunt F-4-01)。
        // 着地 route を固定する理由と順序の前提は LogoutResponse の docblock を参照。
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->configureRateLimiters();
        $this->configureViews();
        $this->attachRecentAuthToSensitiveRoutes();
    }

    /**
     * Fortify が登録する機微な 2FA 管理ルートへ recent-auth middleware を後付けする。
     *
     * Fortify 標準の password.confirm は generic recent-auth へ置換済み
     * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
     * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
     * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決して append する。route:cache 下でも
     * CompiledRouteCollection::getByName() が nameCache に memoize した同一 instance を
     * match() が返すため、この変更は dispatch にも有効。
     */
    private function attachRecentAuthToSensitiveRoutes(): void
    {
        $this->app->booted(static function (Application $app): void {
            $routes = $app->make(Router::class)->getRoutes();
            // fluent な ->name() 付与はコレクションの name index に遅延反映のため明示 refresh
            $routes->refreshNameLookups();

            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
                self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
            }

            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
                self::appendMiddlewareIfMissing($routes, $name, $alias);
            }
        });
    }

    /**
     * named route に middleware alias を idempotent に append する (未登録時のみ)。
     *
     * booted callback (static クロージャ) から呼ぶため **static** で定義し
     * `self::appendMiddlewareIfMissing(...)` で呼ぶ。長寿命プロセス等で callback が
     * 同一 Route instance に複数回届いても重複付与しない (idempotent)。
     */
    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $username = $request->input(Fortify::username());
            $throttleKey = Str::transliterate(
                Str::lower(is_string($username) ? $username : '').'|'.$request->ip(),
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $loginId = $request->session()->get('login.id');

            return Limit::perMinute(5)->by(is_scalar($loginId) ? (string) $loginId : $request->ip().'|2fa');
        });
    }

    /**
     * 全 Fortify GET ルートを Inertia ページに接続する。
     */
    private function configureViews(): void
    {
        Fortify::loginView(static fn (): InertiaResponse => Inertia::render('Auth/Login', [
            'socialProviders' => array_keys(config()->array('template.social_providers')),
        ]));

        Fortify::registerView(static function (Request $request): SymfonyResponse {
            // 招待リンク経由 (session に active token) の場合のみ招待先 email を prefill 用に解決する。
            // resolver 内で stale/invalid token は session から破棄される (fail-secure)。
            $invitationEmail = app(OrganizationMembershipService::class)
                ->resolveRegisterPrefillEmail($request->session());

            $response = Inertia::render('Auth/Register', [
                'socialProviders' => array_keys(config()->array('template.social_providers')),
                'invitationEmail' => $invitationEmail,
                // 料金表 → /register?plan={code} のプラン意図。ユーザー入力のため
                // resolver の allowlist 照合に一本化する (Provider 側で分岐を書かない)。
                // 未知値 / 配列 / Enterprise はすべて null (= 意図なし) に倒れる。
                'intendedPlan' => IntendedPlanResolver::normalizeRaw($request->query('plan'))?->value,
            ])->toResponse($request);

            // PII (招待先 email) を含む応答を HTTP キャッシュ (共有/中間プロキシ/ブラウザの
            // HTTP キャッシュ) に保存させない (bearer token 由来 PII の運用 fail-safe)。
            // email を含まない通常登録応答には付けない (不要なキャッシュ抑止を避ける)。
            // 「PII 実在 = 非空 email 文字列」で判定する (resolver 契約と frontend の isInvited
            //  = invitationEmail != null && !== "" に揃え、null 判定だけの暗黙契約に依存しない)。
            if ($invitationEmail !== null && $invitationEmail !== '') {
                $response->headers->set('Cache-Control', 'no-store');
            }

            return $response;
        });

        Fortify::requestPasswordResetLinkView(
            static fn (): InertiaResponse => Inertia::render('Auth/ForgotPassword'),
        );

        Fortify::resetPasswordView(static function (Request $request): InertiaResponse {
            $token = $request->route('token');
            $email = $request->query('email');

            return Inertia::render('Auth/ResetPassword', [
                'token' => is_string($token) ? $token : '',
                'email' => is_string($email) ? $email : null,
            ]);
        });

        Fortify::verifyEmailView(static function (Request $request): InertiaResponse {
            $user = $request->user();

            // 登録由来の継続導線 (「あとで認証する」)。session には組織 id のみ保持し、
            // membership 確認を通ったときだけ URL 化する (IDOR 防御)。
            return Inertia::render('Auth/VerifyEmail', [
                'continueUrl' => EmailVerificationContinuation::resolveUrl(
                    $user instanceof User ? $user : null,
                    $request->session(),
                ),
            ]);
        });

        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済み。
        // ただし fortify.views=true の間は GET /user/confirm-password が Fortify により
        // 無条件登録され、ConfirmPasswordViewResponse 未 bind だと直アクセスが
        // BindingResolutionException で 500 になる (bug-hunt F-11)。正規の確認画面
        // (recent-auth.confirm、password or 再SSO) へ 302 で誘導する。
        // 注意: これは GET view の救済 redirect であり、`password.confirm` middleware 互換
        // (auth.password_confirmed_at の充足) は提供しない。middleware 互換が必要になったら
        // 別途設計すること (config/fortify.php の TODO(template) 参照)。
        Fortify::confirmPasswordView(
            static fn (): RedirectResponse => redirect()->route('recent-auth.confirm'),
        );

        Fortify::twoFactorChallengeView(static fn (): InertiaResponse => Inertia::render('Auth/TwoFactorChallenge'));
    }
}
