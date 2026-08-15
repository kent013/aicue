<?php

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| パスキー (WebAuthn) の設定
|--------------------------------------------------------------------------
|
| ⚠ **宣言場所がここである理由**: Laravel\Fortify\FortifyServiceProvider::register() の
| configurePasskeys() が `passkeys.*` を `config('fortify.passkeys.*')` から
| **無条件に上書きする**ため、アプリ側 config/passkeys.php を置いても効かない。
| Fortify が読むこのキーが唯一の宣言点である。
|
| ⚠ **他の config ファイルを config() で読まない** (読み込み順に依存するため)。
| ここでは env() だけを見る (APP_KEY / APP_URL は config/app.php と同じ env を読む)。
|
| 既定値は APP_URL / APP_KEY からの導出で、同一オリジン PWA (v1 スコープ) では
| 通常 env の宣言なしで正しく動く。ただし **PASSKEYS_USER_HANDLE_SECRET だけは
| production で宣言が必須** (未宣言だと APP_KEY ローテートで登録済みパスキーが全件無効)。
| 検査は App\Support\PasskeyConfigValidator (ProductionEnvGuard 経由) が起動時に行う。
| 運用契約は docs/auth-security-mechanisms.md §5。
|
*/

$appUrl = parse_url((string) env('APP_URL', ''));

$appUrlScheme = is_array($appUrl) && is_string($appUrl['scheme'] ?? null) ? strtolower($appUrl['scheme']) : '';
$appUrlHost = is_array($appUrl) && is_string($appUrl['host'] ?? null) ? strtolower($appUrl['host']) : '';
$appUrlPort = is_array($appUrl) && is_int($appUrl['port'] ?? null) ? ':'.$appUrl['port'] : '';

// APP_URL の origin (scheme://host[:port])。path / query は落とす。
$derivedOrigin = ($appUrlScheme !== '' && $appUrlHost !== '')
    ? $appUrlScheme.'://'.$appUrlHost.$appUrlPort
    : '';

$declaredRelyingPartyIdValue = env('PASSKEYS_RELYING_PARTY_ID');
$declaredRelyingPartyId = is_string($declaredRelyingPartyIdValue) ? strtolower(trim($declaredRelyingPartyIdValue)) : '';

$declaredOriginsValue = env('PASSKEYS_ALLOWED_ORIGINS');
$declaredOrigins = is_string($declaredOriginsValue) ? trim($declaredOriginsValue) : '';

// 宣言があれば CSV を trim + **小文字化**して保持する (空要素は落とさない)。
// ★小文字化は load-bearing である。webauthn-lib の照合は
//   `in_array($normalizedOrigin, $this->fullOrigins, true)` = **strict な文字列比較**で
//   (vendor/web-auth/webauthn-lib/src/CeremonyStep/CheckAllowedOrigins.php 実測)、
//   ブラウザは常に小文字の origin を申告する。`HTTPS://App.Example.com` と書かれた設定は
//   一致せず**全ての手続きが無言で失敗する**ため、宣言の時点で小文字へ正規化する
//   (scheme と host は RFC 3986 上 case-insensitive なので、正規化は意味を変えない)。
// 宣言が無い / 空文字なら APP_URL からの導出 1 件に倒す
// (env ファイルにキーだけ残す運用を壊さないため、空文字は「未宣言」と同じ扱い)。
$rawAllowedOrigins = $declaredOrigins !== ''
    ? array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $declaredOrigins))
    : [$derivedOrigin];

// ⚠ **値そのものは trim しない**。「既にパスキーがある環境は現行 APP_KEY の値を
//    そのまま宣言すれば維持できる」という運用契約を守るため
//    (APP_KEY に前後空白が含まれていた場合、trim すると別の鍵になり全件無効になる)。
//    trim を使うのは「宣言されたか (空白だけではないか)」の判定にだけ留める。
$declaredUserHandleSecretValue = env('PASSKEYS_USER_HANDLE_SECRET');
$declaredUserHandleSecret = is_string($declaredUserHandleSecretValue) ? $declaredUserHandleSecretValue : '';
$userHandleSecretDeclared = trim($declaredUserHandleSecret) !== '';

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

    /*
    |--------------------------------------------------------------------------
    | Passkeys
    |--------------------------------------------------------------------------
    |
    | ファイル冒頭のコメント参照。FortifyServiceProvider::configurePasskeys() が
    | このブロックを passkeys.* へ写す (アプリ側 config/passkeys.php は効かない)。
    |
    */

    'passkeys' => [
        /*
        | 身元の識別子 (relying party id)。パスキーはこの値に束縛され、
        | 一致するドメインでしか検証できない。host のみ (scheme / port を含めない)。
        | 未宣言なら APP_URL の host。Fortify が passkeys.relying_party_id へ写す。
        */
        'relying_party_id' => $declaredRelyingPartyId !== '' ? $declaredRelyingPartyId : $appUrlHost,

        /*
        | 許可する接続元 (allowed origins)。ブラウザが申告した origin がこの列に無ければ
        | WebAuthn の手続きを受け付けない。`scheme://host[:port]` 形式。
        | Fortify が passkeys.allowed_origins へ写し、webauthn-lib が読む。**空要素を除いた列**。
        */
        'allowed_origins' => array_values(array_filter(
            $rawAllowedOrigins,
            static fn (string $v): bool => $v !== '',
        )),

        /*
        | フィルタ前の接続元列 (trim・小文字化済み。**空要素を保持する**)。
        | ここでの「生」は「env の原文」ではなく「空要素を除去する前」の意味である。config 段で落ちた空要素を
        | 起動時 fail-fast で表面化させるために PasskeyConfigValidator が読む
        | (trustedproxy.raw_proxies と同じ役割)。**Fortify は本キーを読まない**
        | (検査専用。passkeys.* へは写らない)。
        */
        'raw_allowed_origins' => $rawAllowedOrigins,

        /*
        | 利用者ハンドルの導出鍵。hash_hmac の鍵として使われ、**変わると
        | 登録済みパスキーが全件無効になる**。未宣言なら APP_KEY に倒れるため、
        | APP_KEY ローテートがパスキー全件失効を意味してしまう。
        | production では宣言必須 (PasskeyConfigValidator が起動時に検査)。
        */
        'user_handle_secret' => $userHandleSecretDeclared
            ? $declaredUserHandleSecret
            : (string) env('APP_KEY', ''),

        /*
        | 導出鍵が **APP_KEY と独立して宣言されたか**。値の一致では判定しない
        | (既存パスキーを維持するために現行 APP_KEY と同じ値を意図して宣言する
        |  移行が正当なため)。config:cache 後も真偽値として残る。
        | **Fortify は本キーを読まない** (検査専用)。
        */
        'user_handle_secret_declared' => $userHandleSecretDeclared,
    ],

];
