<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メールアドレスの昇格 (企業 SSO でしか入れない利用者が自分のメールを持つための確認待ち)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ★トークンは **原文を保存せず指紋だけ** (用途ラベル EmailPromotionToken)。
            //   一意制約が「一回だけ consume できる」の根拠。
            $table->char('token_fingerprint', 64)->unique();

            // 昇格しようとしているメール。**CipherSweet で暗号化する** (PII)。
            // ★ここにも blind index を付けない — 確定するまでは users のメールではないので、
            //   引き当てに使う理由が無い。
            $table->text('email_encrypted');

            $table->timestamp('expires_at');
            $table->timestamps();

            // ★利用者ごとの未消費は **1 件だけ**にする (再送で旧トークンが失効することの DB 側の担保)。
            //   消費は行の削除なので、未消費 = 行が在ることである。
            $table->unique('user_id', 'email_promotions_user_unique');

            $table->index('expires_at');   // 期限切れ掃除の走査用
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_promotions');
    }
};
