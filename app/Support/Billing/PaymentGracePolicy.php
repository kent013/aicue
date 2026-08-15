<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 支払い失敗の猶予 (何日まで使わせるか) を判定する **唯一の正本**。
 *
 * 猶予日数を読む場所・期限を計算する場所をここ 1 つに閉じる (AG-035 (5))。
 * 画面文言・通知・運用スクリプトが日数を再計算して食い違うことを防ぐ。
 *
 * **利用可否そのものは答えない**。可否の確定は SubscriptionService::deriveEntitlement の
 * 一本道であり、本クラスは「起点からの期限が切れたか」だけを答える。
 *
 * **チケット残高切れの猶予は扱わない** (残高 0 は予約時点で即拒否 = 猶予 0 が標準形)。
 */
final class PaymentGracePolicy
{
    /** 猶予日数 (config の唯一の読み口)。負値は設定不備として落とす。 */
    public function graceDays(): int
    {
        $days = config()->integer('billing.payment_grace_days');
        Assert::greaterThanEq($days, 0, '支払い猶予日数には 0 以上を設定してください');

        return $days;
    }

    /** 猶予の期限 (この時刻までは利用継続)。 */
    public function expiresAt(CarbonImmutable $pastDueSince): CarbonImmutable
    {
        return $pastDueSince->addDays($this->graceDays());
    }

    /**
     * 猶予が切れているか。
     *
     * **境界 (ちょうど期限の瞬間) は切れていない扱い**にする (利用者に有利な側へ倒す)。
     */
    public function hasExpired(CarbonImmutable $pastDueSince, CarbonImmutable $now): bool
    {
        return $now->greaterThan($this->expiresAt($pastDueSince));
    }
}
