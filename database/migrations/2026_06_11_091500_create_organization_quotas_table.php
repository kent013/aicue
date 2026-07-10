<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 多次元 Quota の組織別 override (docs 07 ガイド §4)。
     *
     * 既定値は config/quota.php の plan_code → limits map。本テーブルは
     * 「特定組織だけ上限を変える」運用 override を JSON で持つ (key 単位で上書き)。
     */
    public function up(): void
    {
        Schema::create('organization_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            // 例: {"max_projects": 5, "max_members": 20}
            $table->json('limits');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_quotas');
    }
};
