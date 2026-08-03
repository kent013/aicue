<?php

declare(strict_types=1);

use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ゲート反転 (P4) の declarer-less grandfathering backfill。
 *
 * 反転前は「plan_code IS NULL = 未契約 = 支払い不要の free tier」として素通ししていた。
 * 反転後の判定 (`BillingAccess::state()`) は plan_code を一切見ないため、この経路で許可されて
 * いた既存 org は free entitlement (`free_plan_code='personal'`) を明示的に持たない限り遮断される。
 * 本 migration がゲートコードの活性化より前に当該集合を空にする (= 締め出しゼロ)。
 *
 *   grandfather := { org : plan_code IS NULL ∧ free_plan_code IS NULL ∧ ¬entitled(org) }
 *
 * **entitlement の判定は `SubscriptionService::deriveEntitlement()` に委譲する**
 * (述語を SQL へ写すと `state()` との集合ドリフトが生じる: past_due + 支払い手段有りは
 * entitled = 救ってはいけない / trial 終了 + 支払い手段無しは ¬entitled = 救わねばならない。
 * さらに `subscription('default')` は type='default' の **最新 1 行** のみを見るため EXISTS では
 * 再現できない)。よって対象 ID は PHP で確定し、その ID 集合で UPDATE する。
 *
 * declarer (`personal_declared_by_user_id` / `personal_declared_at`) は NULL のままにする
 * (自己申告の記録が無い既存 org のため。partial unique index
 * `organizations_personal_free_declarer_unique` の対象外 = 1 user 複数 org でも衝突しない)。
 * 初回無償チケットは発火しない (`signup_tickets_granted_at` に触れない = 将来の activate /
 * paid 成立時に 1 回だけ付与される)。
 *
 * 冪等 (`whereNull('free_plan_code')` ガード)。末尾の残余 0 件検証が違反すれば throw し、
 * デプロイを中断してゲートを反転させない。down() は「どの org が migration 起因か」を
 * 識別できないため意図的に no-op (旧コードは free_plan_code を見ないため無害に無視される)。
 */
return new class extends Migration
{
    /** 走査 / UPDATE の chunk サイズ (長時間ロックと N+1 を避ける) */
    private const int CHUNK = 500;

    public function up(): void
    {
        $now = CarbonImmutable::now();
        $targets = $this->collectTargetIds();

        foreach (array_chunk($targets, self::CHUNK) as $ids) {
            DB::table('organizations')->whereIn('id', $ids)->update([
                // migration はアプリ定数に依存させない (drift は invariant テストが固定する)
                'free_plan_code' => 'personal',
                'free_plan_activated_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 残余 0 件検証: 反転後に利用可否が変わる既存 org が 1 件も残っていないこと。
        $remaining = count($this->collectTargetIds());
        if ($remaining !== 0) {
            throw new RuntimeException("grandfather backfill incomplete: {$remaining} org(s) would lose access");
        }
    }

    public function down(): void
    {
        // backfill の巻き戻しは「どの org が migration 起因か」を識別できないため意図的に no-op。
    }

    /**
     * grandfather 対象の org ID を確定する (母集団ガード + ¬entitled)。
     *
     * @return list<int>
     */
    private function collectTargetIds(): array
    {
        $subscriptions = app(SubscriptionService::class);

        /** @var list<int> $targets */
        $targets = [];

        Organization::query()
            ->whereNull('plan_code')        // plan_code 非 null の org は反転で結論が変わらない
            ->whereNull('free_plan_code')   // 既に free entitlement を持つ org は対象外 (冪等)
            ->with('subscriptions')
            // Collection の型パラメータを明示して $organization を Organization に確定させる
            // (無指定だと mixed に落ち、getKey() の戻り値も mixed になって cast.int で落ちる)。
            ->chunkById(self::CHUNK, function (Collection $organizations) use ($subscriptions, &$targets): void {
                /** @var Collection<int, Organization> $organizations */
                foreach ($organizations as $organization) {
                    $subscription = $organization->subscription('default');
                    $entitled = $subscription instanceof Subscription
                        && $subscriptions->deriveEntitlement($subscription)->entitled;

                    if (! $entitled) {
                        $targets[] = $organization->id;
                    }
                }
            });

        return $targets;
    }
};
