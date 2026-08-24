<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 識別名の改名履歴 (家系裁定 AG-046 / 不変条件 I12・I13)。
 *
 * ★**一意制約を張らない** — 旧識別名は予約せず**解放する** (I13)。
 *   履歴に unique を張ると旧識別名を他組織が取れなくなり、裁定に反する。
 * ★複合 index `(organization_id, renamed_at)` を張る (30 日窓の回数判定がこの順で走る)。
 * ★`renamed_by_user_id` は `nullOnDelete` (利用者を削除しても改名の履歴は失わない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_slug_renames', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('renamed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('from_slug');
            $table->string('to_slug');
            $table->timestampTz('renamed_at');
            $table->timestamps();

            $table->index(['organization_id', 'renamed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_slug_renames');
    }
};
