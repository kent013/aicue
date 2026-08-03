<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;

/**
 * サブスクリプション系 Stripe 呼び出しの抽象
 * (実装: CashierStripeGateway。fake_externals 時は FakeStripeGateway を bind)。
 *
 * Stripe 呼び出しを本 interface に閉じ、Controller / Service は戻り値 DTO の URL へ
 * Inertia::location するのみ。チケット系は TicketCheckoutGateway が担う (境界を分ける)。
 */
interface StripeGatewayInterface
{
    /**
     * subscription (type=default) の hosted Checkout Session を作り snapshot を返す。
     *
     * 戻り値に session id を含むのは **webhook 照合の pin** に必須のため
     * (billing_checkout_sessions.stripe_session_id が真実源になる)。
     * $idempotencyKey は Stripe へそのまま渡す (`sub_start:{attemptToken}`)。
     *
     * @param  array<string, string>  $metadata  照合専用 (認可・org 解決には使わない)
     */
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): CreatedCheckoutSession;

    /**
     * Stripe 側 Checkout Session を expire する (別 plan の live pending 整理)。
     *
     * @return string expire 後の session status ('expired'|'complete'|...)
     */
    public function expireCheckoutSession(string $stripeSessionId): string;

    /**
     * Customer Portal セッションを作り遷移先を返す
     * (configuration は PortalConfigurationSpec 準拠。実装側で解決する)。
     */
    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect;

    /**
     * 請求先連絡先 (name 等) を Stripe Customer に同期する。
     *
     * Cashier の Billable 同期メソッドを job から直接呼ぶと fake 環境 (bug-hunt / Browser) を
     * 素通りして実 Stripe API を叩く。同期も interface 境界を通すことで fake 可能にする。
     * `stripe_id` 未設定の組織は呼び出し側で skip 済の前提 (実装側でも no-op を許容)。
     */
    public function syncCustomerDetails(Organization $organization): void;
}
