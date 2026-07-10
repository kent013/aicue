<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RenderJob: VideoManual 配下のレンダ (完成 mp4 / プレビュー) ジョブ (doc/10 §10.1)。
     *
     * - video_manual_id / ticket_reservation_id は保護キー (payload から受けない。Service が明示代入)
     * - kind は §10.8-8「preview と render は別操作種別」の実体 (in-flight 判定・課金有無が異なる)
     * - scenario_version は §10.8-6 の開始時スナップショット (トリガー tx で確定。NOT NULL)
     * - ticket_reservation_id は予約の冪等キー (§10.8-1)。preview は常に NULL
     * - output_path は succeeded 時に設定し、世代交代の削除後は NULL 化する
     */
    public function up(): void
    {
        Schema::create('render_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();
            $table->string('kind');                       // RenderKind: render|preview
            $table->string('status')->default('queued');  // JobStatus (既存 enum 共用)
            $table->string('step')->nullable();           // RenderStep: compose|concat
            $table->unsignedSmallInteger('progress')->nullable();
            $table->unsignedInteger('scenario_version');  // §10.8-6 スナップショット (NOT NULL)
            $table->foreignId('ticket_reservation_id')->nullable()
                ->constrained('ticket_reservations')->nullOnDelete(); // preview は常に NULL
            $table->string('output_path', 1024)->nullable(); // 世代交代の削除後は NULL 化
            $table->text('error')->nullable();
            $table->string('error_code')->nullable();     // RenderErrorCode (3 値で閉じる)
            $table->timestamps();
            $table->index(['video_manual_id', 'kind', 'status']); // in-flight 判定 / 最新 succeeded 解決
            $table->index(['status', 'updated_at']);              // stale 回復走査
            $table->index(['kind', 'status']);                    // org preview 上限 / reconcile 走査
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_jobs');
    }
};
