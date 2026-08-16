<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * サムネイルの実バイト数 (容量 Quota の集計対象)。
     *
     * - `takes.size_bytes` とは**別列**にする。size_bytes は
     *   「予約 (take_upload_reservations.size_bytes) と HeadObject の ContentLength が
     *   三点照合で一致した確定値」であり、その同一性が
     *   StorageUsageService::occupiedBytes() の pending→used 読み取り順の根拠になっている。
     *   事後に生成されるサムネイル分を足し込むとその根拠が読めなくなる。
     * - 生成前 / 生成失敗のテイクは NULL (= 0 として集計する)。既存行も NULL のままでよい。
     * - integer で足りる (出力は config で寸法・品質を固定した JPEG 1 枚)。
     */
    public function up(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->integer('thumbnail_size_bytes')->nullable()->after('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->dropColumn('thumbnail_size_bytes');
        });
    }
};
