<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Billing\OnboardingBillingState;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use App\Services\Onboarding\OnboardingReturnResolver;
use App\Support\Http\FlashNotificationRelay;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

/**
 * 課金ゲート: BillingAccess の entitlement 判定で不許可 (= 未契約、または有償プラン契約中の
 * 支払い不健全) の組織の業務 route アクセスを遮断し、onboarding へ誘導する middleware。
 * alias: `require-active-subscription`。
 *
 * - 判定は BillingAccess::state()->grantsAccess() のみ (subscription 直参照禁止。
 *   plan_code は見ない = 無料枠は free_plan_code='personal' の ActiveFreePlan で表現する)
 * - 遮断時: ブラウザは onboarding へ redirect (manageBilling 保持者は自分で契約できるので
 *   onboarding.checkout、非保持者は説明画面 onboarding.billing-required)。**遮断理由の flash は
 *   積まない** — 理由は着地ページ (Onboarding/Checkout・Onboarding/BillingRequired) が持つ
 * - JSON/XHR は 402 Payment Required (文言は state で 2 分岐)
 * - allowlist: billing (index/checkout/portal)・onboarding (checkout/activate-personal/
 *   billing-required)・Stripe webhook・組織管理系 route には本 middleware を適用しない
 *   (route 側で group に含めない構造的 allowlist。遮断中でも契約導線 / Customer Portal に
 *   到達できることを保証する = 「契約するための画面が契約してないと見られない」詰みの防止)
 *
 * 対象 organization の解決:
 *   route の `{organization}` binding **だけ**を使う (家系裁定 AG-037: 組織文脈は URL だけで
 *   決まる。保持列は撤去済み)。非メンバー / 不在 org は **認可より前に 404**
 *   (テナント存在秘匿)。通常は MembershipScopedOrganizationBinder が 404 済みのため到達しない
 *   defense-in-depth (binder 回帰時の最終防波堤。403 を返すと存在が漏れる)。
 *   **binding が無ければ fail-closed (500)** — 課金ゲート配下に組織を持たない route が
 *   紛れ込んだら黙って通さない。「課金ゲート配下の全 route は組織引数を持つ」は
 *   BillingGateRouteOrganizationParamTest が固定する。
 */
final class RequireActiveSubscription
{
    /**
     * JSON/XHR の 402 文言 (ブラウザ経路の理由提示は着地ページが担う)。
     *
     * BLOCKED_MESSAGE は「有償契約 + 支払い不健全」(= ExpiredCheckout) の既存契約文言で不変。
     * NO_PLAN_MESSAGE はゲート反転で新たに生まれた遮断事由 (未契約 = NoSubscription /
     * PendingCheckout) 専用。
     */
    private const string BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';

    private const string NO_PLAN_MESSAGE = 'ご利用にはプランの選択が必要です。';

    public function __construct(
        private readonly BillingAccess $access,
        private readonly OnboardingReturnResolver $returnResolver,
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

        $state = $this->access->state($organization);
        if ($state->grantsAccess()) {
            return $next($request);
        }

        // JSON/XHR は 402 (文言は遮断事由で 2 分岐)
        if ($request->expectsJson()) {
            abort(
                Response::HTTP_PAYMENT_REQUIRED,
                $state === OnboardingBillingState::ExpiredCheckout ? self::BLOCKED_MESSAGE : self::NO_PLAN_MESSAGE,
            );
        }

        $canManage = Gate::forUser($user)->allows('manageBilling', $organization);

        // manageBilling 保持 (= 自分で契約できる) かつ safe method (GET/HEAD) の意図遷移の
        // ときだけ「やりたかった destination」を org-scoped session に保存する。契約完了着地で
        // 復帰導線に使う。POST は意図遷移ではない (元 path 復元に意味がない) ため保存しない。
        // XHR/JSON はここに到達しない (直前の 402 abort で除外済み = 判定を二重化しない)。
        // open-redirect は normalizePath が same-origin 内部 path のみ許可して防ぐ。
        $isSafe = in_array($request->getMethod(), ['GET', 'HEAD'], true);
        if ($canManage && $isSafe) {
            $this->returnResolver->rememberForOrganization($organization, '/'.ltrim($request->path(), '/'));
        }

        // 直前 hop で積まれた通知 (例: 招待受諾の success) が、この gate-redirect の
        // 1 hop で消費され失われないよう延命する。**通知だけ**を延命する (reflash() にしない):
        // API キー平文のような 1 度きり表示の内部状態まで持ち越さないため。
        FlashNotificationRelay::relayTo($request->session());

        // 遮断理由は着地ページが持つ (middleware は error flash を積まない)。
        // ★着地先も組織 URL 配下なので、**識別名を明示して**組み立てる (家系裁定 AG-037)。
        //   モデルをそのまま渡すと getRouteKeyName() = 'id' により URL に id が入る。
        return redirect()->route(
            $canManage
                ? 'onboarding.checkout'          // 自分で契約できる = プラン選択へ
                : 'onboarding.billing-required', // 契約権限なし = 説明画面へ
            ['organization' => $organization->slug],
        );
    }

    /**
     * gate 対象の Organization を解決する。**URL の binding だけ**が出所である。
     * binding が無い = 配線ミスなので fail-closed (500)。
     */
    private function resolveOrganization(Request $request, User $user): Organization
    {
        $organization = $request->route('organization');
        Assert::isInstanceOf($organization, Organization::class);

        // defense-in-depth: 非メンバーがここに到達する = binder の回帰。
        // 存在秘匿方針に合わせ 404 で abort し、観測可能化して即検知する。
        // ★判定は **binder と同じ契約 (organization_user の所属)** で行う。役割の有無で
        //   判定すると、所属はあるが役割が無い利用者 (並行受諾レースの帰結) の 403 が
        //   404 に化けて層 2 と層 3 の境目が消える (拒否の理由が読めなくなる)。
        if (! $organization->users()->whereKey($user->getKey())->exists()) {
            Log::warning('non-member passed organization binder (binder regression?)', [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'route' => $request->route()?->getName(),
            ]);
            abort(404);
        }

        return $organization;
    }
}
