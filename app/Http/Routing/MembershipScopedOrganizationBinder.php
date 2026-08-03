<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * web ルートの `{organization}` route binding をテナント存在秘匿付きで解決する explicit binder。
 *
 * org-boundary-404: 解決クエリを「認証済み web ユーザーが所属する組織」にスコープし、
 * 非メンバー・不在 slug/id を等しく ModelNotFoundException → 404 にする。不在組織と非メンバー
 * 組織の応答差分を消し、組織 slug (顧客名等) の存在列挙を構造的に防ぐ。
 *
 * 適用境界 (重要):
 *  - 担うのはクロステナント秘匿 (membership) のみ。same-org の権限不足 (Policy 403) は
 *    従来どおり各 Controller / Policy の責務であり、本 binder は関与しない。
 *  - `{organization}` param は routes/web.php 専用 (tests/Architecture/
 *    OrganizationRouteParamWebOnlyInvariantTest で pin)。API v1 / MCP は API key から
 *    org を確定する別レイヤー (ResolvesApiOrganization) で本 binder を通らない。
 *  - Filament admin は `{record}` param + resolveRouteBindingQuery() 経由のため影響しない。
 *
 * membership の真実は organization_user pivot (Organization::users())。whereHas で
 * 解決クエリ自体をスコープするため、非メンバーには「存在しない」のと同じ応答になる。
 *
 * AppServiceProvider::boot の `Route::bind('organization', self::class)` から、Laravel の
 * class binding 規約 (RouteBinding::createClassBinding、既定メソッド名 `bind`) で呼ばれる。
 *
 * `{organization}` は RouteBindingTypes::CUSTOM_BINDER 分類 (= Route::pattern による宣言的
 * 型制約を掛けられない。`{organization:slug}` を併用するため)。NormalizesRouteBindingInput は
 * その分類を型で宣言する marker で、入力正規化の実効性の正本は Feature テスト
 * (tests/Feature/Routing/RouteBindingTypeConstraintTest の異常系) である。
 */
final class MembershipScopedOrganizationBinder implements NormalizesRouteBindingInput
{
    /**
     * binding field の allowlist。route 定義側の `{organization:xxx}` 誤指定を
     * QueryException (500) でなく 404 に倒すための固定リスト。
     *
     * @var list<string>
     */
    private const BINDABLE_FIELDS = ['id', 'slug'];

    /**
     * @throws ModelNotFoundException<Organization>
     */
    public function bind(mixed $value, ?Route $route = null): Organization
    {
        // auth middleware は SubstituteBindings より priority が先のため guest はここへ
        // 到達する前に 302 login になる。万一の経路 (auth なし route への将来の
        // {organization} 追加等) は fail-closed で 404。
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        if (! is_string($value) && ! is_int($value)) {
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        // `{organization:slug}` の field 指定を尊重し、無指定 (`{organization}`) は
        // routeKeyName (id) で解決する。field は allowlist で固定 — 将来 route 定義側で
        // 未知 field ({organization:uuid} 等) を指定しても QueryException (500) に倒さず、
        // 存在秘匿と同じ 404 にフォールバックする。
        $field = $route?->bindingFieldFor('organization')
            ?? (new Organization)->getRouteKeyName();

        if (! in_array($field, self::BINDABLE_FIELDS, true)) {
            Log::warning('organization binder received unsupported binding field', [
                'field' => $field,
                'route' => $route?->getName(),
            ]);
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        // id binding は整数 PK。非数値・bigint 範囲外・非 canonical (先頭ゼロ等) は
        // DB 方言次第で 500 化する余地があるため、存在し得ない id として 404 に倒す。
        if ($field === 'id') {
            $value = $this->normalizeIntegerId($value);
            if ($value === null) {
                throw (new ModelNotFoundException)->setModel(Organization::class);
            }
        }

        $organization = Organization::query()
            ->where($field, $value)
            ->whereHas(
                'users',
                static fn (Builder $query): Builder => $query->whereKey($user->id),
            )
            ->first();

        if (! $organization instanceof Organization) {
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        return $organization;
    }

    /**
     * route 引数を bigint PK として安全な int に正規化する。非数値・bigint 範囲外は
     * 「存在し得ない id」として null を返し、呼び出し側で fail-closed 404 に倒す。
     */
    private function normalizeIntegerId(string|int $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        // 非負整数表現のみ許可
        if (! ctype_digit($value)) {
            return null;
        }

        // 先頭ゼロ (canonical でない表現。例 "007") を弾く。
        if (strlen($value) > 1 && $value[0] === '0') {
            return null;
        }

        // 64bit signed (= bigint と同幅) 範囲内のみ許可する。範囲外の巨大数値文字列を
        // (int) キャストすると PHP 8.5 は「範囲外文字列の cast」警告を投げ 500 化するため、
        // キャスト前に文字列比較で範囲判定して 404 に倒す。
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            return null;
        }

        return (int) $value;
    }
}
