<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item: Project 配下サンプルリソース (テンプレート規約を満たした「正しい追加」の見本)。
     *
     * tenancy マッピング規約 (docs 07 ガイド §2):
     * - 親 FK は constrained() + NOT NULL (nullable な tenant キーを作らない)
     * - 親削除でカスケード (cascadeOnDelete)
     *
     * 派生アプリはこのリソースをリネーム (または参考に複製) してドメインリソースを作る。
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
