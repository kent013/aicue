<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SourceDocument: SOP (作業手順書) ファイル (doc/10 §10.1)。VideoManual 配下。
     * Tier B (schema 先取り)。extracted_json は SOP 抽出結果 (AI 解析フェーズで書き込む)。
     */
    public function up(): void
    {
        Schema::create('source_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime');
            $table->bigInteger('size_bytes');
            $table->json('extracted_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_documents');
    }
};
