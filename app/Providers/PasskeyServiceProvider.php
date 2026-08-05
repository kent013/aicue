<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\Passkey\PasskeyConfirmationResponse;
use App\Http\Responses\Passkey\PasskeyDeletedResponse;
use App\Http\Responses\Passkey\PasskeyLoginResponse;
use App\Http\Responses\Passkey\PasskeyRegistrationResponse;
use App\Http\Routing\SelfScopedPasskeyBinder;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\PasskeyLoginPolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey as VendorPasskey;
use Laravel\Passkeys\Passkeys;

/**
 * laravel/passkeys (Fortify 1.37 が推移依存で持つ) のアプリ側アダプタ。
 *
 * route / controller / action / migration は Fortify + laravel/passkeys が提供する
 * (AGENTS.md 思考原則 1: フレームワークのレンジ内でやる)。本 Provider は
 * 「アプリ固有の不変条件を vendor に被せる」ことだけを担う:
 *
 *   1. **binder 差し替え**: vendor の binder はグローバルに id 解決し、controller が
 *      `abort_unless($passkey->user_id === $user->getKey(), 403)` で弾く。403 は
 *      **他人の passkey の存在を漏らす**。認証ユーザーの passkeys() 経由に張り替えて
 *      不整合を **認可より前に 404** にする (AGENTS.md セキュリティ不変条件 2)。
 *   2. **Response contract 上書き**: vendor 既定は `new JsonResponse(...)` を直に返し
 *      禁止事項 4 に触れる。Inertia / DTO+JsonResource へ差し替える。
 *      あわせて confirm 経路が書く `auth.password_confirmed_at` を除去する
 *      (RecentAuthState の契約「Fortify の鍵には書かない」を守る)。
 *   3. **vendor route 加工**: recent-auth / ensure-login-method / no-store の後付け配線。
 *   4. **login 認可**: TOTP 有効ユーザーの passkey login を拒否 (PasskeyLoginPolicy に委譲)。
 *
 * ⚠ **boot 順序**: Route::bind() は後勝ちだが、`bootstrap/providers.php` の順序は
 * **app provider 間の順序**にすぎず、auto-discovery された package provider
 * (Laravel\Passkeys\PasskeysServiceProvider) との最終 boot 順序を保証しない。
 * したがって binder 差し替えも **`$this->app->booted()` の中で実行する**
 * (= 全 provider の boot が終わった後に最終上書きする)。route middleware の後付けと
 * 同じ「booted で最終上書き」の形に統一する。
 * この順序依存は tests/Architecture/PasskeyPackageContractTest が
 * 「binder の最終解決系がアプリ実装」+「他人の passkey は 404」で固定する。
 */
final class PasskeyServiceProvider extends ServiceProvider
{
    /** recent-auth を後付けする passkey route (credential 集合を触る管理経路) */
    private const RECENT_AUTH_ROUTE_NAMES = [
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    /** ログイン手段を減らす passkey route */
    private const LOGIN_METHOD_GUARD_ROUTE_NAMES = [
        'passkey.destroy',
    ];

    public function register(): void
    {
        Passkeys::usePasskeyModel(Passkey::class);

        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);
        $this->app->singleton(PasskeyRegistrationResponseContract::class, PasskeyRegistrationResponse::class);
        $this->app->singleton(PasskeyDeletedResponseContract::class, PasskeyDeletedResponse::class);
    }

    public function boot(): void
    {
        $this->configureLoginAuthorization();

        // binder と middleware は **全 provider boot 後** に最終上書きする
        // (PasskeysServiceProvider::boot() の Route::bind に確実に後勝ちするため)。
        $this->app->booted(static function (Application $app): void {
            Route::bind('passkey', SelfScopedPasskeyBinder::class);
            self::attachMiddlewareToPasskeyRoutes($app);
        });
    }

    /**
     * passkey login の最終ゲート。判定は PasskeyLoginPolicy に集約する
     * (LoginMethodInventory と条件を二重定義しないため。closure にロジックを書かない)。
     */
    private function configureLoginAuthorization(): void
    {
        Passkeys::authorizeLoginUsing(static function (Request $request, PasskeyUser $user, VendorPasskey $passkey): bool {
            if (! $user instanceof User) {
                return false;   // fail-closed
            }

            return app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user);
        });
    }

    /**
     * Fortify が登録した passkey route へアプリ側 middleware を後付けする。
     * FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() と同じ作法
     * (route:cache 下でも CompiledRouteCollection の nameCache が同一 instance を返すため有効)。
     */
    private static function attachMiddlewareToPasskeyRoutes(Application $app): void
    {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();

        // **順序が重要**: recent-auth を先に通し、その後で手段保持を検査する。
        // 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
        // PasskeyRouteProtectionTest が gatherMiddleware() 上の index 比較で固定する。
        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
        }

        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'ensure-login-method');
        }

        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
        // WebAuthn challenge を載せる応答をキャッシュさせない。
        self::appendMiddlewareIfMissing($routes, 'passkey.login-options', 'no-store');
    }

    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }
}
