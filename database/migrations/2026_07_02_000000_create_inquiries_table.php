<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 公開問い合わせフォームの受付テーブル。
 *
 * PII 列 (name / email / company_name / message) は CipherSweet 暗号化 (ciphertext のため text)。
 * email の blind index は共有 blind_indexes テーブル (inquiry_email_index)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table): void {
            $table->id();
            // 平文の運用検索列 (type / status / source)。
            $table->string('type');
            $table->string('status')->default('open');
            $table->string('source')->nullable();
            // 運用列: 担当 Admin 割当 / 社内メモ (いずれも guarded、Filament / コードで明示代入)。
            $table->foreignId('assigned_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();
            $table->text('internal_note')->nullable();
            // PII 列 (CipherSweet で暗号化)。
            $table->text('name');
            $table->text('email');
            $table->text('company_name')->nullable();
            $table->text('message');
            // 同意の証跡。
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('consent_version')->nullable();
            // retention: status→Closed 遷移時刻 (closed の purge 基準)。
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
            $table->index(['status', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
