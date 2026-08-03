<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9 (T081 / T1004): サブスク契約 Checkout に「資金選択」と「PM 流用 Job dispatch marker」を足す。
 *
 * - funding_choice: Onboarding/Checkout の資金 2 択 (SignupFundingChoice の値)。
 *   `auto_recharge` の有償契約だけが T1004 (サブスク決済カードのオートリチャージ流用) の対象。
 *   Plans 経路 (契約変更) は funding 提示が無いため null。
 * - pm_reuse_dispatched_at: ReuseSubscriptionPaymentMethodJob を dispatch した事実の永続マーカー。
 *   決済確定 (payment_status ∈ {paid, no_payment_required}) の completed でのみ立つため、
 *   「オートリチャージが自動的に有効になります」表示の唯一の出典になる
 *   (updated_at / completed_at は未決済 completed で窓が誤って開くため使わない)。
 *   webhook の forceFill 専用 marker のため $fillable には入れない。
 *
 * P2 所管テーブルへの **additive 列追加のみ** (既存列・index・UNIQUE は触らない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_checkout_sessions', function (Blueprint $table): void {
            $table->string('funding_choice', 16)->nullable()->after('plan_code');
            $table->timestamp('pm_reuse_dispatched_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_checkout_sessions', function (Blueprint $table): void {
            $table->dropColumn(['funding_choice', 'pm_reuse_dispatched_at']);
        });
    }
};
