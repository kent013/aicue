<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Take: カットの撮影素材 (doc/10 §10.1 + §10.8)。Cut 配下。Tier B (schema 先取り)。
     *
     * - (cut_id, client_take_id) UNIQUE は撮影端末からの同期冪等キー (§10.8-3)
     * - size_bytes は org 単位の容量 Quota 実計上の元値 (§10.8-4。集計は後続フェーズ)
     */
    public function up(): void
    {
        Schema::create('takes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cut_id')->constrained()->cascadeOnDelete();
            $table->string('client_take_id', 26);
            $table->string('video_path');
            $table->string('thumbnail_path')->nullable();
            $table->bigInteger('size_bytes');
            $table->integer('duration_ms')->nullable();
            $table->string('status');
            $table->text('comment')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->integer('sort_order');
            $table->timestamps();

            $table->unique(['cut_id', 'client_take_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('takes');
    }
};
