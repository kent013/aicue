<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * プラン価格のバージョニング管理。
 *
 * 旧 current 行を無効化しつつ新 current を差し込む一連の処理を、
 * 単一DBトランザクションで実行することで二重 current を物理的に防ぐ。
 * 部分ユニーク（生成列 `current_unique_key`）がラスト・リゾートのガード。
 */
class PlanPriceService
{
    /**
     * 指定 kind の current Price を差し替える (旧 current を閉じてから新 current を作る)。
     *
     * $lookupKey は Stripe Price の lookup_key の DB snapshot。`billing:sync-stripe-prices` は
     * 「kind + is_current + lookup_key 一致」で current 行を引くため、null で作ると同期対象を
     * 見失う (詳細設計 D14)。
     */
    public function replaceCurrent(
        Plan $plan,
        PlanPriceKind $kind,
        string $stripePriceId,
        int $amount,
        ?string $lookupKey = null,
        string $currency = 'jpy',
        ?CarbonImmutable $activeFrom = null,
    ): PlanPrice {
        Assert::notEmpty($stripePriceId);
        Assert::greaterThan($amount, 0);

        $from = $activeFrom ?? CarbonImmutable::now();

        return DB::transaction(function () use ($plan, $kind, $stripePriceId, $amount, $lookupKey, $currency, $from): PlanPrice {
            // 先に旧 current を無効化。部分ユニーク制約のため必ず先行実行する。
            $plan->prices()
                ->where('kind', $kind->value)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'active_to' => $from,
                ]);

            /** @var PlanPrice $new */
            $new = $plan->prices()->create([
                'kind' => $kind->value,
                'stripe_price_id' => $stripePriceId,
                'lookup_key' => $lookupKey,
                'amount' => $amount,
                'currency' => $currency,
                'active_from' => $from,
                'active_to' => null,
                'is_current' => true,
            ]);

            return $new;
        });
    }
}
