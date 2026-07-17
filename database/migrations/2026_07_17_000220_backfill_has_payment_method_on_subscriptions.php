<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 既存 subscription 行の `has_payment_method` を true へ backfill する。
 *
 * 列既定の false は「trial 中カード無し signup」経路が存在する前提の値 (移植元の既定)。
 * AI-CUE の subscription 生成経路は Checkout (mode=subscription) のみで PM 収集が必須のため、
 * 既存行の事実値は true。これにより判定モデル置換 (deriveEntitlement) の
 * 「trial 終了 & PM 無し = 遮断」に既存の有償組織が該当しない (デプロイ時点で該当 0 件)。
 *
 * `recordPaymentMethodSnapshot()` は monotonic (true→false に戻さない) のため backfill 値は
 * 以後保存される。冪等 (where ガード)。down() は「どの行が migration 起因か」を識別できないため
 * 意図的に no-op。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('has_payment_method', false)
            ->update(['has_payment_method' => true]);
    }

    public function down(): void
    {
        // backfill の巻き戻しは「どの行が migration 起因か」を識別できないため意図的に no-op。
    }
};
