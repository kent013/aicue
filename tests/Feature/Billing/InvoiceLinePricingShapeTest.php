<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Models\Organization;
use Laravel\Cashier\Events\WebhookReceived;
use Webmozart\Assert\Assert;

/*
|--------------------------------------------------------------------------
| invoice.paid line item の price 形状互換 (basil / clover regression)
|--------------------------------------------------------------------------
|
| Stripe API 2025-03-31 (basil) 以降、invoice line item の `price` は廃止され
| `pricing.price_details.price` (string) に移動した。実イベントでは
| `price: null` + `pricing` のみが届くため、旧形状だけ読む実装では
| 月次付与が必ずスキップされる。StripeWebhookProcessor::resolveInvoicePlan は
| 両形状に対応済みのため、その regression をここで固定する。
*/

/** PlanSeeder が bootstrap 投入した standard プラン現行 base Price の Stripe Price ID */
function pricingShapeStandardBasePriceId(): string
{
    $price = Plan::query()->where('code', 'standard')->firstOrFail()
        ->currentPrice(PlanPriceKind::Base);
    Assert::notNull($price, 'standard プランの current base price が未 seed');

    return $price->stripe_price_id;
}

/**
 * standard プランの月次付与を有効化する (arrange)。
 *
 * D28 で月次付与は廃止され seed 既定の monthly_ticket_grant は全 tier 0 になった。
 * 本テストの関心 (invoice line の price 形状解決) は月次付与の発火で観測するため、
 * arrange で明示的に枚数を設定する。
 */
function pricingShapeEnableMonthlyGrant(): void
{
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
}

/** stripe customer を持つ組織を作る (WebhookIdempotencyTest とは独立の helper) */
function pricingShapeStripeCustomer(string $stripeId): Organization
{
    [$organization] = createOrganizationWithOwner();
    // stripe_id は Cashier customer column (状態キー)。テストでは明示代入する
    $organization->stripe_id = $stripeId;
    $organization->save();

    return $organization;
}

/**
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function invoicePaidPayloadWithLine(string $eventId, string $customerId, array $line): array
{
    return [
        'id' => $eventId,
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => 'in_'.$eventId,
                'customer' => $customerId,
                'billing_reason' => 'subscription_create',
                'lines' => ['data' => [$line]],
            ],
        ],
    ];
}

test('新形状 (pricing.price_details.price) の invoice.paid で月次付与される', function (): void {
    $organization = pricingShapeStripeCustomer('cus_clover_create');
    pricingShapeEnableMonthlyGrant();

    // 実イベントと同形状: price キーは null で届き pricing のみが price を持つ
    event(new WebhookReceived(invoicePaidPayloadWithLine('evt_clover_create', 'cus_clover_create', [
        'price' => null,
        'pricing' => [
            'price_details' => [
                'price' => pricingShapeStandardBasePriceId(),
                'product' => 'prod_clover',
            ],
            'type' => 'price_details',
        ],
    ])));

    // standard プランの monthly_ticket_grant (100) が「月次付与」として計上される。
    // subscription_create ではこれに加え signup grant (10) も走るため、balance ではなく
    // 月次エントリ (monthly:%) を直接検証してこのテストの関心 (pricing 形状解決) に絞る。
    expect($organization->ticketLedgerEntries()
        ->where('idempotency_key', 'like', 'monthly:%')->sum('delta'))->toBe(100);
});

test('旧形状 (price.id) の invoice.paid でも月次付与される (後方互換)', function (): void {
    $organization = pricingShapeStripeCustomer('cus_legacy_create');
    pricingShapeEnableMonthlyGrant();

    event(new WebhookReceived(invoicePaidPayloadWithLine('evt_legacy_create', 'cus_legacy_create', [
        'price' => ['id' => pricingShapeStandardBasePriceId()],
    ])));

    expect($organization->ticketLedgerEntries()
        ->where('idempotency_key', 'like', 'monthly:%')->sum('delta'))->toBe(100);
});

test('新形状の price が plan_prices に無ければ月次付与しない', function (): void {
    $organization = pricingShapeStripeCustomer('cus_clover_unknown');
    pricingShapeEnableMonthlyGrant();

    event(new WebhookReceived(invoicePaidPayloadWithLine('evt_clover_unknown', 'cus_clover_unknown', [
        'price' => null,
        'pricing' => [
            'price_details' => ['price' => 'price_not_synced', 'product' => 'prod_x'],
            'type' => 'price_details',
        ],
    ])));

    // plan_code fallback も無い (未契約) ため「月次付与」は走らない
    // (subscription_create なので signup grant は別途走るが、本テストの関心外)。
    expect($organization->ticketLedgerEntries()
        ->where('idempotency_key', 'like', 'monthly:%')->count())->toBe(0);
});
