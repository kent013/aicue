<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 既存の past_due 行の猶予起点を **migration 実行時刻** で埋める。
 *
 * 実際に失敗した時刻は復元できない (Stripe の請求履歴からの推定は移行のために外部 API を
 * 叩くことになり、得られるのは数日の厳密さだけなので採らない)。よって「猶予はこのデプロイ時点
 * から数え直す」という意味を持たせる = 移行と同時に既存利用者を遮断しない (遡って遮断すると
 * 告知なしに突然止まる)。
 *
 * 冪等 (whereNull ガード)。down() は「どの行が migration 起因か」を識別できないため意図的に no-op。
 * **手動 SQL / tinker でこの列を書かない** (書込の単一化は runbook にも明記する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('stripe_status', 'past_due')
            ->whereNull('past_due_since')
            ->update(['past_due_since' => CarbonImmutable::now()]);
    }

    public function down(): void
    {
        // backfill の巻き戻しは「どの行が migration 起因か」を識別できないため意図的に no-op。
    }
};
