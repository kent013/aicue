<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AnalysisJob: VideoManual 配下の AI 解析ジョブ (doc/10 §10.1)。
     *
     * - video_manual_id / source_document_id / ticket_reservation_id は保護キー
     *   (payload から受けない。Service が明示代入)
     * - status は string + アプリ層 cast (enum 追加に強い)
     * - ticket_reservation_id は予約の冪等キー (§10.8-1。再試行で二重予約しない)
     * - result_json は中間成果 (作業分解表) の write-only 監査スナップショット
     */
    public function up(): void
    {
        Schema::create('analysis_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued');
            $table->string('step')->nullable();
            $table->unsignedSmallInteger('progress')->nullable();
            $table->foreignId('ticket_reservation_id')->nullable()->constrained('ticket_reservations')->nullOnDelete();
            $table->json('result_json')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['video_manual_id', 'status']); // in-flight 判定
            $table->index(['status', 'updated_at']);      // stale 回復走査
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_jobs');
    }
};
