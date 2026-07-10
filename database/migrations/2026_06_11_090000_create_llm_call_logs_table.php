<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LLM 呼び出しのコスト記録 (1 execution = 1 row)。コスト監視・モデル別集計に使う。
     * 記録は RecordLlmCallCost / RecordLlmCallFailure listener → LlmCallLogWriter 経由のみ。
     */
    public function up(): void
    {
        Schema::create('llm_call_logs', function (Blueprint $table): void {
            $table->id();

            // 冪等 upsert のキー (同一 execution の再記録は last-write-wins backfill)
            $table->string('execution_id', 64)->unique();

            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // subject morph。subject_id は int 主キーと ULID (26 文字) の双方を
            // 収められるよう string(64)
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();

            $table->string('prompt_class');
            $table->string('prompt_template')->nullable();

            $table->string('provider');
            $table->string('model');
            // FinishReason enum の value。失敗系は sentinel 'failed'
            $table->string('finish_reason');
            $table->unsignedSmallInteger('step_count')->default(1);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_input_tokens')->nullable();
            $table->unsignedInteger('cache_read_input_tokens')->nullable();
            $table->unsignedInteger('thought_tokens')->nullable();

            // コストの null は upstream の pricing 解決失敗。0.0 は unknown モデルの
            // zero-cost snapshot (正常系) なので区別して保持する
            $table->decimal('input_cost_usd', 12, 6)->nullable();
            $table->decimal('output_cost_usd', 12, 6)->nullable();
            $table->decimal('total_cost_usd', 12, 6)->nullable();

            // pricing_snapshot は呼び出し時点の価格表 (後から価格が変わっても記録は不変)
            $table->json('pricing_snapshot')->nullable();
            $table->json('fx_snapshot')->nullable();
            $table->decimal('total_cost_jpy', 12, 2)->nullable();

            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('request_id')->nullable();

            $table->boolean('metadata_missing')->default(false);
            $table->string('failure_reason', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['model', 'created_at']);
            $table->index('prompt_template');
            $table->index('metadata_missing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_call_logs');
    }
};
