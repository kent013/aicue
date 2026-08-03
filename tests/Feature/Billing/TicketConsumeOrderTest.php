<?php

declare(strict_types=1);

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;

/*
 * P5: 消費優先 (monthly → purchased) と単一 consume_source の容量ガード。
 * aigenba TicketService::reserve verbatim + amount 一般化。
 */

function consumeOrderService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

function sourceNet(Organization $organization, ?TicketSource $source): int
{
    $query = TicketLedgerEntry::query()->where('organization_id', $organization->getKey());
    $query = $source === null ? $query->whereNull('source') : $query->where('source', $source);

    return (int) $query->sum('delta');
}

test('monthly が賄えるうちは monthly から消費し最短 monthly 期限を固定する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $monthlyExpiry = CarbonImmutable::now()->addDays(30);
    consumeOrderService()->grantMonthly($organization, 10, $monthlyExpiry, 'monthly:order', '月次付与');
    consumeOrderService()->grantPurchased($organization, 10, 'cs_order', 'pi_order', 10000);

    $reservation = consumeOrderService()->reserve($organization, 3);

    expect($reservation->consume_source)->toBe(TicketSource::Monthly);
    expect($reservation->consume_expires_at?->toIso8601String())->toBe($monthlyExpiry->toIso8601String());
});

test('monthly を使い切ると purchased から消費し consume_expires_at は null', function (): void {
    [$organization] = createOrganizationWithOwner();
    consumeOrderService()->grantMonthly($organization, 3, CarbonImmutable::now()->addDays(30), 'monthly:used', '月次付与');
    consumeOrderService()->grantPurchased($organization, 10, 'cs_used', 'pi_used', 10000);

    $first = consumeOrderService()->reserve($organization, 3);
    consumeOrderService()->commit($first);

    $second = consumeOrderService()->reserve($organization, 3);

    expect($second->consume_source)->toBe(TicketSource::Purchased);
    expect($second->consume_expires_at)->toBeNull();
});

test('commit は単一 source の消費行を 1 行だけ書く (source ごとの分割をしない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    consumeOrderService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:single', '月次付与');
    consumeOrderService()->grantPurchased($organization, 10, 'cs_single', 'pi_single', 10000);

    $reservation = consumeOrderService()->reserve($organization, 3);
    consumeOrderService()->commit($reservation);

    $consumes = TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->get();

    expect($consumes)->toHaveCount(1);
    expect($consumes->firstOrFail()->delta)->toBe(-3);
    expect($consumes->firstOrFail()->source)->toBe(TicketSource::Monthly);
});

test('単一 source 容量ガード: どちらの source も単独で賄えない reserve は不足 (タダ配りを作らない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    consumeOrderService()->grantMonthly($organization, 2, CarbonImmutable::now()->addDays(30), 'monthly:cap', '月次付与');
    consumeOrderService()->grantPurchased($organization, 2, 'cs_cap', 'pi_cap', 2000);

    expect(fn () => consumeOrderService()->reserve($organization, 3))
        ->toThrow(InsufficientTicketsException::class, '残高: 2'); // max(2, 2)

    expect($organization->ticketReservations()->count())->toBe(0);
    // 台帳は無傷 (超過消費が clamp に隠れていない)
    expect(sourceNet($organization, TicketSource::Purchased))->toBe(2);
    expect(sourceNet($organization, TicketSource::Monthly))->toBe(2);
});

test('availableTrueBalance は monthly が purchased の債務を埋めない真値', function (): void {
    [$organization] = createOrganizationWithOwner();
    consumeOrderService()->grantPurchased($organization, 5, 'cs_debt', 'pi_debt', 5000);
    $reservation = consumeOrderService()->reserve($organization, 2);
    consumeOrderService()->commit($reservation);
    consumeOrderService()->clawbackPurchasedByPaymentIntent('pi_debt', 5000); // purchased = -2

    consumeOrderService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:debt', '月次付与');

    expect(consumeOrderService()->availableTrueBalance($organization))->toBe(10);
    expect(sourceNet($organization, TicketSource::Purchased))->toBe(-2); // 債務は台帳で保全
});
