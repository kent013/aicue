<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Organization;

/**
 * 組織が業務機能を利用してよいか (billing entitlement) の判定。
 *
 * **課金による利用可否の判定は必ず本クラスを経由する** (middleware / controller /
 * service での subscription 直参照は禁止)。判定基準を 1 クラスに閉じ込めることで、
 * アプリ側は本クラスの書き換えだけで gate 方針を変更できる。
 *
 * AI-CUE の entitlement 方針 (テンプレート既定の「active subscription 必須」からの
 * 意図的な書き換え。devnotes/20260712-0927-bugfix-billing-free-access):
 *
 * - plan_code null (未契約) = fallback free プラン。**支払い不要 tier としてアクセス許可**。
 *   有償価値は別レイヤで gate 済み (チケット残高 = analyze/render、Quota = max_projects 等)
 * - plan_code 非 null = 有償プラン契約状態。subscription('default') が active / trialing の
 *   ときのみ許可 (past_due / canceled / incomplete / 行不在は fail-closed で不許可 =
 *   支払い健全性の担保のみが本ゲートの責務)
 *
 * 不変条件 (依存するデータモデル契約): `organizations.plan_code` は Stripe Price を持つ
 * 有償プランの契約時のみ StripeWebhookProcessor が set し、subscription.deleted で null に
 * 戻す。支払い不要のプランを plan_code に載せる場合は本判定とセットで見直すこと
 * (挙動は RequireActiveSubscriptionMiddlewareTest が固定する)。
 *
 * 注: 本メソッドは「subscription を持つか」ではなく「業務ルートを利用してよいか
 * (billing entitlement)」を返す。free 組織は subscription 無しで true になる。
 */
class BillingAccess
{
    /** アクセスを許可する Stripe subscription status (有償プラン契約時のみ参照) */
    private const array GRANTING_STATUSES = ['active', 'trialing'];

    public function hasActiveAccess(Organization $organization): bool
    {
        // 未契約 (plan_code null) = fallback free プラン。支払い不要 tier として許可
        if ($organization->plan_code === null) {
            return true;
        }

        // 有償プラン契約状態: 支払い健全性 (active/trialing) を要求。
        // 行不在 (webhook 順序逆転等) も fail-closed で不許可
        $subscription = $organization->subscription('default');

        return $subscription !== null
            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
    }
}
