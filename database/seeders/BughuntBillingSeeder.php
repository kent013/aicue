<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Database\Seeder;

/**
 * bug-hunt env 専用: 有料プラン組織に active subscription + 初期チケットを付与する。
 *
 * 目的: BillingAccess::hasActiveAccess (subscription default が active/trialing) を
 * 有料プラン組織で true にし、業務ルート (/projects, /app) を bug-hunt で走行可能にする。
 * チケット消費系ジャーニー (AI 解析 / レンダ) のため初期残高も付与する。
 *
 * ★ free 組織には何も付与しない: 「課金なし経路」(billing redirect / 残高ゼロ) を
 *   bug-hunt 環境内に温存し、課金ゲート系バグの検出能力を落とさない (概念設計 施策 4)。
 *
 * 三重 fail-secure (BughuntOAuthSeeder と同一): (1) config('testing.fake_externals') === true、
 * (2) app()->environment('bughunt.local')、(3) DB 名 ^bug_hunt(_[1-8])?$。
 * いずれか欠ければ no-op (production/dev DB に課金状態をばら撒かない)。
 *
 * 冪等 = 「探索前提の active 状態を毎回回復する」。stripe_status が active 以外に変わって
 * いても reseed で active を再保証する。チケットは grantMonthly の idempotency_key で二重付与しない。
 *
 * 依存は Seeder の method injection (run() 引数) で受ける (Laravel 公式作法。型安全)。
 */
class BughuntBillingSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;

    /** 初期チケット付与枚数 (S3 の解析/レンダ探索に十分な決定論値)。 */
    private const int INITIAL_TICKET_GRANT = 100;

    public function run(TicketLedgerService $tickets): void
    {
        if (
            config('testing.fake_externals') !== true
            || ! app()->environment('bughunt.local')
            || ! $this->isBughuntDatabase()
        ) {
            $this->command->warn('BughuntBillingSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');

            return;
        }

        $paidPlanCodes = $this->paidPlanCodes();
        if ($paidPlanCodes === []) {
            $this->command->warn('BughuntBillingSeeder: 有料プランが無いため skip。先に PlanSeeder を流すこと。');

            return;
        }

        $organizations = Organization::query()->whereIn('plan_code', $paidPlanCodes)->orderBy('id')->get();
        foreach ($organizations as $organization) {
            $this->ensureActiveSubscription($organization);
            // 冪等キーで二重付与を防ぐ (reseed は migrate:fresh 後だが、単独再実行にも安全)
            $tickets->grantMonthly(
                $organization,
                self::INITIAL_TICKET_GRANT,
                null,
                "bughunt:initial-grant:{$organization->id}",
                'bug-hunt 初期チケット (探索用)',
            );
        }

        $this->command->info("BughuntBillingSeeder: {$organizations->count()} 組織に active subscription + チケット".self::INITIAL_TICKET_GRANT.' 枚を付与。');
    }

    /**
     * base price を持つプラン (= 有料プラン) の code 一覧。
     *
     * @return list<string>
     */
    private function paidPlanCodes(): array
    {
        // pluck は list<mixed> になるため map で string を実型でも保証し、
        // array_values で list 型を確定させる (PHPStan level 10)
        return array_values(
            Plan::query()->orderBy('sort_order')->get()
                ->filter(fn (Plan $plan): bool => $plan->currentPrice(PlanPriceKind::Base) !== null)
                ->map(fn (Plan $plan): string => $plan->code)
                ->all(),
        );
    }

    /**
     * active な default subscription を保証する (payload はここに集約)。
     * 作成は billable relation 経由 (organization_id は FK 自動設定 = guarded 不侵)。
     * stripe_id は決定論 fixture `sub_bughunt_{org id}` = org 単位一意
     * (subscriptions.stripe_id UNIQUE と両立。Stripe には到達しない)。
     */
    private function ensureActiveSubscription(Organization $organization): void
    {
        $existing = $organization->subscription('default');

        if ($existing instanceof Subscription) {
            if ($existing->stripe_status !== 'active') {
                // 探索で past_due 等に変わっていても active を回復 (冪等 = active 再保証)
                $existing->forceFill(['stripe_status' => 'active'])->save();
            }

            return;
        }

        $organization->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => "sub_bughunt_{$organization->id}",
            'stripe_status' => 'active',
            'quantity' => 1,
        ]);
    }
}
