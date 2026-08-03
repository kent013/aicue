<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * free プランの撤去 (D11)。後継は `personal` (P1 で seed 済み)。
 *
 * free entitlement は `organizations.free_plan_code='personal'` で表現するようになり、
 * `plans` の free 行は行き場を失った。`PlanSeeder` は `updateOrCreate` のため seeder から
 * 定義を消しても既存 DB の行は残る = 本 data migration で消す。
 *
 * 参照行 (`organizations.plan_code='free'` / free の `plan_prices`) が残存していたら
 * **fail-closed で throw する** (黙って消して参照を壊さない)。free は Stripe Price を持たず
 * plan_code に載る経路が構造的に無いため、残存 0 件が期待値。残存したらデプロイを止めて調査する。
 *
 * down() は `plans` の free 行のみを復元する (config/quota.php はリポジトリ内のため migration が
 * 書き換えられない = rollback は運用手順で config を revert する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        $freePlanId = DB::table('plans')->where('code', 'free')->value('id');
        if ($freePlanId === null) {
            return; // 冪等: 既に撤去済み (未 seed の新規 DB を含む)
        }

        $referencingOrganizations = DB::table('organizations')->where('plan_code', 'free')->count();
        if ($referencingOrganizations !== 0) {
            throw new RuntimeException(
                "cannot remove free plan: {$referencingOrganizations} organization(s) still reference plan_code='free'"
            );
        }

        $prices = DB::table('plan_prices')->where('plan_id', $freePlanId)->count();
        if ($prices !== 0) {
            throw new RuntimeException("cannot remove free plan: {$prices} plan_price(s) still reference it");
        }

        DB::table('plans')->where('code', 'free')->delete();

        $remaining = DB::table('plans')->where('code', 'free')->count();
        if ($remaining !== 0) {
            throw new RuntimeException("free plan removal incomplete: {$remaining} row(s) remain");
        }
    }

    public function down(): void
    {
        if (DB::table('plans')->where('code', 'free')->exists()) {
            return;
        }

        $now = CarbonImmutable::now();
        DB::table('plans')->insert([
            'code' => 'free',
            'name' => 'Free',
            'monthly_ticket_grant' => 0,   // D28: 月次付与は廃止
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
