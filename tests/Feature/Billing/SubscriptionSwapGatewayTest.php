<?php

declare(strict_types=1);

use App\Enums\Billing\SubscriptionSwapOutcome;
use App\Exceptions\Billing\PlanChangeFailedException;
use App\Models\Organization;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\SubscriptionSnapshotMapper;
use Mockery\MockInterface;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\SubscriptionService as StripeSubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

/*
 * F-3-01 層 0: CashierStripeGateway::swapSubscriptionPrices() の **制御フロー**を固定する。
 *
 * Stripe client の取得は protected seam (`stripe(): StripeClient`) 越しなので、テストでは
 * seam を差し替えた subclass に mock client を返させる (**実ネットワークに出ない**)。
 * seam をリネームしたら本テストも同時に更新すること。
 *
 * 固定する契約:
 *  - remote の base item price が対象と同一 → `update()` は 0 回 / `AlreadyOnTargetPrice`
 *  - 異なる → `update()` が 1 回・buildSwapPayload() と同一 payload + idempotency key / `Applied`
 *  - `retrieve` と `update` は同一 subscription id を使う
 *  - 想定外の item 構成 (0 個 / 2 個 / 解決不能 / quantity != 1) は fail-closed で update 0 回
 *  - SDK 例外 (`ApiErrorException`) は境界を越えず `PlanChangeFailedException` に変換される
 */

/**
 * seam を差し替えた gateway (Stripe client を注入できる本番実装)。
 */
function swapGateway(StripeClient $client): CashierStripeGateway
{
    return new class($client) extends CashierStripeGateway
    {
        public function __construct(private readonly StripeClient $client)
        {
            parent::__construct(new SubscriptionSnapshotMapper);
        }

        protected function stripe(): StripeClient
        {
            return $this->client;
        }
    };
}

/**
 * remote subscription object を組み立てる (ネットワークに出ない)。
 *
 * @param  list<array<string, mixed>>  $items
 */
function swapRemoteSubscription(string $stripeId, array $items): StripeSubscription
{
    return StripeSubscription::constructFrom([
        'id' => $stripeId,
        'object' => 'subscription',
        'items' => ['object' => 'list', 'data' => $items],
    ]);
}

/**
 * @return array<string, mixed>
 */
function swapRemoteItem(string $id, ?string $priceId, ?int $quantity = 1): array
{
    return [
        'id' => $id,
        'object' => 'subscription_item',
        'price' => $priceId === null ? null : ['id' => $priceId, 'object' => 'price'],
        'quantity' => $quantity,
    ];
}

/**
 * 契約中組織 + mock Stripe client を用意する。
 *
 * @return array{Organization, StripeClient, MockInterface}
 */
function swapGatewayFixture(): array
{
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $subscription = contractPaidPlan($organization);
    $subscription->forceFill(['stripe_id' => 'sub_swap_1'])->save();
    $organization->refresh();

    $subscriptionsService = Mockery::mock(StripeSubscriptionService::class);
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    // `$client->subscriptions` は StripeClient::__get → getService() に落ちる
    // (Mockery は magic method を素通しするため getService に期待を張る)。
    $client->shouldReceive('getService')->with('subscriptions')->andReturn($subscriptionsService);

    return [$organization, $client, $subscriptionsService];
}

