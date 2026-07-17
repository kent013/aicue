<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * personal-free-plan: 初回付与マーカーの backfill。
 *
 * 既に signup grant (`signup_grant:%` の ledger 行) を受けた org は
 * `signup_tickets_granted_at` を最初の付与日時で立てる。マーカー導入前に付与済みの org を
 * 塞ぎ、free 有効化経路での再付与を防ぐ。
 *
 * 冪等 (whereNull ガード)。相関サブクエリの UPDATE は PostgreSQL / SQLite 双方で有効。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')
            ->whereNull('signup_tickets_granted_at')
            ->whereIn('id', DB::table('ticket_ledger_entries')
                ->where('idempotency_key', 'like', 'signup_grant:%')
                ->select('organization_id'))
            ->update([
                'signup_tickets_granted_at' => DB::raw(
                    '(select min(granted_at) from ticket_ledger_entries'
                    .' where ticket_ledger_entries.organization_id = organizations.id'
                    ." and ticket_ledger_entries.idempotency_key like 'signup_grant:%')"
                ),
            ]);
    }

    public function down(): void
    {
        // backfill の巻き戻しは「どの org が migration 起因か」を識別できないため意図的に no-op。
    }
};
