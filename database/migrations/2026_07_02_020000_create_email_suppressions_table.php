<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();
            // 正本: 正規化平文 email (lower+trim)。送信前チェックの一致判定に使う。
            // PII。ログ出力禁止 (ログは email_hash を使う)。
            $table->string('email')->unique();
            // 検索補助 + ログ用。EmailHash::compute (HMAC-SHA256+app.key)。
            // APP_KEY rotation で過去 hash と突合不可になるが、email 正本は不変なので lookup は壊れない。
            $table->string('email_hash')->index();
            // bounce / complaint。EmailSuppressionReason enum と一致。
            $table->string('reason');
            // 最後に受けた SNS 通知の messageId (参照値、上書きされる)。
            $table->string('provider_message_id')->nullable();
            // 抑止が確定した時刻 (SES 通知の timestamp 由来 or 受信時刻)。
            $table->timestamp('suppressed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
