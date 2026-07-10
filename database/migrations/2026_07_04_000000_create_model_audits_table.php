<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 運営 Admin の Critical Action による Eloquent 属性 diff の保存先 (owen-it/laravel-auditing)。
 *
 * パッケージ提供 migration は publish せず自前で型固定 (driver 差吸収):
 *  - `old_values` / `new_values`: longText (MariaDB の text 64KB 上限を回避)
 *  - `tags`: string(500) (UUIDv7 admin_action_id + multi-tag 余裕)
 *  - `user_agent`: string(1023)
 *  - index: [user_id, user_type] / morphs auto / created_at
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_audits', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event');
            $table->morphs('auditable');
            $table->longText('old_values')->nullable();
            $table->longText('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'user_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_audits');
    }
};
