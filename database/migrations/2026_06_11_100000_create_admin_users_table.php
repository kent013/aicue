<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filament 管理画面 (admin guard) 専用の運用者テーブル。
     *
     * 設計判断: AdminUser も users テーブルと同様に name / email を CipherSweet で
     * 暗号化する (運用者も個人であり PII 保護の例外にしない)。email の一意性は
     * 平文 unique では担保できないため、blind_indexes テーブルの partial unique index
     * (2026_06_11_071100 の blind_indexes_type_name_value_unique。対象型に AdminUser を
     * 含む) + 作成経路の whereBlind 明示チェックで担保する。
     */
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            // name / email は CipherSweet 暗号化カラム (ciphertext のため text)
            $table->text('name');
            $table->text('email');
            $table->string('password');
            // 2 要素認証 (Filament 標準の app authentication カラム構成。
            // encrypted cast で保存されるため text で保持する)
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
