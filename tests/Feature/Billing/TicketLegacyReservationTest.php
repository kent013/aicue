<?php

declare(strict_types=1);

use App\Enums\Billing\TicketCommitResult;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketReservation;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Support\Facades\Log;

/*
 * P5: デプロイ時に in-flight だった legacy 予約 (consume_source / consume_expires_at = null) の
 * 移行期挙動 (aigenba verbatim)。backfill しないため 2 列 null のまま到達する。
 */

function legacyReservationService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

test('legacy 予約は per-source hold に計上されないが activeReservations には計上される', function (): void {
    [$organization] = createOrganizationWithOwner();
    legacyReservationService()->grantMonthly($organization, 10, null, 'monthly:legacy-hold', '無期限月次付与');

    TicketReservation::factory()->forOrganization($organization)->legacy()->create(['amount' => 4]);

    $balance = legacyReservationService()->balance($organization);
    // 表示は保守側 (legacy も拘束として見せる)
    expect($balance->activeReservations)->toBe(4);
    expect($balance->totalAvailable())->toBe(6);
    // 一方 per-source hold には計上されないため与信は拘束されない (aigenba と同一の既知窓)
    expect(legacyReservationService()->availableTrueBalance($organization))->toBe(10);
});

test('legacy 予約の commit は monthly / 予約 TTL 境界で 1 行計上し警告を残す', function (): void {
    [$organization] = createOrganizationWithOwner();
    legacyReservationService()->grantMonthly($organization, 10, null, 'monthly:legacy-commit', '無期限月次付与');
    $reservation = TicketReservation::factory()->forOrganization($organization)->legacy()->create(['amount' => 3]);

    Log::spy();

    expect(legacyReservationService()->commit($reservation))->toBe(TicketCommitResult::Committed);

    $consume = TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->firstOrFail();
    expect($consume->delta)->toBe(-3);
    expect($consume->source)->toBe(TicketSource::Monthly);
    expect($consume->expires_at?->toIso8601String())
        ->toBe($reservation->refresh()->expires_at->toIso8601String());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'legacy reservation without consume_source'))
        ->once();
});

test('legacy 予約の再 commit は AlreadyCommitted', function (): void {
    [$organization] = createOrganizationWithOwner();
    legacyReservationService()->grantMonthly($organization, 10, null, 'monthly:legacy-again', '無期限月次付与');
    $reservation = TicketReservation::factory()->forOrganization($organization)->legacy()->create(['amount' => 3]);

    legacyReservationService()->commit($reservation);

    expect(legacyReservationService()->commit($reservation))->toBe(TicketCommitResult::AlreadyCommitted);
    expect(TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->count())->toBe(1);
});
