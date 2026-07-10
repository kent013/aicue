<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // name / email は CipherSweet 暗号化カラム (ciphertext のため text)。
            // email の一意性は平文 unique では担保できないため、blind_indexes テーブルの
            // unique index (別 migration) + 登録時の whereBlind 明示チェックで担保する。
            $table->text('name');
            $table->text('email');
            $table->timestamp('email_verified_at')->nullable();
            // SSO-only ユーザー (UserFactory::ssoOnly() / password 未設定) を許容するため
            // nullable。password 経路の可否判定は User::hasPassword() が fail-closed で行う
            $table->string('password')->nullable();
            // 利用規約同意の証跡 ($fillable 外、登録 Action が forceFill で記録)
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('consent_version')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
