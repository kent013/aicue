<?php

declare(strict_types=1);

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketReservationStatus;
use App\Models\Billing\TicketLedgerEntry;
use App\Services\Billing\TicketLedgerService;

/*
 * P5 で維持する AI-CUE 固有の逸脱 (AGENTS.md 不変条件 #7) の回帰網。
 * - amount ベース reserve (aigenba の 1 枚固定に退化していない)
 * - reserve → commit / release の 2 フェーズ (直接デクリメントを書かない)
 */

function amountBasedService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

test('reserve は amount 枚をまとめて 1 行の予約にする (1 枚固定に退化しない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    amountBasedService()->grant($organization, 10, '初期付与');

    $reservation = amountBasedService()->reserve($organization, 5);

    expect($organization->ticketReservations()->count())->toBe(1);
    expect($reservation->amount)->toBe(5);
    expect($reservation->status)->toBe(TicketReservationStatus::Reserved);
    expect(amountBasedService()->balance($organization)->activeReservations)->toBe(5);
});

test('解析 / レンダの可変コストがそれぞれの枚数で reserve → commit される', function (): void {
    [$organization] = createOrganizationWithOwner();
    amountBasedService()->grant($organization, 10, '初期付与');

    $analysisCost = config()->integer('manual.analysis_ticket_cost');
    $renderCost = config()->integer('manual.render_ticket_cost');
    expect($analysisCost)->not->toBe($renderCost); // 可変コスト前提が失われていない

    amountBasedService()->commit(amountBasedService()->reserve($organization, $analysisCost));
    amountBasedService()->commit(amountBasedService()->reserve($organization, $renderCost));

    $deltas = TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->orderBy('id')
        ->pluck('delta')
        ->all();
    expect($deltas)->toBe([-$analysisCost, -$renderCost]);
    expect(amountBasedService()->balance($organization)->totalAvailable())
        ->toBe(10 - $analysisCost - $renderCost);
});

test('reserve → release は台帳を減らさない (直接デクリメントが無い)', function (): void {
    [$organization] = createOrganizationWithOwner();
    amountBasedService()->grant($organization, 10, '初期付与');

    $reservation = amountBasedService()->reserve($organization, 4);
    expect(amountBasedService()->balance($organization)->totalAvailable())->toBe(6);

    amountBasedService()->release($reservation);

    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
    expect(amountBasedService()->balance($organization)->totalAvailable())->toBe(10);
    // 監査痕跡は delta=0 の release 行のみ (負 delta を書かない)
    $release = TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::Release)
        ->firstOrFail();
    expect($release->delta)->toBe(0);
});
