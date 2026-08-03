<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
use App\DataTransferObjects\Billing\InvoiceStateDto;
use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
use App\Models\Organization;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\PaymentMethod;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Invoice;
use Stripe\PaymentIntent;
use Webmozart\Assert\Assert;

/**
 * AutoRechargeGatewayInterface の Cashier (Stripe SDK) 実装。
 *
 * Cashier のヘルパは per-request idempotency key を公開しないため、Cashier の Stripe
 * クライアント (`$organization->stripe()`) を直接使う (CashierTicketCheckoutGateway と同型)。
 *
 * **税の扱い**: AI-CUE のチケット価格は税込単価 (`ticket_volume_prices.unit_amount`) で、
 * Checkout 経路も `automatic_tax` / `tax_rates` を使わない (`CashierTicketCheckoutGateway`
 * の invariant)。invoice 経路も同一にすることで `amount_due = quantity × unit_amount` が
 * 成立し、`AutoRechargeService::recordSuccessfulCharge` の amount cross-check が機能する。
 */
final class CashierAutoRechargeGateway implements AutoRechargeGatewayInterface
{
    public function createSetupCheckout(
        Organization $organization,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): array {
        // mode=setup は決済を伴わないカード保存。無料パーソナル (サブスクなし) でも
        // createOrGetStripeCustomer が customer を作成して通す。
        $organization->createOrGetStripeCustomer();
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織ではカード登録を開始できません');

        $session = $organization->stripe()->checkout->sessions->create([
            'mode' => 'setup',
            'customer' => $customerId,
            'currency' => 'jpy',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ], ['idempotency_key' => $idempotencyKey]);

        return [
            'id' => $session->id,
            'url' => is_string($session->url) ? $session->url : null,
        ];
    }

    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string {
        Assert::greaterThan($quantity, 0);

        $organization->createOrGetStripeCustomer();
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では invoice を作れません');

        $stripe = $organization->stripe();

        // 順序: draft invoice を先に作り、invoice 指定で item を作る (dangling invoice item 防止)。
        // auto_advance=false で Stripe の自動 finalize / 自動回収 (Smart Retries/dunning) を切る —
        // 「失敗 = void/delete による終端保証」の前提 (遅延成功の二重課金を構造的に排除)。
        $invoice = $stripe->invoices->create([
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'auto_advance' => false,
            'metadata' => $metadata,
        ], ['idempotency_key' => "{$idempotencyKeyBase}:invoice"]);

        $invoiceId = $invoice->id;
        Assert::stringNotEmpty($invoiceId, 'Stripe invoice id missing');

        // basil (2025-08-27) API: トップレベル 'price' は廃止。'pricing' => ['price' => ...] を使う。
        $stripe->invoiceItems->create([
            'customer' => $customerId,
            'invoice' => $invoiceId,
            'pricing' => ['price' => $priceId],
            'quantity' => $quantity,
            'metadata' => $metadata,
        ], ['idempotency_key' => "{$idempotencyKeyBase}:item"]);

        return $invoiceId;
    }

    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
    {
        $stripe = Cashier::stripe();

        // Stripe invoice 状態機械: draft → finalize → open → pay → paid。
        // 既 finalize 済 (リコンサイル再実行) は invalid_request になり得るため許容して pay へ進む。
        try {
            $stripe->invoices->finalizeInvoice(
                $invoiceId,
                ['auto_advance' => false],
                ['idempotency_key' => "{$idempotencyKeyBase}:finalize"],
            );
        } catch (InvalidRequestException $e) {
            if (! str_contains((string) $e->getMessage(), 'finalized')) {
                throw $e;
            }
        }

        try {
            // basil API では Invoice に payment_intent が直載りしない。InvoicePayment を expand し
            // payments.data[].payment.payment_intent から PI id を解決する。
            $paid = $stripe->invoices->pay($invoiceId, [
                'off_session' => true,
                'expand' => ['payments.data.payment'],
            ], ['idempotency_key' => "{$idempotencyKeyBase}:pay"]);
        } catch (CardException $e) {
            // card_declined / authentication_required 等 → typed 失敗 (終端判断は Service 層)
            return OffSessionChargeResultDto::failed(
                $invoiceId,
                is_string($e->getStripeCode()) ? $e->getStripeCode() : null,
                is_string($e->getDeclineCode()) ? $e->getDeclineCode() : null,
            );
        }

        $amountPaid = $paid->amount_paid;
        $amountDue = $paid->amount_due;
        Assert::integer($amountPaid);
        Assert::integer($amountDue);

        return OffSessionChargeResultDto::paid($invoiceId, $amountPaid, $amountDue, $this->extractPaymentIntentId($paid));
    }

