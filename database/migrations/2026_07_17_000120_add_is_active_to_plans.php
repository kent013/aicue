<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans.is_active: プランの公開制御 (料金表・課金ページへの露出可否の唯一の場所)。
 *
 * 既定 true のため既存 free / standard 行の公開状態は変わらない (additive)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
