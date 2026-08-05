<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ticket_ledger_entries に「1 組織 1 signup grant」を強制する部分 UNIQUE index を追加する。
 *
 * - 述語 `idempotency_key LIKE 'signup_grant:%'` は旧キー (signup_grant:{subId}) と
 *   新 org スコープキー (signup_grant:org:{id}) の双方をカバーし、ローリングデプロイ中の
 *   別キー同時 insert でも二重付与を DB レベルで原子的に防ぐ。
 * - 作成前に既存重複を非破壊監査し、重複があれば fail-closed で停止する
 *   (台帳は append-only。重複補正は別途承認された手順へ分離し、本 migration では触れない)。
 */
return new class extends Migration
{
    private const string INDEX_NAME = 'ticket_ledger_entries_signup_grant_unique';

    public function up(): void
    {
        // 非破壊監査: 同一 organization_id に signup_grant:% 行が 2 件以上あると UNIQUE index は作れない
        $duplicates = DB::table('ticket_ledger_entries')
            ->where('idempotency_key', 'like', 'signup_grant:%')
            ->groupBy('organization_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('organization_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'signup_grant 重複あり (organization_id: '.$duplicates->implode(', ').
                ')。台帳は append-only のため本 migration は補正しない。別途承認された補正手順で解消後に再実行すること。',
            );
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("部分 UNIQUE index 未対応の driver: {$driver} (pgsql/sqlite のみ対応)");
        }

        // pgsql / sqlite はいずれも partial index (WHERE 述語) を支持する
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX_NAME.
            " ON ticket_ledger_entries (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
    }
};
