<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\TicketBalanceDto;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketLedgerEntry;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;

/*
 * P5: per-source 会計 (aigenba TicketService::balance verbatim 移植)。
 * バケット = monthly (source=monthly) / purchased (source=purchased ∪ source IS NULL)。
 * 消費行に grant と同じ expires_at を載せることで「+grant と −consume が同時に落ちる」。
 */

function accountingService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

test('期限付き monthly の grant と消費が同時に失効する (全額失効近似の解消)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $expiresAt = CarbonImmutable::now()->addDays(30);
    accountingService()->grantMonthly($organization, 10, $expiresAt, 'monthly:1', '月次付与');

    $reservation = accountingService()->reserve($organization, 3);
    accountingService()->commit($reservation);

    // 消費行は grant と同じ失効境界を持つ
    $consume = TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->firstOrFail();
    expect($consume->source)->toBe(TicketSource::Monthly);
    expect($consume->expires_at?->toIso8601String())->toBe($expiresAt->toIso8601String());

    expect(accountingService()->balance($organization)->monthlyRemaining)->toBe(7);

    // 期限到達で +10 と -3 が同時に合算から落ちる (現行実装なら -3 が残り -3 になる)
    $this->travelTo($expiresAt->addMinute());
    $balance = accountingService()->balance($organization);
    expect($balance->monthlyRemaining)->toBe(0);
    expect($balance->totalAvailable())->toBe(0);
});

test('balance は per-source DTO を返し debt フィールドを持たない', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grant($organization, 5, '手動付与');

    $balance = accountingService()->balance($organization);

    expect($balance)->toBeInstanceOf(TicketBalanceDto::class);
    expect(array_keys($balance->toArray()))->toBe([
        'monthlyRemaining',
        'purchasedRemaining',
        'totalAvailable',
        'activeReservations',
        'nextExpireAt',
    ]);
});

test('per-source clamp: purchased の債務を monthly が肩代わりも打ち消しもしない', function (): void {
    [$organization] = createOrganizationWithOwner();
    // purchased を -2 にする (返金逆仕訳相当の負計上)
    accountingService()->grantPurchased($organization, 3, 'cs_clamp', 'pi_clamp', 3000);
    $reservation = accountingService()->reserve($organization, 3);
    accountingService()->commit($reservation);
    accountingService()->clawbackPurchasedByPaymentIntent('pi_clamp', 3000);

    accountingService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:clamp', '月次付与');

    $balance = accountingService()->balance($organization);
    expect($balance->purchasedRemaining)->toBe(0); // max(-3, 0)
    expect($balance->monthlyRemaining)->toBe(10);
    expect($balance->totalAvailable())->toBe(10);

    // 台帳側では債務が保全されている (clamp は表示・与信のみ)
    $purchasedNetRaw = (int) TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('source', TicketSource::Purchased)
        ->sum('delta');
    expect($purchasedNetRaw)->toBe(-3);
});

test('source IS NULL の台帳行は purchased バケットへ畳まれる (過去消費が帳消しにならない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grantPurchased($organization, 10, 'cs_null', 'pi_null', 10000);

    // P5 以前の消費行相当 (source = null) を append-only で 1 行足す
    $legacyConsume = new TicketLedgerEntry;
    $legacyConsume->organization()->associate($organization);
    $legacyConsume->delta = -4;
    $legacyConsume->kind = TicketLedgerKind::ReserveCommit;
    $legacyConsume->description = 'P5 以前の消費行 (source なし)';
    $legacyConsume->save();

    $balance = accountingService()->balance($organization);
    expect($balance->purchasedRemaining)->toBe(6); // 帳消しにせず purchased へ畳む
    expect($balance->totalAvailable())->toBe(6);
});

test('nextExpireAt は未失効・正 delta の最短 expires_at (ISO8601)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $near = CarbonImmutable::now()->addDays(10);
    $far = CarbonImmutable::now()->addDays(30);
    accountingService()->grantMonthly($organization, 5, $far, 'monthly:far', '遠い期限');
    accountingService()->grantMonthly($organization, 5, $near, 'monthly:near', '近い期限');
    accountingService()->grantPurchased($organization, 5, 'cs_inf', 'pi_inf', 5000); // 無期限は対象外

    expect(accountingService()->balance($organization)->nextExpireAt)
        ->toBe($near->toIso8601String());
});

test('activeReservations は拘束枚数 (SUM(amount))', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grant($organization, 10, '初期付与');

    accountingService()->reserve($organization, 3);
    accountingService()->reserve($organization, 2);

    $balance = accountingService()->balance($organization);
    expect($balance->activeReservations)->toBe(5); // count(2) ではなく枚数
    expect($balance->totalAvailable())->toBe(5);
});

test('無期限 monthly grant のみの組織でも reserve が例外にならず consume_expires_at が null で固定される', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grantMonthly($organization, 100, null, 'monthly:infinite', '無期限月次付与');

    $reservation = accountingService()->reserve($organization, 3);

    expect($reservation->consume_source)->toBe(TicketSource::Monthly);
    expect($reservation->consume_expires_at)->toBeNull();
});

test('availableTrueBalance は per-source clamp 後の合算で常に 0 以上', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grantPurchased($organization, 3, 'cs_true', 'pi_true', 3000);
    $reservation = accountingService()->reserve($organization, 3);
    accountingService()->commit($reservation);
    accountingService()->clawbackPurchasedByPaymentIntent('pi_true', 3000);
    accountingService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:true', '月次付与');

    expect(accountingService()->availableTrueBalance($organization))->toBe(10);
});
