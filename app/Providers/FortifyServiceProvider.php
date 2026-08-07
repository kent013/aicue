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
use App\Support\EmailHash;
use App\Support\EmailNormalizer;
use App\Support\Http\RateLimiterKeys;
use App\Support\Http\RouteThrottleBinder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
     * いずれも「第二要素の秘密の露出」または「確立済み第二要素の除去・差し替え」経路であり、
     * 通常セッション認証だけで到達させない (姉妹操作: organizations.members.two-factor.reset /
     * settings.account.destroy 等と同基準)。
     * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
     * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
     *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
     *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
     *     self-disable が許可される非 enforced 組織のユーザー。
     * - qr-code / secret-key (GET, T124): TOTP seed そのものの露出。
     *   Fortify の TwoFactorSecretKeyController は two_factor_secret を**復号して平文で返し**、
     *   TwoFactorQrCodeController は otpauth:// URL (秘密を内包) を返す。どちらも
     *   two_factor_confirmed_at を見ないため、**確立済み**第二要素の seed が読める。
     *   throttle (two-factor-secret-read) は連続取得の回数上限であって step-up の代替ではない。
     * - enable (POST, T124): Fortify の TwoFactorAuthenticationController は
     *   `$request->boolean('force')` を EnableTwoFactorAuthentication へ渡す。
     *   force=true は two_factor_secret と two_factor_recovery_codes を**再生成する一方で
     *   two_factor_confirmed_at を触らない** (fortify v1.37.2 実査) ため、奪取セッションから
     *   1 回叩くだけで「誰も知らない秘密で TOTP を要求し続ける」永久ロックアウトを作れる。
     *   秘密の**読み出し**だけ塞いで**差し替え**を開けたままにしない。
     * 付与漏れは RecentAuthRouteTest (allowlist) と TwoFactorStepUpInventoryTest
     * (deny-by-default 目録) の 2 枚で検出する。
     *
     * @var list<string>
     */
    private const RECENT_AUTH_ROUTE_NAMES = [
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.enable',
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
        $this->attachThrottleToFortifyRoutes();
    }

    /**
     * Fortify が登録する認証系 route への throttle 後付け表。
     *
     * config/fortify.php の `limiters` は login / two-factor / passkeys / verification の
     * 4 キーしか受け付けないため、それ以外は route 名での後付けで賄う
     * (「貼る仕組みの 3 段優先順」の第 2 段。第 1 段で貼れるものは第 1 段のまま)。
     *
     * 閾値の根拠:
     *  - password-reset-request / password-reset-submit / account-register は
     *    「未認証 + メール送信または credential 総当り」であり、**既に本番稼働中の
     *    同性質エンドポイント (inquiry / login) と同値**にする (新しい値を発明しない)。
     *  - password-verify (6/min) は recent-auth.password と同値 (1 つの秘密の照合予算)。
     *  - two-factor-manage (10/min) は onboarding.activate-personal と同値 (認証済みの管理操作)。
     *
     * ★**本表に inline (`{max},{decay}`) を書かない** (T125 で全廃)。
     *   inline のキーは user id だけで route も limiter 名も入らないため、
     *   **同一 actor の全 inline throttle route が 1 bucket を共有する**
     *   (ThrottleRequests::handle() の $prefix 既定 '' + resolveRequestSignature())。
     *   合算値が最小 max (recent-auth.password = 6) を先に食い潰して再認証を壊すため、
     *   レーンを分けたい面は named limiter を新設する
     *   (configureStepUpAndCredentialRateLimiters())。自前 route への inline 追加は
     *   InlineThrottleInventoryTest が deny-by-default で止める。
     *
     * ★`feature` は Fortify の機能フラグ (config/fortify.php の `features`)。
     *   null = 常に必須 (route が無ければ起動時 fail-fast)。
     *   非 null = その機能が有効なときだけ必須 (無効なら route 自体が登録されないため skip)。
     *   **skip が穴にならない根拠**: 機能を再有効化して binder が skip したままなら、
     *   ThrottleCoverageInventoryTest が「throttle 無しの保護対象 route」として必ず fail する
     *   (binder の fail-fast と目録検査の二重の網で守る)。
     *
     * @return array<string, array{throttle: string, feature: string|null}>
     */
    private static function throttledFortifyRoutes(): array
    {
        return [
            'password.email' => ['throttle' => 'password-reset-request', 'feature' => Features::resetPasswords()],
            'password.update' => ['throttle' => 'password-reset-submit', 'feature' => Features::resetPasswords()],
            'register.store' => ['throttle' => 'account-register', 'feature' => Features::registration()],
            // ★T125: inline (`6,1` / `10,1`) から named limiter へ移行。
            //   inline のキーは user id だけで route も limiter 名も入らず、
            //   同一 actor の全 inline route が 1 bucket を共有するため
            //   (2FA 管理を連打すると再認証が 429 になる)。閾値は移行前と同値。
            'password.confirm.store' => ['throttle' => 'password-verify', 'feature' => null],
            'user-password.update' => ['throttle' => 'password-verify', 'feature' => Features::updatePasswords()],
            'two-factor.enable' => ['throttle' => 'two-factor-manage', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.confirm' => ['throttle' => 'two-factor-manage', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.disable' => ['throttle' => 'two-factor-manage', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.regenerate-recovery-codes' => ['throttle' => 'two-factor-manage', 'feature' => Features::twoFactorAuthentication()],
            // ★秘密を返す GET 3 本 (T120 事後監査の是正)。
            //   named limiter を使う理由は configureRateLimiters() の
            //   two-factor-secret-read の docblock を参照 (inline は bucket を
            //   全 inline route で共有するため、描画 GET を足すと再認証を壊す)。
            'two-factor.qr-code' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.secret-key' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.recovery-codes' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
        ];
    }

    /**
     * Fortify 登録 route へ throttle を後付けする (設定で貼れないものだけ)。
     *
     * route 登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決する (attachRecentAuthToSensitiveRoutes と同じ流儀)。
     * 後付けは冪等で、route 名が消えていれば fail-fast する
     * (route:cache 起動時の扱いは RouteThrottleBinder::attachOnBooted の docblock を参照)。
     */
    private function attachThrottleToFortifyRoutes(): void
    {
        $routes = [];

        foreach (self::throttledFortifyRoutes() as $name => $spec) {
            if ($spec['feature'] !== null && ! Features::enabled($spec['feature'])) {
                continue; // 機能無効 = route 自体が存在しない (目録検査が二重の網)
            }

            $routes[$name] = $spec['throttle'];
        }

        RouteThrottleBinder::attachOnBooted($this->app, $routes);
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
        /*
         * ログイン試行の RateLimiter。閾値 5/min は据え置き (プロダクト依存の既定値)。
         *
         * ★Str::transliterate を廃止した理由:
         *   App\Support\EmailNormalizer の docblock が「legitimate な Unicode email を
         *   別 user に collapse させるリスクがあるため使わない」と明記しているのに、
         *   本 limiter だけが使っており設計意図と実装が正面から矛盾していた。
         *   実害は「無関係アカウントの巻き添えロックアウト」。
         *
         * ★email は EmailHash (HMAC-SHA256 / app.key 鍵付き) でハッシュ化してからキーに入れる。
         *   **canonical 化の正本は EmailNormalizer** (保存・検索・inquiry と同一)。
         *   limiter は validation より前に走るため email が非 string で来うる → is_string ガード必須。
         */
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                'login:email-ip:'.self::emailKey($request, Fortify::username())
                .':'.($request->ip() ?? 'unknown'),
            );
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $loginId = $request->session()->get('login.id');

            return is_scalar($loginId)
                ? Limit::perMinute(5)->by('two-factor:login-id:'.$loginId)
                : Limit::perMinute(5)->by('two-factor:ip:'.($request->ip() ?? 'unknown'));
        });

        // passkey (WebAuthn) endpoint。config/fortify.php の limiters.passkeys が
        // この名前を指しており、未設定だと Fortify が throttle 自体を外す
        // (= 未認証の challenge 発行 GET /passkeys/login/options が無制限になる)。
        // 未認証の login-options を含むため、認証済みは user 単位・未認証は IP 単位で絞る。
        RateLimiter::for('passkeys', fn (Request $request): Limit => Limit::perMinute(10)
            ->by(RateLimiterKeys::actorOrIp($request, 'passkeys')));

        /*
         * 2FA の秘密を返す GET (qr-code / secret-key / recovery-codes) の読み取りレーン。
         *
         * ★inline (`10,1`) にしない: inline のキーは sha1(user id) だけで
         *   **同一ユーザーの全 inline route が 1 bucket を共有する**
         *   (ThrottleRequests::resolveRequestSignature)。ページ描画で 2 発飛ぶ GET を
         *   そこへ足すと、リロード数回で recent-auth.password (max 6) まで 429 にしてしまう。
         *   named limiter はキーに limiter 名が入るためレーンが独立する。
         *
         * ★閾値 10/min は姉妹の 2FA 管理操作 (two-factor.enable / .confirm / .disable /
         *   .regenerate-recovery-codes の `10,1`) と同値 (新しい値を発明しない)。
         *
         * ★IP 分岐は「auth を持たない route でも同じ helper を使える」ための冗長である
         *   (T125 で事実に合わせて訂正)。framework 既定の priority list は
         *   `AuthenticatesRequests` → `ThrottleRequests` の順であり **auth の方が先**に走るため、
         *   auth 必須の本 route では user 分岐しか通らない。この実効順は
         *   AuthThrottleCoverageTest「認証は throttle より先に走る」が固定する。
         *
         * ★これは**連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
         *   認証強度は T124 で別途 recent-auth を後付けした (RECENT_AUTH_ROUTE_NAMES)。
         *   この 2 つは役割が違うので、片方を理由にもう片方を外さないこと。
         */
        RateLimiter::for('two-factor-secret-read', fn (Request $request): Limit => Limit::perMinute(10)
            ->by(RateLimiterKeys::actorOrIp($request, 'two-factor-secret-read')));

        $this->configureStepUpAndCredentialRateLimiters();
        $this->configureAuthFormRateLimiters();
    }

    /**
     * inline throttle から移行した「actor 自身に閉じる認証面」のレーン群 (T125)。
     *
     * ★なぜ inline から移すのか:
     *   `ThrottleRequests::handle()` の inline 経路が組むキーは
     *   `$prefix` (既定 `''`) + `resolveRequestSignature()` で、後者は認証済みなら
     *   **user id だけ**を返す (route 名も limiter 名も入らない)。つまり
     *   **同一 actor の全 inline throttle route が 1 bucket を共有**し、
     *   最小 max を持つ `recent-auth.password` (6) が他 route の連打で先に潰れて
     *   **再認証ができなくなる**。named limiter はキーにレーン名が入るため独立する。
     *
     * ★閾値は移行元の inline 値そのまま (新しい値を発明しない。AGENTS.md ドメイン規約 5)。
     *
     * ★レーンの切り方は 2 基準あり、混同しない:
     *   - **同じ credential を照合する面** = その秘密に対する「試行予算」(password-verify)
     *   - **同じ feature のフロー** = そのフローの「操作予算」(two-factor-manage / email-verification)
     *   フロー内の相互消費は許容し、**別 feature との巻き添えを遮断する**のが本設計の目的。
     */
    private function configureStepUpAndCredentialRateLimiters(): void
    {
        // パスワード**照合**の試行予算。3 本 (recent-auth.password / password.confirm.store /
        // user-password.update) が 1 つの秘密を数えるため、合算 6/min を維持する
        // (面ごとに分けると同じ秘密に 18 回/min 試せることになり総当り耐性が下がる)。
        RateLimiter::for('password-verify', fn (Request $request): Limit => Limit::perMinute(6)
            ->by(RateLimiterKeys::actorOrIp($request, 'password-verify')));

        // パスワードの**初回設定** (settings.password.store)。current_password の照合を伴わない
        // credential mutation であり数える対象が違うため password-verify とはレーンを分ける。
        // 同居させると「設定に 6 回失敗 → step-up 再認証が 429」という巻き添えが残る。
        RateLimiter::for('password-set', fn (Request $request): Limit => Limit::perMinute(6)
            ->by(RateLimiterKeys::actorOrIp($request, 'password-set')));

        // メール検証フロー (verification.send / verification.verify)。
        // ★2 本が同レーンなのは Fortify が config('fortify.limiters.verification') という
        //   **1 つの knob** で両方に貼るためで、第 2 段 (package の設定) で貼る限り構造的にそうなる。
        //   概念的には「外向きメール送信」と「署名付き GET の検証」は数える対象が違うため、
        //   これは**暫定判断**である。
        RateLimiter::for('email-verification', fn (Request $request): Limit => Limit::perMinute(6)
            ->by(RateLimiterKeys::actorOrIp($request, 'email-verification')));

        // 2FA 設定フローの操作予算 (enable / confirm / disable / regenerate-recovery-codes)。
        // ★受容リスク: enable/disable/regenerate の消費で、秘密照合面である confirm が
        //   429 になる構造が意図的に残る。4 本は同一画面から踏む 1 フローであり、
        //   TOTP の天井は分離しても 10 のまま変わらない (分離してもレーンが増えるだけ)。
        RateLimiter::for('two-factor-manage', fn (Request $request): Limit => Limit::perMinute(10)
            ->by(RateLimiterKeys::actorOrIp($request, 'two-factor-manage')));
    }

    /**
     * 未認証 + メール送信 / credential 総当りを伴う認証系 POST の RateLimiter。
     *
     * 閾値は**既に本番稼働中の同性質エンドポイントと同値**にする (新しい値を発明しない):
     *  - IP 単独 5/min      = inquiry (公開問い合わせフォーム) / login と同値
     *  - IP+email 10/60min  = inquiry と同値
     *
     * 2 系統に分ける理由: IP 単独は「1 本の回線からのメール爆撃」を、
     * IP+email は「同一宛先への長時間の反復」を止める (数える単位が違う)。
     *
     * ★`RateLimiter::for()` の第 1 引数は必ずリテラルで書く (ループで一括登録しない)。
     *   RateLimiterKeyConventionTest の scanner が非リテラル引数を deny-by-default で
     *   fail させる契約になっており、沈黙する登録を作らないため。
     */
    private function configureAuthFormRateLimiters(): void
    {
        RateLimiter::for(
            'password-reset-request',
            fn (Request $request): array => self::authFormLimits('password-reset-request', $request, Fortify::email()),
        );

        RateLimiter::for(
            'password-reset-submit',
            fn (Request $request): array => self::authFormLimits('password-reset-submit', $request, Fortify::email()),
        );

        RateLimiter::for(
            'account-register',
            fn (Request $request): array => self::authFormLimits('account-register', $request, Fortify::username()),
        );
    }

    /**
     * 認証フォーム系 limiter の 2 系統 (IP 単独 / IP+email) を組み立てる。
     *
     * @return list<Limit>
     */
    private static function authFormLimits(string $lane, Request $request, string $field): array
    {
        $ip = $request->ip() ?? 'unknown';

        return [
            Limit::perMinute(5)->by($lane.':ip:'.$ip),
            Limit::perMinutes(60, 10)->by($lane.':ip-email:'.$ip.':'.self::emailKey($request, $field)),
        ];
    }

    /**
     * limiter キーに埋め込む email の鍵付きハッシュ (平文を cache キーに残さない)。
     *
     * 正規化の正本は EmailNormalizer (保存・検索・inquiry と同一関数)。
     * limiter は validation より前に走るため、非 string / 空文字は `anon` へ倒す。
     */
    private static function emailKey(Request $request, string $field): string
    {
        $raw = $request->input($field);
        $email = is_string($raw) && $raw !== '' ? EmailNormalizer::normalize($raw) : '';

        return $email !== '' ? EmailHash::compute($email) : 'anon';
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

            // 認証前に onboarding.checkout へ進ませる CTA は出さない (route は
            // ['auth','verified'] 配下 = 未認証は必ず差し戻される)。継続が実在するときだけ
            // 「認証後にプラン選択へ進む」ことを予告する説明を出す (bug-hunt F-2-01)。
            // service は継続の有無までを返し、画面語彙への写像はここで行う。
            return Inertia::render('Auth/VerifyEmail', [
                'continuesToCheckout' => EmailVerificationContinuation::hasContinuation(
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
