<?php

declare(strict_types=1);

use App\Services\Billing\SubscriptionSnapshotMapper;

/*
 * SubscriptionSnapshotMapper — Stripe の subscription オブジェクト (配列) から
 * SubscriptionSnapshot を組む **唯一の写像**。
 *
 * webhook (payload の data.object) と日次突き合わせ (SDK オブジェクトの toArray()) は
 * どちらもここを通る。同じ配列を渡せば同じ snapshot が出ることを固定し、
 * 「突き合わせ経路だけ別挙動」の余地を消す。
 */

function snapshotMapper(): SubscriptionSnapshotMapper
{
    return app(SubscriptionSnapshotMapper::class);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeSubscriptionObject(array $overrides = []): array
{
    return array_replace([
        'id' => 'sub_map_1',
        'object' => 'subscription',
        'status' => 'active',
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_map_1',
            'price' => ['id' => 'price_map_1'],
            'quantity' => 2,
            'current_period_end' => 1_800_000_000,
        ]]],
        'trial_end' => 1_700_000_000,
    ], $overrides);
}

test('7 フィールドを取り出す', function (): void {
    $snapshot = snapshotMapper()->fromStripeSubscription(stripeSubscriptionObject([
        'ended_at' => 1_750_000_000,
    ]));

    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->stripeId)->toBe('sub_map_1')
        ->and($snapshot?->status)->toBe('active')
        ->and($snapshot?->basePriceId)->toBe('price_map_1')
        ->and($snapshot?->baseQuantity)->toBe(2)
        ->and($snapshot?->currentPeriodEnd?->getTimestamp())->toBe(1_800_000_000)
        ->and($snapshot?->trialEndsAt?->getTimestamp())->toBe(1_700_000_000)
        ->and($snapshot?->endsAt?->getTimestamp())->toBe(1_750_000_000);
});

test('id が無い応答は写像失敗 (null。呼び出し側が fail-closed に倒す)', function (mixed $id): void {
    $object = stripeSubscriptionObject();
    $object['id'] = $id;

    expect(snapshotMapper()->fromStripeSubscription($object))->toBeNull();
})->with([
    'null' => [null],
    '空文字' => [''],
    '非文字列' => [123],
]);

test('status 欠落は incomplete に倒す (未知状態を active と誤読しない)', function (): void {
    $object = stripeSubscriptionObject();
    unset($object['status']);

    expect(snapshotMapper()->fromStripeSubscription($object)?->status)->toBe('incomplete');
});

test('current_period_end は新 API (item 配下) を優先し、無ければ旧 API (top-level) を拾う', function (): void {
    $newApi = snapshotMapper()->fromStripeSubscription(stripeSubscriptionObject([
        'current_period_end' => 1_600_000_000,
    ]));
    expect($newApi?->currentPeriodEnd?->getTimestamp())->toBe(1_800_000_000);

    $object = stripeSubscriptionObject(['current_period_end' => 1_600_000_000]);
    unset($object['items']['data'][0]['current_period_end']);

    expect(snapshotMapper()->fromStripeSubscription($object)?->currentPeriodEnd?->getTimestamp())
        ->toBe(1_600_000_000);
});

test('epoch 0 / 非 int の時刻は null (0 を 1970 年として書き込まない)', function (mixed $value): void {
    $object = stripeSubscriptionObject(['trial_end' => $value]);

    expect(snapshotMapper()->fromStripeSubscription($object)?->trialEndsAt)->toBeNull();
})->with([
    'epoch 0' => [0],
    '負値' => [-1],
    '文字列' => ['1700000000'],
    'null' => [null],
]);

test('endsAt は ended_at を優先し、無ければ cancel_at を拾う', function (): void {
    $both = snapshotMapper()->fromStripeSubscription(stripeSubscriptionObject([
        'ended_at' => 1_750_000_000,
        'cancel_at' => 1_760_000_000,
    ]));
    expect($both?->endsAt?->getTimestamp())->toBe(1_750_000_000);

    $cancelOnly = snapshotMapper()->fromStripeSubscription(stripeSubscriptionObject([
        'cancel_at' => 1_760_000_000,
    ]));
    expect($cancelOnly?->endsAt?->getTimestamp())->toBe(1_760_000_000);
});

test('quantity が int でなければ null (欠落を 0 と読まない)', function (): void {
    $object = stripeSubscriptionObject();
    $object['items']['data'][0]['quantity'] = '2';

    expect(snapshotMapper()->fromStripeSubscription($object)?->baseQuantity)->toBeNull();
});

test('決済手段の観測は三値 (true / 観測できず null。false と断定しない)', function (array $overrides, ?bool $expected): void {
    expect(snapshotMapper()->observePaymentMethod(stripeSubscriptionObject($overrides)))->toBe($expected);
})->with([
    'default_payment_method が id 文字列' => [['default_payment_method' => 'pm_1'], true],
    'default_payment_method が expanded' => [['default_payment_method' => ['id' => 'pm_1']], true],
    'default_source のみ' => [['default_source' => 'card_1'], true],
    'どちらも無い' => [[], null],
    'どちらも空文字' => [['default_payment_method' => '', 'default_source' => ''], null],
]);

test('webhook 経路 (data.object) と gateway 経路 (toArray 相当) は同じ snapshot を生む', function (): void {
    $object = stripeSubscriptionObject(['ended_at' => 1_750_000_000]);

    // webhook は payload の data.object を取り出して渡す。gateway は SDK の toArray() を渡す。
    $payload = ['data' => ['object' => $object]];
    /** @var array<string, mixed> $fromPayload */
    $fromPayload = data_get($payload, 'data.object');

    expect(snapshotMapper()->fromStripeSubscription($fromPayload))
        ->toEqual(snapshotMapper()->fromStripeSubscription($object));
});