    public function terminateInvoice(string $invoiceId): void
    {
        $stripe = Cashier::stripe();

        try {
            $invoice = $stripe->invoices->retrieve($invoiceId);
        } catch (InvalidRequestException $e) {
            if ($e->getHttpStatus() === 404) {
                return; // 冪等: 存在しない (draft delete 済み含む) は成功扱い
            }

            throw $e;
        }

        $status = $invoice->status;

        if ($status === 'void' || $status === 'deleted') {
            return; // 冪等: 終端済み
        }

        // paid を誤って終端しない (付与経路の管轄)。uncollectible は Stripe 上 void 可能かつ
        // 後から支払われ得るため、終端保証の対象に含めて void する (放置すると遅延成功の穴になる)。
        Assert::true(
            $status === 'draft' || $status === 'open' || $status === 'uncollectible',
            "invoice {$invoiceId} は終端できない状態です (status={$status})",
        );

        if ($status === 'draft') {
            // draft は void 不可 (Stripe 制約) — delete で終端する
            $stripe->invoices->delete($invoiceId);

            return;
        }

        $stripe->invoices->voidInvoice($invoiceId);
    }

    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
    {
        $stripe = Cashier::stripe();

        try {
            // nested expand で PaymentIntent object まで取得する — SCA (requires_action) 判定の出典。
            $invoice = $stripe->invoices->retrieve($invoiceId, ['expand' => ['payments.data.payment.payment_intent']]);
        } catch (InvalidRequestException $e) {
            if ($e->getHttpStatus() === 404) {
                return new InvoiceStateDto('deleted', null, null, null, false, null);
            }

            throw $e;
        }

        $status = $invoice->status;
        Assert::stringNotEmpty($status, 'Stripe invoice status missing');

        return new InvoiceStateDto(
            $status,
            $invoice->amount_paid,
            $invoice->amount_due,
            $this->extractPaymentIntentId($invoice),
            $this->invoiceRequiresAction($invoice),
            is_string($invoice->hosted_invoice_url) ? $invoice->hosted_invoice_url : null,
        );
    }

    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto
    {
        $pm = $organization->defaultPaymentMethod();

        if (! $pm instanceof PaymentMethod) {
            return DefaultPaymentMethodDto::none();
        }

        $stripePm = $pm->asStripePaymentMethod();
        $card = $stripePm->card;

        return new DefaultPaymentMethodDto(
            $stripePm->id,
            $card?->brand,
            $card?->last4,
        );
    }

    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string
    {
        $setupIntent = Cashier::stripe()->setupIntents->retrieve($setupIntentId);
        $paymentMethod = $setupIntent->payment_method;

        if ($paymentMethod instanceof \Stripe\PaymentMethod) {
            return $paymentMethod->id;
        }

        Assert::stringNotEmpty($paymentMethod, "setup_intent {$setupIntentId} に payment_method がありません");

        return $paymentMethod;
    }

    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        // Cashier の updateDefaultPaymentMethod は「既 default なら no-op / attach 済みなら再 attach
        // しない / invoice_settings.default_payment_method 設定 / pm_type・pm_last_four snapshot」まで
        // 面倒を見る冪等実装。Stripe 側状態の取得・更新をここに一元化する。
        $organization->updateDefaultPaymentMethod($paymentMethodId);
    }

    /**
     * expanded InvoicePayment の PaymentIntent から SCA (requires_action) 待ちかを判定する。
     * webhook 到着順・local failure_code に依存しない判定源。
     */
    private function invoiceRequiresAction(Invoice $invoice): bool
    {
        $payments = $invoice->payments;
        if ($payments === null) {
            return false;
        }

        foreach ($payments->data as $invoicePayment) {
            $paymentIntent = $invoicePayment->payment->payment_intent ?? null;

            // expand が効かず string id の形状でも SCA を見逃さない — gateway 内で
            // PaymentIntent を retrieve して状態を読む (fallback)。
            if (is_string($paymentIntent) && $paymentIntent !== '') {
                $paymentIntent = Cashier::stripe()->paymentIntents->retrieve($paymentIntent);
            }
            if (! $paymentIntent instanceof PaymentIntent) {
                continue;
            }
            if ($paymentIntent->status === 'requires_action') {
                return true;
            }
            $lastError = $paymentIntent->last_payment_error;
            if ($lastError !== null && ($lastError->toArray()['code'] ?? null) === 'authentication_required') {
                return true;
            }
        }

        return false;
    }

    /**
     * basil API: invoice.payments (InvoicePayment collection) から default payment の
     * PaymentIntent id を解決する。
     */
    private function extractPaymentIntentId(Invoice $invoice): ?string
    {
        $payments = $invoice->payments;
        if ($payments === null) {
            return null;
        }

        foreach ($payments->data as $invoicePayment) {
            $paymentIntent = $invoicePayment->payment->payment_intent ?? null;

            if (is_string($paymentIntent) && $paymentIntent !== '') {
                return $paymentIntent;
            }
            if ($paymentIntent instanceof PaymentIntent) {
                return $paymentIntent->id;
            }
        }

        return null;
    }
}
