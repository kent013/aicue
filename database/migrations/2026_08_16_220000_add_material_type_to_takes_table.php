<?php

declare(strict_types=1);

use App\Enums\Manual\MaterialType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * takes.material_type: 登録された素材の**実体**種別 (cuts.material_type は計画で別概念)。
     *
     * 3 段で入れる:
     * 1. nullable で追加 (既存行を壊さない)
     * 2. 既存行を 'video' で backfill — presign は今まで
     *    capture.allowed_video_content_types しか通していないため、既存テイクは全件動画である
     * 3. NOT NULL 化 (DB default は置かない = INSERT 時の明示代入を強制する。
     *    ドメイン規約 1 (ii)/2 と同じ理由で、default に依存すると migration 変更で意味が黙って変わる)
     */
    public function up(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->string('material_type')->nullable()->after('video_path');
        });

        DB::table('takes')->whereNull('material_type')
            ->update(['material_type' => MaterialType::Video->value]);

        Schema::table('takes', function (Blueprint $table): void {
            $table->string('material_type')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('takes', function (Blueprint $table): void {
            $table->dropColumn('material_type');
        });
    }
};
