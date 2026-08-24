<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 企業 SSO のログイン試行。**state の使用権の唯一性**を DB の一意制約と行ロックで担保する
 * (セッションドライバの種別と `->block()` の書き忘れに依存させない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_sso_login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();

            // state の **指紋だけ**を持つ (原文を保存しない)。一意制約が使用権の唯一性の根拠。
            $table->char('state_fingerprint', 64)->unique();

            // nonce も **指紋だけ**。ID トークンの nonce を同じ用途ラベルで指紋化して定時間比較する。
            $table->char('nonce_fingerprint', 64);

            // 開始したブラウザとの結合 (login CSRF を塞ぐ本体)。
            // セッションへ置いた「結び付けの秘密」の指紋。**session ID は保存しない**。
            $table->char('browser_binding_fingerprint', 64);

            // PKCE の検証子だけは token 交換でそのまま送るので原文が要る → 暗号化して保存。
            $table->text('pkce_verifier_encrypted');

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');   // 期限切れ掃除の走査用
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_sso_login_attempts');
    }
};
