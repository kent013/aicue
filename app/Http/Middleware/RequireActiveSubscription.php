<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 課金ゲート: 有効な subscription (BillingAccess 判定) を持たない組織の
 * 業務 route アクセスを遮断する middleware。alias: `require-active-subscription`。
 *
 * - 判定は BillingAccess::hasActiveAccess のみ (subscription 直参照禁止。
 *   アプリは BillingAccess の差し替えで gate 方針を変更する)
 * - 未契約: ブラウザは billing へ redirect して Checkout 導線に誘導、
 *   JSON/XHR は 402 Payment Required
 * - allowlist: billing (index/checkout/portal)・Stripe webhook・組織管理系 route には
 *   本 middleware を適用しない (route 側で group に含めない構造的 allowlist。
 *   未契約でも checkout に到達できることを保証する)
 *
 * 対象 organization の解決:
 *   1. route に `{organization}` binding があればそれを使う。その際、非メンバー /
 *      不在 org は **認可より前に 404** (テナント存在秘匿)。通常は
 *      MembershipScopedOrganizationBinder が 404 済みのため到達しない
 *      defense-in-depth (binder 回帰時の最終防波堤。403 を返すと存在が漏れる)
 *   2. binding が無い current org スコープ route (/projects 等) は
 *      user の currentOrganization を使う。未設定は controller 側の
 *      ResolvesCurrentOrganization が 404 に倒すため素通しする
 */
final class RequireActiveSubscription
{
    public function __construct(
        private readonly BillingAccess $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            // 未認証は auth middleware の責務 (本 middleware は課金判定のみ)
            return $next($request);
        }

        $organization = $this->resolveOrganization($request, $user);
        if ($organization === null) {
            return $next($request);
        }

        if ($this->access->hasActiveAccess($organization)) {
            return $next($request);
        }

        // JSON/XHR は 402、ブラウザは billing へ誘導 (Checkout 導線)
        if ($request->expectsJson()) {
            abort(Response::HTTP_PAYMENT_REQUIRED, '有効なサブスクリプションがありません。お支払いを完了してください。');
        }

        // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
        // 1 hop で消費され失われないよう延命する
        $request->session()->reflash();

        return redirect()->route('billing.index');
    }

    /**
     * gate 対象の Organization を解決する。route binding 優先、無ければ current org。
     */
    private function resolveOrganization(Request $request, User $user): ?Organization
    {
        $organization = $request->route('organization');
        if ($organization instanceof Organization) {
            // defense-in-depth: 非メンバーがここに到達する = binder の回帰。
            // 存在秘匿方針に合わせ 404 で abort し、観測可能化して即検知する
            if (! Gate::forUser($user)->allows('view', $organization)) {
                Log::warning('non-member passed organization binder (binder regression?)', [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'route' => $request->route()?->getName(),
                ]);
                abort(404);
            }

            return $organization;
        }

        return $user->currentOrganization;
    }
}
