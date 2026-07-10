<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Category: 動画マニュアルの分類 (doc/10 §10.1)。Project 配下。
     *
     * - project_id は tenant キー (constrained + NOT NULL + cascade)
     * - name は project 内ユニーク (複合 unique)
     * - sort_order は reorder Service のみが更新する (fillable 外)
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->integer('sort_order');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
