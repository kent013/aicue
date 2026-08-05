<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;

/**
 * `{passkey}` route binding を「認証済み web ユーザー所有の passkey」にスコープして解決する
 * explicit binder。
 *
 * **差し替える理由 (セキュリティ不変条件 2「子は親に属する = 認可より前に 404」)**:
 * vendor の binder (Laravel\Passkeys\PasskeysServiceProvider::registerRouteBindings) は
 * `app($model)->resolveRouteBinding($value)` でグローバルに id 解決するため、
 * controller の `abort_unless($passkey->user_id === $user->getKey(), 403)` に到達し
 * **他人の passkey の存在を 403/404 の差で漏らす**。所有者スコープで解決すれば
 * 「他人の passkey」と「存在しない id」が等しく 404 になる。
 *
 * 併せて 22P02 (pgsql invalid_text_representation) 対策も担う。`{passkey}` は
 * RouteBindingTypes::CUSTOM_BINDER 分類のため Route::pattern による宣言的な数値制約を
 * 掛けない (vendor route の param に app 側から pattern を掛けると vendor 側の
 * route 定義変更に追随できない)。代わりに本 binder が非数値・範囲外を 404 に倒す。
 *
 * 登録は PasskeyServiceProvider::boot() の `$this->app->booted()` から
 * `Route::bind('passkey', self::class)` で行い、vendor provider の boot に**後勝ち**させる。
 */
final class SelfScopedPasskeyBinder implements NormalizesRouteBindingInput
{
    /**
     * @throws ModelNotFoundException<Passkey>
     */
    public function bind(mixed $value, ?Route $route = null): Passkey
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            // auth middleware が先に 302/401 に倒すのが正常系。到達しても fail-closed で 404。
            throw (new ModelNotFoundException)->setModel(Passkey::class);
        }

        $id = $this->normalizeIntegerId($value);
        if ($id === null) {
            throw (new ModelNotFoundException)->setModel(Passkey::class);
        }

        // 所有者スコープの where を **解決クエリ自体に**含める (取得後に弾くと 403/404 の
        // 差で存在が漏れる)。App\Models\Passkey 型で返すためモデル直クエリを使う
        // (relation は PasskeyUser interface の宣言により vendor 型で解決される)。
        $passkey = Passkey::query()
            ->whereKey($id)
            ->where('user_id', $user->getKey())
            ->first();

        if (! $passkey instanceof Passkey) {
            throw (new ModelNotFoundException)->setModel(Passkey::class);
        }

        return $passkey;
    }

    /**
     * route 引数を bigint PK として安全な int に正規化する。
     * 非数値・bigint 範囲外は「存在し得ない id」として null を返し 404 に倒す
     * (MembershipScopedOrganizationBinder と同じ作法)。
     */
    private function normalizeIntegerId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        // bigint 上限を超える桁は DB へ渡さない (22003 numeric_value_out_of_range 回避)
        if (strlen(ltrim($value, '0')) > 18) {
            return null;
        }

        return (int) $value;
    }
}
