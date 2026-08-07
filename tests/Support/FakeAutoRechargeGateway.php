<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
use App\DataTransferObjects\Billing\InvoiceStateDto;
use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
use App\Enums\Billing\GatewayFailureClass;
use App\Models\Organization;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Closure;
use Tests\Support\Billing\GatewayFailureFixtures;

/**
 * AutoRechargeGatewayInterface のテスト用 spy (Stripe に到達しない)。
 *
 * 呼び出しを記録し、テスト側が結果を注入できる。invoice の状態は内部 map で保持し、
 * terminate / retrieve が現実の Stripe と同じ状態機械 (draft/open → void / paid は終端不可)
 * を近似する。
 */
final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
{
    /** @var list<array{organizationId: int, successUrl: string, cancelUrl: string, metadata: array<string, string>, idempotencyKey: string}> */
    public array $setupCheckouts = [];

    /** @var list<array{organizationId: int, priceId: string, quantity: int, metadata: array<string, string>, keyBase: string}> */
    public array $createdInvoices = [];

    /** @var list<array{invoiceId: string, keyBase: string}> */
    public array $payCalls = [];

    /** @var list<string> */
    public array $terminated = [];

    /** @var list<array{organizationId: int, paymentMethodId: string}> */
    public array $defaultPaymentMethodsSet = [];

    /** default PM 状態 (有効化 fail-closed 判定の注入点)。 */
    public ?DefaultPaymentMethodDto $defaultPaymentMethod = null;

    /** payOffSessionInvoice の返り値 (null なら paid を合成する)。 */
    public ?OffSessionChargeResultDto $payResult = null;

    /** retrieveInvoiceState の返り値 (null なら invoiceStates → 内部 status map の順で解決する)。 */
    public ?InvoiceStateDto $invoiceState = null;

    /** invoiceId => InvoiceStateDto の個別注入 (org ごとに異なる状態を作るテスト用)。 */
    /** @var array<string, InvoiceStateDto> */
    public array $invoiceStates = [];

    /** invoiceId => status (retrieveInvoiceState / terminateInvoice の内部状態)。 */
    /** @var array<string, string> */
    public array $invoiceStatuses = [];

    /**
     * terminateInvoice が投げる失敗の**分類** (null なら投げない)。
     *
     * ★bool ではなく分類で指定する。投げる実体は GatewayFailureFixtures が返す
     *   **実ライブラリ例外**であり、本物の gateway が伝播させるクラスと一致する。
     */
    public ?GatewayFailureClass $terminateFailure = null;

    /**
     * createAutoRechargeInvoice が invoice ID を返す**直前**に呼ばれる hook
     * (`FakeRenderComposer::$duringCompose` と同じ作法)。
     *
     * 「Stripe 側の作成は成功したが、返る前に停止側が terminal 化した」
     * = attach 0 行になる競合点を決定論的に再現するために使う。
     */
    public ?Closure $duringCreateInvoice = null;

    /** @var list<string> resolveSubscriptionPaymentMethod を要求された subscription id (T1004) */
    public array $resolvedSubscriptions = [];

    /** resolveSubscriptionPaymentMethod の返り値 (null = 解決不能)。 */
    public ?string $subscriptionPaymentMethodId = 'pm_test_subscription';

    /** resolveSubscriptionPaymentMethod が投げる失敗の分類 (null なら投げない)。 */
    public ?GatewayFailureClass $resolveSubscriptionFailure = null;

    /** createSetupCheckout が返す url (null = 進行中 replay の再現)。 */
    public ?string $setupUrl = 'https://checkout.stripe.test/c/setup/cs_setup_test';

    /** paid 合成時に使う実回収額 (null なら quantity × unit を使う呼び出し側の期待に委ねる)。 */
    public ?int $payAmountPaid = null;

    public ?string $payPaymentIntentId = 'pi_test_autorecharge';

    public function createSetupCheckout(
        Organization $organization,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): array {
        $this->setupCheckouts[] = [
            'organizationId' => (int) $organization->getKey(),
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'metadata' => $metadata,
            'idempotencyKey' => $idempotencyKey,
        ];

        // idempotency key から決定的に導出 (同一 token の再送は同一 session に収束)
        return [
            'id' => 'cs_setup_'.substr(hash('sha256', $idempotencyKey), 0, 24),
            'url' => $this->setupUrl,
        ];
    }

    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string {
        $this->createdInvoices[] = [
            'organizationId' => (int) $organization->getKey(),
            'priceId' => $priceId,
            'quantity' => $quantity,
            'metadata' => $metadata,
            'keyBase' => $idempotencyKeyBase,
        ];

        // Stripe 冪等: 同一 key base は同一 invoice id に収束する
        $invoiceId = 'in_'.substr(hash('sha256', $idempotencyKeyBase), 0, 24);
        $this->invoiceStatuses[$invoiceId] ??= 'draft';

        if ($this->duringCreateInvoice !== null) {
            ($this->duringCreateInvoice)($invoiceId);
        }

        return $invoiceId;
    }

    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
    {
        $this->payCalls[] = ['invoiceId' => $invoiceId, 'keyBase' => $idempotencyKeyBase];

        if ($this->payResult !== null) {
            if ($this->payResult->paid) {
                $this->invoiceStatuses[$invoiceId] = 'paid';
            } else {
                $this->invoiceStatuses[$invoiceId] = 'open';
            }

            return $this->payResult;
        }

        $this->invoiceStatuses[$invoiceId] = 'paid';
        $amount = $this->payAmountPaid ?? 0;

        return OffSessionChargeResultDto::paid($invoiceId, $amount, $amount, $this->payPaymentIntentId);
    }

    public function terminateInvoice(string $invoiceId): void
    {
        if ($this->terminateFailure !== null) {
            throw GatewayFailureFixtures::throwableFor($this->terminateFailure);
        }

        $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
        if ($status === 'paid') {
            // ★本物 (CashierAutoRechargeGateway の Assert::true) と**同じクラス**を投げる
            throw GatewayFailureFixtures::throwableFor(GatewayFailureClass::InvariantViolation);
        }

        $this->terminated[] = $invoiceId;
        $this->invoiceStatuses[$invoiceId] = $status === 'draft' ? 'deleted' : 'void';
    }

    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
    {
        if (isset($this->invoiceStates[$invoiceId])) {
            return $this->invoiceStates[$invoiceId];
        }

        if ($this->invoiceState !== null) {
            return $this->invoiceState;
        }

        return new InvoiceStateDto(
            $this->invoiceStatuses[$invoiceId] ?? 'open',
            null,
            null,
            null,
            false,
            'https://invoice.stripe.test/i/'.$invoiceId,
        );
    }

    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto
    {
        return $this->defaultPaymentMethod ?? DefaultPaymentMethodDto::none();
    }

    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string
    {
        return 'pm_test_'.substr(hash('sha256', $setupIntentId), 0, 16);
    }

    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        $this->defaultPaymentMethodsSet[] = [
            'organizationId' => (int) $organization->getKey(),
            'paymentMethodId' => $paymentMethodId,
        ];
        $this->defaultPaymentMethod = new DefaultPaymentMethodDto($paymentMethodId, 'visa', '4242');
    }

    public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string
    {
        $this->resolvedSubscriptions[] = $stripeSubscriptionId;

        if ($this->resolveSubscriptionFailure !== null) {
            throw GatewayFailureFixtures::throwableFor($this->resolveSubscriptionFailure);
        }

        return $this->subscriptionPaymentMethodId;
    }

    /** 有効化 fail-closed を通過させる (default PM ありの状態を注入する)。 */
    public function withDefaultPaymentMethod(string $paymentMethodId = 'pm_test_default'): self
    {
        $this->defaultPaymentMethod = new DefaultPaymentMethodDto($paymentMethodId, 'visa', '4242');

        return $this;
    }
}
