<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
use App\DataTransferObjects\Billing\InvoiceStateDto;
use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
use App\Models\Organization;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;

/**
 * AutoRechargeGatewayInterface の runtime fake (fake_externals 環境専用。Stripe に到達しない)。
 *
 * 契約 = FakeTicketCheckoutGateway と同じ「外部ステップを skip した中立帰還」:
 * - setup Checkout は決定的な session id + アプリ内帰還 URL を返す
 * - **課金は一切成立させない** (payOffSessionInvoice は常に card_declined の typed 失敗)。
 *   fake 環境で自動購入が「成功」すると台帳に偽の付与行が残るため、成功側には倒さない
 * - default PM は「無し」を返す = fake 環境ではオートリチャージを有効化できない (fail-closed)
 */
final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
{
    public function createSetupCheckout(
        Organization $organization,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): array {
        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)。
        $token = substr(hash('sha256', $idempotencyKey), 0, 32);

        return [
            'id' => "cs_bughuntfake_setup_{$token}",
            'url' => FakeExternalUrl::neutralReturn($cancelUrl),
        ];
    }

    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string {
        return 'in_bughuntfake_'.substr(hash('sha256', $idempotencyKeyBase), 0, 24);
    }

    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
    {
        // fake は決済を成立させない (偽の付与行を台帳に残さない)。
        return OffSessionChargeResultDto::failed($invoiceId, 'card_declined', 'generic_decline');
    }

    public function terminateInvoice(string $invoiceId): void
    {
        // no-op: fake 環境は実 Stripe を叩かない (終端は常に成功扱い)。
    }

    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
    {
        return new InvoiceStateDto('void', null, null, null, false, null);
    }

    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto
    {
        return DefaultPaymentMethodDto::none();
    }

    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string
    {
        return 'pm_bughuntfake_'.substr(hash('sha256', $setupIntentId), 0, 20);
    }

    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        // no-op: fake 環境は Stripe customer を更新しない。
    }

    public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string
    {
        // 既知 prefix (fake が発行する subscription id) にのみ対の PM id を返し、
        // 未知の id は **null** (= 解決不能) にする。空文字は返さない。
        if (! str_starts_with($stripeSubscriptionId, 'sub_bughuntfake_')) {
            return null;
        }

        return 'pm_bughuntfake_'.substr(hash('sha256', $stripeSubscriptionId), 0, 20);
    }
}
