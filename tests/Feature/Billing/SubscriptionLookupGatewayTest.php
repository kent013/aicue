<?php

declare(strict_types=1);

use App\Exceptions\Billing\SubscriptionLookupFailedException;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\SubscriptionSnapshotMapper;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\SubscriptionService as StripeSubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

/*
 * CashierStripeGateway::retrieveSubscriptionState() の**制御フロー**を固定する
 * (SubscriptionSwapGatewayTest と同じ protected seam 差し替えで実ネットワークに出ない)。
 *
 * 固定する契約:
 *  - 正常応答 → mapper 経由で SubscriptionSnapshot が組み上がる
 *  - resource_missing → **null** (「無い」は例外にしない = 状態を変えない材料)
 *  - それ以外の Stripe SDK 例外 → SubscriptionLookupFailedException に変換 (SDK 例外を外へ出さない)
 *  - id の取れない応答 → SubscriptionLookupFailedException (壊れた応答で状態を変えない)
 *  - 決済手段は三値 (観測できなければ null。false と断定しない)
 */

/** seam を差し替えた gateway (Stripe client を注入できる本番実装)。 */
function lookupGateway(StripeClient $client): CashierStripeGateway
{
    return new class($client, app(SubscriptionSnapshotMapper::class)) extends CashierStripeGateway
    {
        public function __construct(
            private readonly StripeClient $client,
            SubscriptionSnapshotMapper $mapper,
        ) {
            parent::__construct($mapper);
        }

        protected function stripe(): StripeClient
        {
            return $this->client;
        }
    };
}

/**
 * retrieve が $result を返す (または throw する) mock client。
 */
function lookupClient(mixed $result): StripeClient
{
    $subscriptions = Mockery::mock(StripeSubscriptionService::class);
    if ($result instanceof Throwable) {
        $subscriptions->shouldReceive('retrieve')->andThrow($result);
    } else {
        $subscriptions->shouldReceive('retrieve')->andReturn($result);
    }

    $client = Mockery::mock(StripeClient::class);
    $client->subscriptions = $subscriptions;

    return $client;
}

test('正常応答は mapper 経由で snapshot になる', function (): void {
    $remote = StripeSubscription::constructFrom([
        'id' => 'sub_lookup_1',
        'object' => 'subscription',
        'status' => 'past_due',
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_1',
            'object' => 'subscription_item',
            'price' => ['id' => 'price_lookup_1', 'object' => 'price'],
            'quantity' => 1,
            'current_period_end' => 1_800_000_000,
        ]]],
    ]);

    $state = lookupGateway(lookupClient($remote))->retrieveSubscriptionState('sub_lookup_1');

    expect($state)->not->toBeNull()
        ->and($state?->snapshot->stripeId)->toBe('sub_lookup_1')
        ->and($state?->snapshot->status)->toBe('past_due')
        ->and($state?->snapshot->basePriceId)->toBe('price_lookup_1')
        ->and($state?->snapshot->baseQuantity)->toBe(1)
        ->and($state?->snapshot->currentPeriodEnd?->getTimestamp())->toBe(1_800_000_000);
});

test('resource_missing は null (契約が無いことは例外にしない)', function (): void {
    $missing = new InvalidRequestException('No such subscription');
    $missing->setStripeCode('resource_missing');

    expect(lookupGateway(lookupClient($missing))->retrieveSubscriptionState('sub_gone'))->toBeNull();
});

test('resource_missing 以外の InvalidRequestException は変換される', function (): void {
    $invalid = new InvalidRequestException('bad parameter');
    $invalid->setStripeCode('parameter_invalid_empty');

    lookupGateway(lookupClient($invalid))->retrieveSubscriptionState('sub_lookup_1');
})->throws(SubscriptionLookupFailedException::class);

test('その他の Stripe SDK 例外も変換される (SDK 例外を境界の外へ出さない)', function (): void {
    lookupGateway(lookupClient(new ApiConnectionException('network down')))
        ->retrieveSubscriptionState('sub_lookup_1');
})->throws(SubscriptionLookupFailedException::class);

test('id の取れない応答は「確認できなかった」として例外 (状態を変える材料にしない)', function (): void {
    $broken = StripeSubscription::constructFrom(['object' => 'subscription', 'status' => 'active']);

    lookupGateway(lookupClient($broken))->retrieveSubscriptionState('sub_lookup_1');
})->throws(SubscriptionLookupFailedException::class);

test('default_payment_method があれば hasPaymentMethod = true', function (): void {
    $remote = StripeSubscription::constructFrom([
        'id' => 'sub_lookup_2',
        'object' => 'subscription',
        'status' => 'active',
        'default_payment_method' => 'pm_lookup_1',
        'items' => ['object' => 'list', 'data' => []],
    ]);

    expect(lookupGateway(lookupClient($remote))->retrieveSubscriptionState('sub_lookup_2')?->hasPaymentMethod)
        ->toBeTrue();
});

test('決済手段が観測できないときは null (false にしない)', function (): void {
    $remote = StripeSubscription::constructFrom([
        'id' => 'sub_lookup_3',
        'object' => 'subscription',
        'status' => 'active',
        'items' => ['object' => 'list', 'data' => []],
    ]);

    expect(lookupGateway(lookupClient($remote))->retrieveSubscriptionState('sub_lookup_3')?->hasPaymentMethod)
        ->toBeNull();
});

test('空の subscription id は fail-fast (照会に出さない)', function (): void {
    lookupGateway(lookupClient(null))->retrieveSubscriptionState('');
})->throws(InvalidArgumentException::class);
