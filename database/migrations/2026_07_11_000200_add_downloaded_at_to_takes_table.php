<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * takes.downloaded_at (概念設計 D6): DL 済み ACK の打刻。
     * 打刻は POST .../takes/{take}/downloaded (署名 ACK トークン検証) 経由のみ (サーバ側)。
     * 非 null のテイクはサーバ削除不可 (他端末に配布済みの素材を消させない誤操作ガード)。
     */
    public function up(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->timestamp('downloaded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->dropColumn('downloaded_at');
        });
    }
};
