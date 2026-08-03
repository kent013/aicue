<?php

declare(strict_types=1);

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\TicketLedgerService;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * charge.refunded による買い切りチケットの逆仕訳 (clawback)。
 * 「返金済みなのに消費可能」という整合性の穴を塞ぐ。累積返金額の差分のみを
 * 逆仕訳するため、部分返金の繰り返し・再送・順序逆転に対して冪等。
 */

function clawbackService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

/**
 * @return array<string, mixed>
 */
function chargeRefundedPayload(string $eventId, string $paymentIntent, int $amountRefunded): array
{
    return [
        'id' => $eventId,
        'type' => 'charge.refunded',
        'data' => [
            'object' => [
                'id' => 'ch_'.$eventId,
                'payment_intent' => $paymentIntent,
                'amount_refunded' => $amountRefunded,
            ],
        ],
    ];
}

/** purchased 出所の台帳純額 (負の逆仕訳込み) */
function purchasedNet(Organization $organization): int
{
    return (int) TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('source', TicketSource::Purchased)
        ->sum('delta');
}

test('全額返金で購入チケットが全枚数逆仕訳される', function (): void {
    [$organization] = createOrganizationWithOwner();
    clawbackService()->grantPurchased($organization, 10, 'cs_full', 'pi_full', 5000);

    event(new WebhookReceived(chargeRefundedPayload('evt_full', 'pi_full', 5000)));

    expect(purchasedNet($organization))->toBe(0);
    $clawback = $organization->ticketLedgerEntries()
        ->where('kind', TicketLedgerKind::Clawback)
        ->firstOrFail();
    expect($clawback->delta)->toBe(-10);
    expect($clawback->payment_intent_id)->toBe('pi_full');
});

test('部分返金は整数按分 (floor) で逆仕訳される', function (): void {
    [$organization] = createOrganizationWithOwner();
    clawbackService()->grantPurchased($organization, 10, 'cs_part', 'pi_part', 5000);

    // 2999/5000 × 10 = 5.998 → floor 5 枚逆仕訳 → 残 5
    event(new WebhookReceived(chargeRefundedPayload('evt_part', 'pi_part', 2999)));

    expect(purchasedNet($organization))->toBe(5);
});

test('累積部分返金は差分のみ逆仕訳する', function (): void {
    [$organization] = createOrganizationWithOwner();
    clawbackService()->grantPurchased($organization, 10, 'cs_cum', 'pi_cum', 5000);

    // 1 回目: 累積 2500 → target 5 枚
    event(new WebhookReceived(chargeRefundedPayload('evt_cum_1', 'pi_cum', 2500)));
    expect(purchasedNet($organization))->toBe(5);

    // 2 回目: 累積 5000 (全額) → target 10、既逆仕訳 5 → delta 5 のみ
    event(new WebhookReceived(chargeRefundedPayload('evt_cum_2', 'pi_cum', 5000)));
    expect(purchasedNet($organization))->toBe(0);

    expect(
        $organization->ticketLedgerEntries()->where('kind', TicketLedgerKind::Clawback)->count(),
    )->toBe(2);
});

test('同一累積額の再送 (別 event_id) は冪等 no-op', function (): void {
    [$organization] = createOrganizationWithOwner();
    clawbackService()->grantPurchased($organization, 10, 'cs_dup', 'pi_dup', 5000);

    event(new WebhookReceived(chargeRefundedPayload('evt_dup_1', 'pi_dup', 5000)));
    event(new WebhookReceived(chargeRefundedPayload('evt_dup_2', 'pi_dup', 5000)));

    expect(purchasedNet($organization))->toBe(0);
    expect(
        $organization->ticketLedgerEntries()->where('kind', TicketLedgerKind::Clawback)->count(),
    )->toBe(1);
});

test('1 つの payment_intent が複数 org の購入に一致する異常時は中止する (越境 fail-closed)', function (): void {
    [$organizationA] = createOrganizationWithOwner();
    [$organizationB] = createOrganizationWithOwner();
    clawbackService()->grantPurchased($organizationA, 10, 'cs_a', 'pi_x', 5000);
    clawbackService()->grantPurchased($organizationB, 10, 'cs_b', 'pi_x', 5000); // 同一 PI が別 org (異常)

    event(new WebhookReceived(chargeRefundedPayload('evt_x', 'pi_x', 5000)));

    // 越境逆仕訳を防ぐため両 org とも無変更
    expect(purchasedNet($organizationA))->toBe(10);
    expect(purchasedNet($organizationB))->toBe(10);
});

test('照合できる購入明細が無い返金は no-op (サブスク返金等)', function (): void {
    [$organization] = createOrganizationWithOwner();

    event(new WebhookReceived(chargeRefundedPayload('evt_none', 'pi_none', 5000)));

    expect($organization->ticketLedgerEntries()->count())->toBe(0);
});

test('purchase_amount が欠落した購入明細は按分不可として no-op', function (): void {
    [$organization] = createOrganizationWithOwner();
    clawbackService()->grantPurchased($organization, 10, 'cs_na', 'pi_na', null);

    event(new WebhookReceived(chargeRefundedPayload('evt_na', 'pi_na', 5000)));

    expect(purchasedNet($organization))->toBe(10); // 逆仕訳されない (report + no-op)
});

test('既消費分は取り戻せず残高が負まで振れるが、reserve は不足として弾かれる', function (): void {
    [$organization] = createOrganizationWithOwner();
    clawbackService()->grantPurchased($organization, 5, 'cs_neg', 'pi_neg', 2500);

    // 2 枚消費 (reserve → commit)
    $reservation = clawbackService()->reserve($organization, 2);
    clawbackService()->commit($reservation);
    expect(clawbackService()->balance($organization)->totalAvailable())->toBe(3);

    // 全額返金 → target 5 逆仕訳 → 5 - 2 - 5 = -2 (既消費分は取り戻せない)
    event(new WebhookReceived(chargeRefundedPayload('evt_neg', 'pi_neg', 2500)));

    // P5 per-source clamp: 表示・与信からは債務を遮蔽する (purchasedRemaining = max(-2, 0))
    expect(clawbackService()->balance($organization)->purchasedRemaining)->toBe(0);
    expect(clawbackService()->balance($organization)->totalAvailable())->toBe(0);
    expect(clawbackService()->availableTrueBalance($organization))->toBe(0);
    // 台帳では債務が保全され、次回購入で一度だけ自然回収される (clamp は表示・与信のみに効く)
    expect(purchasedNet($organization))->toBe(-2);
    expect(fn () => clawbackService()->reserve($organization, 1))
        ->toThrow(InsufficientTicketsException::class);
});
