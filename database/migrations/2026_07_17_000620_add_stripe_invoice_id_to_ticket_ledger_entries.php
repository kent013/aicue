<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8a (D30): オートリチャージ (Checkout 非経由の off-session Invoice 課金) の
 * 「購入アンカー」を台帳に足す。
 *
 * - checkout 購入: stripe_checkout_session_id (従来どおり)
 * - リチャージ購入: stripe_invoice_id (本列)
 *
 * `ticket_purchases` は移植しない (D30 = ユーザー決定 F3「台帳の置換ではない」)。
 * 返金逆引きの正本は既存どおり ledger の payment_intent_id + purchase_amount で、
 * 本列は invoice 起点の監査逆引き用アンカー。既存行・既存経路は不変。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
            $table->string('stripe_invoice_id', 64)->nullable()->after('stripe_checkout_session_id');
            $table->index('stripe_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['stripe_invoice_id']);
            $table->dropColumn('stripe_invoice_id');
        });
    }
};
