<?php

declare(strict_types=1);

use App\Enums\Billing\TicketCommitResult;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketReservationStatus;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Models\Billing\TicketLedgerEntry;
use App\Services\Billing\TicketLedgerService;

/*
 * チケット台帳 (2 フェーズ消費プリミティブ)。
 * 残高 = SUM(ledger.delta) − reserved 予約合計。消費は reserve → commit / release のみ。
 */

function ticketService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

test('grant すると残高が増える', function (): void {
    [$organization] = createOrganizationWithOwner();

    ticketService()->grant($organization, 10, '初期付与');

    expect(ticketService()->balance($organization)->totalAvailable())->toBe(10);
    $entry = $organization->ticketLedgerEntries()->firstOrFail();
    expect($entry->kind)->toBe(TicketLedgerKind::Grant);
    expect($entry->delta)->toBe(10);
});

test('reserve → commit で残高が減る (負 delta が台帳に記録される)', function (): void {
    [$organization] = createOrganizationWithOwner();
    ticketService()->grant($organization, 10, '初期付与');

    $reservation = ticketService()->reserve($organization, 3);
    // 予約中は残高から拘束される
    expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);

    ticketService()->commit($reservation);

    expect($reservation->status)->toBe(TicketReservationStatus::Committed);
    expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);
    $commitEntry = $organization->ticketLedgerEntries()
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->firstOrFail();
    expect($commitEntry->delta)->toBe(-3);
    expect($commitEntry->reservation_id)->toBe($reservation->id);
});

test('reserve → release で残高が戻る', function (): void {
    [$organization] = createOrganizationWithOwner();
    ticketService()->grant($organization, 10, '初期付与');

    $reservation = ticketService()->reserve($organization, 3);
    expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);

    ticketService()->release($reservation);

    expect($reservation->status)->toBe(TicketReservationStatus::Released);
    expect(ticketService()->balance($organization)->totalAvailable())->toBe(10);
});

test('残高不足の reserve は InsufficientTicketsException', function (): void {
    [$organization] = createOrganizationWithOwner();
    ticketService()->grant($organization, 5, '初期付与');

    expect(fn () => ticketService()->reserve($organization, 6))
        ->toThrow(InsufficientTicketsException::class);
    expect($organization->ticketReservations()->count())->toBe(0);
});

test('連続 reserve で残高超過にならない (2 本目が例外)', function (): void {
    [$organization] = createOrganizationWithOwner();
    ticketService()->grant($organization, 10, '初期付与');

    ticketService()->reserve($organization, 10);

    // 1 本目が残高を拘束しているため 2 本目は不足
    expect(fn () => ticketService()->reserve($organization, 1))
        ->toThrow(InsufficientTicketsException::class);
    expect($organization->ticketReservations()->count())->toBe(1);
});

test('committed の再 commit は冪等 no-op / 再 release は例外 (commit-wins)', function (): void {
    [$organization] = createOrganizationWithOwner();
    ticketService()->grant($organization, 10, '初期付与');

    $reservation = ticketService()->reserve($organization, 3);
    expect(ticketService()->commit($reservation))->toBe(TicketCommitResult::Committed);

    // P5 commit-wins: status guard を撤去したため再 commit は例外ではなく冪等 no-op
    expect(ticketService()->commit($reservation))->toBe(TicketCommitResult::AlreadyCommitted);
    // release の意味論は不変 (非 Reserved は LogicException)
    expect(fn () => ticketService()->release($reservation))->toThrow(LogicException::class);
    // 二重 commit が無いこと (負 delta は 1 行のみ)
    expect($organization->ticketLedgerEntries()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(1);
    expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);
});

test('releaseStale は expires_at 超過の reserved だけを解放する', function (): void {
    [$organization] = createOrganizationWithOwner();
    ticketService()->grant($organization, 10, '初期付与');

    $stale = ticketService()->reserve($organization, 4);
    expect(ticketService()->balance($organization)->totalAvailable())->toBe(6);

    // TTL (30 分) を超過させる
    $this->travel(31)->minutes();
    $fresh = ticketService()->reserve($organization, 2);

    $released = ticketService()->releaseStale();

    expect($released)->toBe(1);
    expect($stale->refresh()->status)->toBe(TicketReservationStatus::Released);
    expect($fresh->refresh()->status)->toBe(TicketReservationStatus::Reserved);
    // stale 分の拘束は解け、fresh 分だけが残る
    expect(ticketService()->balance($organization)->totalAvailable())->toBe(8);
});

test('台帳エントリは append-only (update / delete は例外)', function (): void {
    [$organization] = createOrganizationWithOwner();
    ticketService()->grant($organization, 10, '初期付与');

    $entry = TicketLedgerEntry::query()->firstOrFail();

    expect(function () use ($entry): void {
        $entry->delta = 999;
        $entry->save();
    })->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
});
