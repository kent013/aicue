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
use App\Support\Http\RouteMiddlewareBinder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Features;
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
    /**
     * throttle を後付けする passkey route。
     *
     * vendor (Fortify) の $passkeyMiddleware は $throttle を含まないため、
     * passkey.destroy **だけ** throttle が付かない
     * (vendor/laravel/fortify/routes/routes.php)。
     * EnsureLoginMethodRemains が毎リクエスト DB::transaction + User 行 lockForUpdate を
     * 取るため、認証済みユーザーが自分の User 行に無制限のロック競合を起こせる
     * (audit-cycle-2 Medium-2)。他の passkey route と同じ 10/min に揃える。
     *
     * ThrottleRequests は Laravel の priority list に含まれ Authenticate より後に走るため、
     * キーは user 単位になる (未認証 IP fallback には落ちない)。これは limiter 定義次第の
     * **設計上の期待**なので、別ユーザー同士で bucket が共有されないことを
     * PasskeyThrottleTest が振る舞いで固定する。
     */
    private const THROTTLE_ROUTE_NAMES = [
        'passkey.destroy',
    ];

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

        // ★この 2 つには **cached 起動での有効/無効に差がある**。
        //   一括りに「booted の後付けは cached では無効」と読まないこと:
        //
        //   - Route::bind() は Router::$binders (route collection とは**別の**連想配列) への
        //     登録であり、Router::setCompiledRoutes() の collection 差し替えの影響を受けない。
        //     **cached 起動でも有効**。booted に置いてあるのは boot 順序の問題
        //     (PasskeysServiceProvider::boot() の Route::bind に後勝ちする) だけが理由。
        //   - middleware の後付けは route collection への書き込みであり、
        //     **cached 起動では 1 本も効かない** (RouteMiddlewareBinder の docblock が正本)。
        $this->app->booted(static function (): void {
            Route::bind('passkey', SelfScopedPasskeyBinder::class);
        });

        // first-class callable (理由は FortifyServiceProvider 側と同じ)
        RouteMiddlewareBinder::attachOnBooted($this->app, self::passkeyRouteSpecs(...));
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
     * Fortify が登録した passkey route へ後付けするアプリ側 middleware の spec。
     *
     * ★**順序が重要**: throttle → recent-auth → 手段保持 の順に並べる。
     *   throttle を先に並べることで、priority 適用後も ThrottleRequests が
     *   RequireRecentAuth より前になる (無制限のロック競合を最外周で止める)。
     *   逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
     *   PasskeyRouteProtectionTest が解決後のクラス列上の index 比較で固定する。
     *   → 1 route あたりの alias 列も**この順**で並べる (binder は列の順に append する)。
     *
     * ★route:cache との契約 (cached 起動では 1 本も効かない / 実効は生成時の焼き込み /
     *   毎デプロイ再生成が前提条件) は {@see RouteMiddlewareBinder} が正本。
     *   **旧記述の訂正**: かつてここには「route:cache 下でも nameCache が同一 instance を
     *   返すため有効」と書いてあったが誤りである。
     *
     * ★feature flag: passkey route は `Features::passkeys()` が有効なときだけ登録される
     *   (config/fortify.php の「この 1 行が実質的なキルスイッチ」)。無効時は spec を空にする。
     *
     * @return array<string, list<string>> route 名 => middleware alias の列
     */
    private static function passkeyRouteSpecs(): array
    {
        if (! Features::enabled(Features::passkeys())) {
            return [];
        }

        $specs = [];

        foreach (self::THROTTLE_ROUTE_NAMES as $name) {
            $specs = self::withAlias($specs, $name, 'throttle:passkeys');
        }

        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            $specs = self::withAlias($specs, $name, 'recent-auth');
        }

        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
            $specs = self::withAlias($specs, $name, 'ensure-login-method');
        }

        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
        // WebAuthn challenge を載せる応答をキャッシュさせない。
        $specs = self::withAlias($specs, 'passkey.login-options', 'no-store');

        return $specs;
    }

    /**
     * spec の route へ alias を**列の末尾**に足す (列の順がそのまま append 順になる)。
     *
     * ★helper に切り出しているのは PHPStan level 10 のため。const 由来のリテラル配列へ
     *   `[...($specs[$name] ?? []), $alias]` を直接書くと、shape が完全に推論されて
     *   「`??` の左辺は常に存在する / 存在しない」の nullCoalesce.offset で落ちる。
     *   一般型 `array<string, list<string>>` を跨がせることで、`mixed` 化や
     *   ignore 注釈に逃げず、公開契約の型をそのまま保ったまま具体 shape の推論だけを
     *   切り、「未定義キーなら空列から始める」という**意図**をそのまま書ける。
     *
     * @param  array<string, list<string>>  $specs
     * @return array<string, list<string>>
     */
    private static function withAlias(array $specs, string $routeName, string $alias): array
    {
        $specs[$routeName] = [...($specs[$routeName] ?? []), $alias];

        return $specs;
    }
}
