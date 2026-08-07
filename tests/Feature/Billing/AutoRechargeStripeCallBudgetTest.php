<?php

declare(strict_types=1);

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Support\ExternalClientTimeouts;
use Stripe\ApiRequestor;
use Tests\Support\Billing\CountingStripeHttpClient;

/*
 * 既定キュー接続 (database) の **Stripe 呼び出し回数**を behavioral に固定する (T126 施策 6)。
 *
 * ★静的な呼び出し site 計数では Cashier 内部 (`createOrGetStripeCustomer` 等) を数えられない。
 *   `ApiRequestor::setHttpClient()` は Stripe SDK 公式の差し込み口であり、
 *   **実 `CashierAutoRechargeGateway`** を通した実行時の HTTP 呼び出し回数をそのまま数えられる。
 * ★外部 HTTP は 1 バイトも発生しない (fake client が応答列を返す)。
 * ★`config/queue.php` の retry_after は「呼び出し予算 × Stripe timeout + 局所予算」で決めている。
 *   この回数が増えたら**帯の前提が崩れる**ため、ドリフトを CI で検知する。
 */

/**
 * Stripe API の応答 1 件。
 *
 * @param  array<string, mixed>  $body
 * @return array{status: int, body: string}
 */
function stripeBudgetResponse(int $status, array $body): array
{
    return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
}

/** @return array{status: int, body: string} */
function stripeBudgetCustomer(): array
{
    return stripeBudgetResponse(200, ['id' => 'cus_gate', 'object' => 'customer']);
}

/** @return array{status: int, body: string} */
function stripeBudgetInvoice(string $status, int $amountPaid = 0, int $amountDue = 0): array
{
    return stripeBudgetResponse(200, [
        'id' => 'in_gate',
        'object' => 'invoice',
        'status' => $status,
        'amount_paid' => $amountPaid,
        'amount_due' => $amountDue,
        'payments' => ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices/in_gate/payments'],
    ]);
}

/** @return array{status: int, body: string} */
function stripeBudgetInvoiceItem(): array
{
    return stripeBudgetResponse(200, ['id' => 'ii_gate', 'object' => 'invoiceitem']);
}

/** @return array{status: int, body: string} */
function stripeBudgetCardDeclined(): array
{
    return stripeBudgetResponse(402, ['error' => [
        'type' => 'card_error',
        'code' => 'card_declined',
        'decline_code' => 'generic_decline',
        'message' => 'Your card was declined.',
    ]]);
}

/** @return array{status: int, body: string} */
function stripeBudgetAlreadyFinalized(): array
{
    return stripeBudgetResponse(400, ['error' => [
        'type' => 'invalid_request_error',
        'message' => 'This invoice is already finalized.',
    ]]);
}

/** 有効化済みの auto-recharge 設定を持つ組織で pending attempt を作る。 */
function stripeBudgetPendingAttempt(bool $withInvoice = false, bool $withStripeCustomer = true): TicketAutoRechargeAttempt
{
    [$organization] = createOrganizationWithOwner();
    // stripe_id 有無で createOrGetStripeCustomer の retrieve / create 分岐が変わる
    $organization->forceFill(['stripe_id' => $withStripeCustomer ? 'cus_gate' : null])->save();
    TicketAutoRecharge::factory()->enabled()->create(['organization_id' => $organization->getKey()]);

    $factory = TicketAutoRechargeAttempt::factory();
    if ($withInvoice) {
        $factory = $factory->withInvoice('in_gate');
    }

    return $factory->create([
        'organization_id' => $organization->getKey(),
        'quantity' => 45,
        'unit_amount' => 80,
    ]);
}

/**
 * 代表経路のデータセット。**なぜこれが分岐集合を代表するか**をキー名に残す。
 *
 * 各行: [応答列を返す closure, invoice 既存か, Stripe customer 既存か,
 *        期待呼び出し回数, 期待される最初のリクエスト, 期待 terminal status]
 * ★呼び出し回数だけでなく **経路が意図どおり終端したこと**も検証する
 *   (途中で早期 return して「呼び出しが少ないから green」になるのを防ぐ)。
 */
