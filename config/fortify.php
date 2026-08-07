<?php

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Password Broker
    |--------------------------------------------------------------------------
    |
    | Here you may specify which password broker Fortify can use when a user
    | is resetting their password. This configured value should match one
    | of your password brokers setup in your "auth" configuration file.
    |
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which model attribute should be considered as your
    | application's "username" field. Typically, this might be the email
    | address of the users but you are free to change this value here.
    |
    | Out of the box, Fortify expects forgot password and reset password
    | requests to have a field named 'email'. If the application uses
    | another name for the field you may define it below as needed.
    |
    */

    'username' => 'email',

    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Lowercase Usernames
    |--------------------------------------------------------------------------
    |
    | This value defines whether usernames should be lowercased before saving
    | them in the database, as some database system string fields are case
    | sensitive. You may disable this for your application if necessary.
    |
    */

    'lowercase_usernames' => true,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. You are free to change this value.
    |
    */

    'home' => '/dashboard',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Prefix / Subdomain
    |--------------------------------------------------------------------------
    |
    | Here you may specify which prefix Fortify will assign to all the routes
    | that it registers with the application. If necessary, you may change
    | subdomain under which all of the Fortify routes will be available.
    |
    */

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Fortify will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        // passkey endpoint の絞り。**未設定だと FortifyServiceProvider::passkeyThrottleMiddleware()
        // が null を返し、未認証の GET /passkeys/login/options が無制限**になる
        // (毎回 random_bytes(32) + session 書き込みが走る)。
        // limiter 本体は App\Providers\FortifyServiceProvider::configureRateLimiters()。
        'passkeys' => 'passkeys',
        // メール検証 (verification.send / verification.verify)。**未設定だと Fortify 既定の
        // inline `6,1` になり、同一 actor の全 inline route と bucket を共有する** (T125)。
        // 1 knob で 2 route に貼られるため、この 2 本は構造的に同一レーンになる。
        // limiter 本体は FortifyServiceProvider::configureStepUpAndCredentialRateLimiters()。
        'verification' => 'email-verification',
    ],

    /*
    |--------------------------------------------------------------------------
    | Register View Routes
    |--------------------------------------------------------------------------
    |
    | Here you may specify if the routes returning views should be disabled as
    | you may not need them when building your own application. This may be
    | especially true if you're writing a custom single-page application.
    |
    */

    'views' => true,

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features or you can even remove all of these if you need to.
    |
    */

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            // Fortify 標準の password.confirm (3h・パスワード限定) は無効化し、step-up を
            // generic recent-auth (15 分窓・パスワード or 再SSO) へ統一する。SSO-only ユーザーを
            // password 固定の確認画面で詰ませないため。
            // recovery-codes (GET/POST) と disable (DELETE) は
            // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() で recent-auth を
            // 後付け配線済み (RecentAuthRouteTest が CI 固定)。
            // TODO(template): 残る 2FA 管理エンドポイント (enable/confirm/qr-code/secret-key)
            // は step-up なしで到達可能。enable/confirm は enrollment 動線 (2FA 強制組織の
            // オンボーディング) と衝突しない設計を決めてから同方式で固めること
            // (参照: aigenba RequireRecentAuthOnFortifyRoutes / spirux attachFortifyRouteMiddleware)。
            // 注意: FortifyServiceProvider の confirmPasswordView は GET /user/confirm-password の
            // 救済 redirect (recent-auth.confirm への誘導) のみで、`password.confirm` middleware 互換
            // (auth.password_confirmed_at の充足) は現行未提供 (bug-hunt F-11)。
            'confirmPassword' => false,
        ]),

        // パスキー (WebAuthn)。現場 PWA でパスワード入力を不要にする。
        // **この 1 行が実質的なキルスイッチ**: 外すと passkey route が消え、
        // PasskeyLoginPolicy が false を返して LoginMethodInventory も passkey を数えなくなる。
        //
        // confirmPassword=false の理由は 2FA と同一 — 本アプリは Fortify 標準の
        // password.confirm (3h・パスワード限定) を撤去し generic recent-auth
        // (15 分窓・パスワード or 再SSO) へ統一済みで、残すと SSO-only ユーザーが詰む。
        // step-up は App\Providers\PasskeyServiceProvider が recent-auth を後付け配線する
        // (PasskeyRouteProtectionTest / PasswordConfirmMiddlewareAbsenceTest が CI 固定)。
        Features::passkeys(['confirmPassword' => false]),
    ],

];
