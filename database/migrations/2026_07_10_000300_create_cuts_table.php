<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cut: シナリオのカット (doc/10 §10.1)。VideoManual 配下。Tier B (schema 先取り)。
     *
     * 自己参照 FK (parent_cut_id) と循環 FK (adopted_take_id ↔ takes.cut_id) は
     * この時点では張らない (DB 非依存で安全に構築するため takes 作成後の
     * 2026_07_10_000500_add_foreign_keys_to_cuts_table で後付けする)。
     */
    public function up(): void
    {
        Schema::create('cuts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_cut_id')->nullable();
            $table->unsignedBigInteger('adopted_take_id')->nullable();
            $table->string('type');
            $table->string('shot_type');
            $table->string('material_type')->nullable();
            $table->integer('sort_order');
            $table->text('scene');
            $table->text('shooting_point')->nullable();
            $table->text('narration');
            $table->string('subtitle_primary', 100)->nullable();
            $table->text('subtitle_secondary');
            $table->integer('static_display_seconds')->nullable();
            $table->integer('cut_length_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuts');
    }
};