dataset('auto-recharge の外部呼び出し経路', [
    '成功 (customer 既存 = retrieve → invoice → item → finalize → pay の基準経路)' => [
        fn (): array => [
            stripeBudgetCustomer(),
            stripeBudgetInvoice('draft'),
            stripeBudgetInvoiceItem(),
            stripeBudgetInvoice('open'),
            stripeBudgetInvoice('paid', 3_600, 3_600),
        ],
        false,
        true,
        5,
        'get https://api.stripe.com/v1/customers/cus_gate',
        AutoRechargeAttemptStatus::Paid,
    ],
    '成功 (customer 新規 = createOrGetStripeCustomer の create 側へ入る経路)' => [
        fn (): array => [
            stripeBudgetCustomer(),
            stripeBudgetInvoice('draft'),
            stripeBudgetInvoiceItem(),
            stripeBudgetInvoice('open'),
            stripeBudgetInvoice('paid', 3_600, 3_600),
        ],
        false,
        false,
        5,
        'post https://api.stripe.com/v1/customers',
        AutoRechargeAttemptStatus::Paid,
    ],
    'カード拒否 → invoice void (後始末の追加呼び出しが載る最長経路)' => [
        fn (): array => [
            stripeBudgetCustomer(),
            stripeBudgetInvoice('draft'),
            stripeBudgetInvoiceItem(),
            stripeBudgetInvoice('open'),
            stripeBudgetCardDeclined(),
            stripeBudgetInvoice('open'),   // terminateInvoice: retrieve
            stripeBudgetInvoice('void'),   // terminateInvoice: voidInvoice
        ],
        false,
        true,
        7,
        'get https://api.stripe.com/v1/customers/cus_gate',
        AutoRechargeAttemptStatus::Failed,
    ],
    '既存 invoice の再利用 (finalize 済みで InvalidRequest → pay へ進む経路)' => [
        fn (): array => [
            stripeBudgetAlreadyFinalized(),
            stripeBudgetInvoice('paid', 3_600, 3_600),
        ],
        true,
        true,
        2,
        'post https://api.stripe.com/v1/invoices/in_gate/finalize',
        AutoRechargeAttemptStatus::Paid,
    ],
]);

test(
    '既定接続の Stripe 呼び出しは予算を超えない',
    /**
     * @param  callable(): list<array{status: int, body: string}>  $responses
     */
    function (
        callable $responses,
        bool $withInvoice,
        bool $withStripeCustomer,
        int $expectedCalls,
        string $expectedFirstRequest,
        AutoRechargeAttemptStatus $expectedStatus,
    ): void {
        // ApiRequestor::httpClient() は遅延生成のため null にならない (vendor 実査)。
        $original = ApiRequestor::httpClient();
        $counting = new CountingStripeHttpClient($responses());

        try {
            ApiRequestor::setHttpClient($counting);
            // 実 Cashier クライアントを構築するため API キーが要る (送信は fake client が受ける)。
            config(['cashier.secret' => 'sk_test_external_client_timeout_gate']);
            // テストレーンの fake 配線 (FakeExternalsServiceProvider) が rebind しうるため、
            // **実装へ明示的に戻す** (前提が変わっても本テストが無意味にならないようにする)。
            $this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class);

            $attempt = stripeBudgetPendingAttempt($withInvoice, $withStripeCustomer);

            app(AutoRechargeService::class)->executeAttempt($attempt);

            // ★予算の上限 (定数) を超えない
            expect($counting->calls)->toBeLessThanOrEqual(ExternalClientTimeouts::DEFAULT_CONNECTION_STRIPE_CALL_BUDGET);
            // ★経路ごとの厳密な回数 (増えたら気づく = ドリフト検知)
            expect($counting->calls)->toBe($expectedCalls, implode(PHP_EOL, $counting->requestedUrls));
            // ★空振り防止: 応答列を使い切っていること (経路が途中で終わっていない)
            expect($counting->isExhausted())->toBeTrue('応答列を使い切っていません (経路が想定より短い)');
            // ★**どの分岐へ入ったか**を最初のリクエストで固定する
            //   (customer 既存 = GET retrieve / customer 新規 = POST create。
            //    回数だけ一致して別分岐を通っている、を防ぐ)
            expect($counting->requestedUrls[0] ?? null)->toBe($expectedFirstRequest);
            // ★経路が意図どおり終端したこと
            expect($attempt->refresh()->status)->toBe($expectedStatus);
        } finally {
            ApiRequestor::setHttpClient($original);
        }
    },
)->with('auto-recharge の外部呼び出し経路');
