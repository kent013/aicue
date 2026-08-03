<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
use App\DataTransferObjects\Billing\InvoiceStateDto;
use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
use App\Models\Organization;

/**
 * P8a (D31): オートリチャージ系 Stripe 呼び出しの抽象
 * (実装: CashierAutoRechargeGateway。fake_externals 時は FakeAutoRechargeGateway を bind)。
 *
 * AI-CUE の「狭い gateway + gateway 単位の Fake bind」規約を維持する
 * (サブスク系 = StripeGatewayInterface / チケット checkout 系 = TicketCheckoutGateway と
 * 責務境界を分ける。移植元の 30+ メソッド単一 interface へは寄せない)。
 */
interface AutoRechargeGatewayInterface
{
    /**
     * オートリチャージ用カード保存 Checkout (mode=setup)。off-session mandate 同意を伴う。
     * 無料パーソナル (サブスクなし) でも Stripe Customer を作成して通す唯一のカード登録経路。
     *
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string|null}
     */
    public function createSetupCheckout(
        Organization $organization,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): array;

    /**
     * オートリチャージ Invoice の作成 (段階 1/2)。
     * draft invoice 作成 → invoice item (price × quantity) 追加、までを行い invoice id を返す。
     * 呼び出し側 (Service) はこの戻り値を attempt.stripe_invoice_id に**保存してから**
     * payOffSessionInvoice (段階 2/2) を呼ぶこと — 保存前にプロセスが落ちても、リコンサイルが
     * 同一 $idempotencyKeyBase で再実行すれば Stripe 冪等により同一 invoice が返る。
     *
     * @param  array<string, string>  $metadata  purpose / organization_id / recharge_attempt_ulid 必須
     */
    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string;

    /**
     * オートリチャージ Invoice の確定と回収 (段階 2/2)。
     * finalize (draft→open) → pay(off_session)。カード起因の失敗は例外ではなく typed 結果で返す。
     * Stripe 障害・設定不備は例外のまま伝播 (fail-closed)。
     */
    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto;

    /**
     * 失敗 invoice の終端保証。open → void / draft (finalize 前) → delete。
     * 冪等 (void/delete 済み・存在しないは成功扱い)。paid の invoice に対しては例外
     * (誤 void の防止 — paid は付与経路の管轄)。
     */
    public function terminateInvoice(string $invoiceId): void;

    /**
     * リコンサイル用の invoice 現在状態取得。
     * 存在しない (draft delete 済み含む) は status='deleted' として返す。
     */
    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto;

    /**
     * customer の default PM 状態 (有無・brand・last4)。
     * リチャージ有効化の fail-closed 判定に使う。
     */
    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto;

    /**
     * setup_intent から payment_method id を解決する
     * (Job から Stripe API を直接触らないための境界)。
     */
    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string;

    /**
     * PM を customer に attach し invoice_settings.default_payment_method に設定する。
     * 既 attach の PM は attach を skip する冪等実装。
     */
    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void;
}
