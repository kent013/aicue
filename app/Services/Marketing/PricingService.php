<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\DataTransferObjects\Marketing\PricingPlanDto;
use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use Webmozart\Assert\Assert;

/**
 * 料金表 (/pricing) 表示用のプラン一覧を構築する。
 *
 * - プラン台帳は Plan + PlanPrice (DB snapshot) が真実源
 * - 能力値は config/quota.php の limits の「値」だけを読む
 *   (コードにプラン名分岐を書かない規約どおり。limits に無い key は無制限 = null)
 *
 * 注意: デフォルト解決 (非 singleton) 前提。メモ化はリクエスト内キャッシュとして
 * 設計しているため singleton 登録するとリクエスト間で価格変更が反映されなくなる。
 */
final class PricingService
{
    /** @var list<PricingPlanDto>|null リクエスト内メモ化 */
    private ?array $memoizedPlans = null;

    /**
     * 公開プラン一覧 (sort_order 昇順)。価格は plan_prices current (kind=base)。
     *
     * @return list<PricingPlanDto>
     */
    public function listPublicPlans(): array
    {
        if ($this->memoizedPlans !== null) {
            return $this->memoizedPlans;
        }

        $quotaPlans = config('quota.plans');
        Assert::isArray($quotaPlans);

        return $this->memoizedPlans = array_values(Plan::query()->orderBy('sort_order')->get()
            ->map(function (Plan $plan) use ($quotaPlans): PricingPlanDto {
                $limits = $quotaPlans[$plan->code] ?? [];
                Assert::isArray($limits);
                $price = $plan->currentPrice(PlanPriceKind::Base);

                return new PricingPlanDto(
                    code: $plan->code,
                    name: $plan->name,
                    baseAmountJpy: $price?->amount,
                    monthlyTicketGrant: $plan->monthly_ticket_grant,
                    maxProjects: self::intOrNull($limits, 'max_projects'),
                    maxMembers: self::intOrNull($limits, 'max_members'),
                    maxStorageGb: self::storageGb($limits),
                );
            })
            ->all());
    }

    /**
     * limits から int 値を安全に取り出す (無い key = 無制限 = null)。
     *
     * @param  array<mixed>  $limits
     */
    private static function intOrNull(array $limits, string $key): ?int
    {
        $value = $limits[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * max_storage_bytes を GiB 換算の表示値へ変換する (intdiv 切り捨て。
     * free: 1GiB→1 / standard: 50GiB→50。Feature テストと Vitest の期待値もこの規則に固定)。
     *
     * @param  array<mixed>  $limits
     */
    private static function storageGb(array $limits): ?int
    {
        $bytes = self::intOrNull($limits, 'max_storage_bytes');

        return $bytes === null ? null : intdiv($bytes, 1024 ** 3);
    }
}
