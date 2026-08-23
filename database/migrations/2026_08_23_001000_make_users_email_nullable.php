<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 企業 SSO でしか入れない利用者は**使えるメールを 1 件も持たない** (正典 v1 の always-JIT)。
 *
 * ★email の一意性は平文 unique ではなく blind_indexes の **partial unique** が担うため、
 *   null 化しても一意性の担保は変わらない (null 行は blind index を持たない)。
 * ★仮のメール文字列 (`sub@example.invalid` 等) は作らない —
 *   偽のメールは衝突と誤送の温床であり、nOAuth の再現面と衝突する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('email')->nullable(false)->change();
        });
    }
};
