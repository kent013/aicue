<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P5: チケット残高の per-source 会計 (aigenba TicketService verbatim) のための additive 2 列。
     *
     * 「消費する期間 = 予約した期間」を予約行へ固定し、commit が再探索しないようにする。
     * - consume_source: 予約が消費する出所 (monthly | purchased。App\Enums\Billing\TicketSource)
     * - consume_expires_at: monthly 消費の失効境界 (null = 無期限 monthly または legacy)
     *
     * **backfill しない**。デプロイ時に in-flight だった既存 Reserved 行は 2 列 null (= legacy)
     * のまま残し、誤配賦を固定しない / 並行 reserve と競合させない。legacy 行の扱いは
     * TicketLedgerService::commit (consume_source ?? Monthly + 予約 TTL 境界) が担い、
     * 5 分 cron の releaseStale が TTL 30 分で window を終息させる。
     *
     * **新規 index を追加しない**: hold 集計は既存 ['organization_id','status']、
     * releaseStale は既存 ['status','expires_at'] で覆われる (予約行は org あたり TTL 30 分の少数)。
     */
    public function up(): void
    {
        Schema::table('ticket_reservations', function (Blueprint $table) {
            $table->string('consume_source')->nullable()->after('amount');
            $table->timestamp('consume_expires_at')->nullable()->after('consume_source');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_reservations', function (Blueprint $table) {
            $table->dropColumn(['consume_source', 'consume_expires_at']);
        });
    }
};
