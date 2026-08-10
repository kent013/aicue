<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 保持期間 (7 年) の畳み込み (PR-C2) が作る**繰越行**の終端を記録する列を足す。
 *
 * `carried_forward_through` は「この繰越行が集約した期間の終端」= 畳み込み時の保持期限の閾値。
 * 繰越行は**取引記録ではなく現在残高のスナップショット**であり、原取引の識別子を 1 つも
 * 引き継がない。よって「いつまでの取引が畳み込まれたか」を表す唯一の情報がこの列になる。
 *
 * - null = 通常の取引行 (畳み込みで作られた行ではない)。既存行は全て null のままでよい
 * - 再畳み込み (繰越行同士の合算) でも値は**単調に進む** (前回値と今回の閾値の大きい方)
 *
 * 索引は張らない — 本列で検索する経路は無く、畳み込みの抽出条件は `created_at` である。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
            $table->timestamp('carried_forward_through')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('carried_forward_through');
        });
    }
};
