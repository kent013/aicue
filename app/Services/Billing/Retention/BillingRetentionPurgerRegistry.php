<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\Services\Billing\Contracts\BillingRetentionPurger;
use Webmozart\Assert\Assert;

/**
 * 保持期間 purger の**実行順つき目録**。
 *
 * ★順序は契約である: **子 target を親より先に置く**。親 (`subscriptions`) を先に消すと
 *   FK cascade で子 (`subscription_items`) が件数報告を経由せず道連れになり、
 *   「何件消したか」の報告が嘘になる。
 *
 * 登録漏れの purger は実行されず期限超過が黙って残るため、
 * `BillingRetentionTargetInventoryTest` が「ディレクトリ上の実装 ⇔ 本目録 ⇔ enum の target」の
 * 3 者 exact-fit を deny-by-default で機械強制する。
 */
final class BillingRetentionPurgerRegistry
{
    /**
     * 実行順の purger 実装クラス。
     *
     * @return list<class-string<BillingRetentionPurger>>
     */
    public static function purgerClasses(): array
    {
        return [
            StripeWebhookEventPurger::class,
            BillingCheckoutSessionPurger::class,
            TicketCheckoutSessionPurger::class,
            TicketAutoRechargeAttemptPurger::class,
            // 子 → 親 の順 (入れ替えない)
            SubscriptionItemPurger::class,
            SubscriptionPurger::class,
            // 台帳は物理削除ではなく畳み込みで決着する (残高を保存する操作)。
            // 他 target と親子関係を持たないため順序制約は無いが、最後に置いて
            // 「削除で決着する群」と「畳み込みで決着する群」を出力上も分ける。
            TicketLedgerEntryPurger::class,
        ];
    }

    /**
     * 実行順の purger インスタンス。
     *
     * @return list<BillingRetentionPurger>
     */
    public function purgers(): array
    {
        $purgers = [];
        foreach (self::purgerClasses() as $class) {
            $purger = app($class);
            Assert::isInstanceOf($purger, BillingRetentionPurger::class);
            $purgers[] = $purger;
        }

        return $purgers;
    }
}