test('remote が既に対象 Price なら update を送らず AlreadyOnTargetPrice を返す', function (): void {
    [$organization, $client, $subscriptions] = swapGatewayFixture();

    $subscriptions->shouldReceive('retrieve')
        ->once()
        ->with('sub_swap_1', ['expand' => ['items.data']])
        ->andReturn(swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_target')]));
    $subscriptions->shouldNotReceive('update');

    $outcome = swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');

    expect($outcome)->toBe(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
});

test('remote が別 Price なら既存 item id を指定した update を 1 回だけ送る', function (): void {
    [$organization, $client, $subscriptions] = swapGatewayFixture();

    $subscriptions->shouldReceive('retrieve')
        ->once()
        ->with('sub_swap_1', ['expand' => ['items.data']])
        ->andReturn(swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_current')]));
    $subscriptions->shouldReceive('update')
        ->once()
        ->with(
            'sub_swap_1',
            (new CashierStripeGateway(app(SubscriptionSnapshotMapper::class)))->buildSwapPayload('si_1', 'price_target'),
            ['idempotency_key' => 'change-plan:tok:standard'],
        )
        ->andReturn(swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_target')]));

    $outcome = swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');

    expect($outcome)->toBe(SubscriptionSwapOutcome::Applied);
});

test('item が 0 個の remote は fail-closed で update を送らない', function (): void {
    [$organization, $client, $subscriptions] = swapGatewayFixture();

    $subscriptions->shouldReceive('retrieve')->once()->andReturn(swapRemoteSubscription('sub_swap_1', []));
    $subscriptions->shouldNotReceive('update');

    try {
        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
        $this->fail('PlanChangeFailedException が投げられていない');
    } catch (PlanChangeFailedException $e) {
        expect($e->reason)->toStartWith('unexpected_shape:');
        expect($e->getMessage())->toBe(PlanChangeFailedException::USER_MESSAGE);
    }
});

test('item が 2 個の remote は fail-closed で update を送らない', function (): void {
    [$organization, $client, $subscriptions] = swapGatewayFixture();

    $subscriptions->shouldReceive('retrieve')->once()->andReturn(swapRemoteSubscription('sub_swap_1', [
        swapRemoteItem('si_1', 'price_current'),
        swapRemoteItem('si_2', 'price_seat'),
    ]));
    $subscriptions->shouldNotReceive('update');

    try {
        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
        $this->fail('PlanChangeFailedException が投げられていない');
    } catch (PlanChangeFailedException $e) {
        expect($e->reason)->toStartWith('unexpected_shape:');
    }
});

test('正常 1 件 + price 解決不能 1 件は skip せず fail-closed にする', function (): void {
    // skip すると「正常 1 件 + 解決不能 1 件」が正規化後 1 件になり、多 item 契約を更新してしまう。
    [$organization, $client, $subscriptions] = swapGatewayFixture();

    $subscriptions->shouldReceive('retrieve')->once()->andReturn(swapRemoteSubscription('sub_swap_1', [
        swapRemoteItem('si_1', 'price_current'),
        swapRemoteItem('si_2', null),
    ]));
    $subscriptions->shouldNotReceive('update');

    try {
        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
        $this->fail('PlanChangeFailedException が投げられていない');
    } catch (PlanChangeFailedException $e) {
        expect($e->reason)->toStartWith('unexpected_shape:');
    }
});

test('quantity が 1 でない item は暗黙補正せず fail-closed にする', function (): void {
    [$organization, $client, $subscriptions] = swapGatewayFixture();

    $subscriptions->shouldReceive('retrieve')->once()->andReturn(swapRemoteSubscription('sub_swap_1', [
        swapRemoteItem('si_1', 'price_current', 2),
    ]));
    $subscriptions->shouldNotReceive('update');

    try {
        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
        $this->fail('PlanChangeFailedException が投げられていない');
    } catch (PlanChangeFailedException $e) {
        expect($e->reason)->toStartWith('unexpected_shape:');
    }
});

test('retrieve の ApiErrorException は PlanChangeFailedException に変換される', function (): void {
    [$organization, $client, $subscriptions] = swapGatewayFixture();

    $sdkError = new InvalidRequestException('no such subscription');
    $subscriptions->shouldReceive('retrieve')->once()->andThrow($sdkError);
    $subscriptions->shouldNotReceive('update');

    try {
        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
        $this->fail('PlanChangeFailedException が投げられていない');
    } catch (PlanChangeFailedException $e) {
        expect($e->getMessage())->toBe(PlanChangeFailedException::USER_MESSAGE);
        expect($e->reason)->toStartWith('stripe_api_error:');
        expect($e->getPrevious())->toBe($sdkError);
    }
});

test('update の ApiErrorException も PlanChangeFailedException に変換される', function (): void {
    [$organization, $client, $subscriptions] = swapGatewayFixture();

    $sdkError = new InvalidRequestException('card declined');
    $subscriptions->shouldReceive('retrieve')->once()->andReturn(
        swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_current')]),
    );
    $subscriptions->shouldReceive('update')->once()->andThrow($sdkError);

    try {
        swapGateway($client)->swapSubscriptionPrices($organization, 'price_target', 'change-plan:tok:standard');
        $this->fail('PlanChangeFailedException が投げられていない');
    } catch (PlanChangeFailedException $e) {
        expect($e->reason)->toStartWith('stripe_api_error:');
        expect($e->getPrevious())->toBe($sdkError);
    }
});
