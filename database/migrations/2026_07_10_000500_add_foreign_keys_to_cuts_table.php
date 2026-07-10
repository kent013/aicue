<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * cuts への FK 後付け (循環 FK 対応の最終段)。
     *
     * - parent_cut_id → cuts.id (自己参照。急所カットの親手順。親削除で null 化)
     * - adopted_take_id → takes.id (循環 FK: takes.cut_id → cuts。採用テイク削除で null 化)
     *
     * CREATE TABLE 内の自己参照/循環 FK は DB 依存で不安定になりうるため、
     * cuts → takes の両テーブル作成後にまとめて張る (詳細設計 施策2)。
     */
    public function up(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->foreign('parent_cut_id')->references('id')->on('cuts')->nullOnDelete();
            $table->foreign('adopted_take_id')->references('id')->on('takes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->dropForeign(['parent_cut_id']);
            $table->dropForeign(['adopted_take_id']);
        });
    }
};
