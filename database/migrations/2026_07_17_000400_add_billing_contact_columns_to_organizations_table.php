<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9 (T081): 請求先連絡先 (メール / 宛名)。
 *
 * 両列とも **CipherSweet の ciphertext** を格納するため `text()` を使う
 * (暗号文は元値より長くなるため string(255) では溢れる)。
 * blind index 用の列は作らない — spatie/laravel-ciphersweet の共有 `blind_indexes`
 * morph テーブルに入る (`Organization::configureCipherSweet()` 参照)。
 *
 * 一意制約は張らない (複数組織が同一請求先メールを持つのは正当)。
 * NOT NULL 化・backfill も行わない (未設定時は owner email へ fallback する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->text('billing_contact_email')->nullable()->after('slug');
            $table->text('billing_contact_name')->nullable()->after('billing_contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['billing_contact_email', 'billing_contact_name']);
        });
    }
};
