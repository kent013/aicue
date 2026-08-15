<?php

declare(strict_types=1);

use App\Enums\Billing\TicketCommitResult;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Recovery\RecoveryOutcome;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketReservation;
use App\Services\Billing\TicketLedgerService;
use App\Services\Recovery\Streams\ExpiredTicketReservationStream;
use Carbon\CarbonImmutable;

/*
 * P5: commit-wins (aigenba TicketService::commit verbatim)。
 * TTL 切れ / stale releaser 先着でも生存 hold は課金する。二重課金は
 * idempotency_key `consume:{reservationId}` UNIQUE が防ぐ。
 * 失効 monthly hold のみ決定的 no-charge (ReleasedExpired)。
 */

function commitWinsService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

test('TTL 切れで Released 化された生存予約でも commit は課金する (commit-wins)', function (): void {
    [$organization] = createOrganizationWithOwner();
    commitWinsService()->grantPurchased($organization, 10, 'cs_wins', 'pi_wins', 10000);

    $reservation = commitWinsService()->reserve($organization, 3);
    $this->travel(31)->minutes();
    releaseStaleTicketReservations();
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);

    $result = commitWinsService()->commit($reservation);

    expect($result)->toBe(TicketCommitResult::Committed);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released); // 一方向遷移は壊さない
    expect(commitWinsService()->balance($organization)->totalAvailable())->toBe(7); // 課金は台帳が真実源
});

test('再 commit は AlreadyCommitted で消費行は 1 行のみ', function (): void {
    [$organization] = createOrganizationWithOwner();
    commitWinsService()->grantPurchased($organization, 10, 'cs_again', 'pi_again', 10000);

    $reservation = commitWinsService()->reserve($organization, 3);
    expect(commitWinsService()->commit($reservation))->toBe(TicketCommitResult::Committed);
    expect(commitWinsService()->commit($reservation))->toBe(TicketCommitResult::AlreadyCommitted);

    $consumes = TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->get();
    expect($consumes)->toHaveCount(1);
    expect(commitWinsService()->balance($organization)->totalAvailable())->toBe(7);
});

test('失効した monthly hold の commit は課金せず ReleasedExpired', function (): void {
    [$organization] = createOrganizationWithOwner();
    $expiresAt = CarbonImmutable::now()->addDays(30);
    commitWinsService()->grantMonthly($organization, 10, $expiresAt, 'monthly:expired', '月次付与');

    $reservation = commitWinsService()->reserve($organization, 3);
    $this->travelTo($expiresAt->addMinute());

    $result = commitWinsService()->commit($reservation);

    expect($result)->toBe(TicketCommitResult::ReleasedExpired);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
    expect(TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->count())->toBe(0);
});

test('無期限 monthly 予約は TTL 経過後も ReleasedExpired にならず課金される', function (): void {
    [$organization] = createOrganizationWithOwner();
    commitWinsService()->grantMonthly($organization, 10, null, 'monthly:inf-commit', '無期限月次付与');

    $reservation = commitWinsService()->reserve($organization, 3);
    $this->travel(31)->minutes();

    expect(commitWinsService()->commit($reservation))->toBe(TicketCommitResult::Committed);
    expect(commitWinsService()->balance($organization)->totalAvailable())->toBe(7);
});

test('滞留回収は TTL 未超過でも失効 monthly hold を解放する', function (): void {
    [$organization] = createOrganizationWithOwner();
    // monthly 期限 (10 分後) < reserve TTL (30 分) にして「TTL 切れ」枝と切り分ける
    $expiresAt = CarbonImmutable::now()->addMinutes(10);
    commitWinsService()->grantMonthly($organization, 10, $expiresAt, 'monthly:stale', '月次付与');

    $reservation = commitWinsService()->reserve($organization, 3);
    expect($reservation->consume_expires_at?->toIso8601String())->toBe($expiresAt->toIso8601String());

    $this->travel(11)->minutes(); // TTL (30 分) は未超過だが monthly hold は失効

    expect(releaseStaleTicketReservations())->toBe(1);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
});

/*
 * 滞留回収の並行競合 (T171): 候補列挙とロック取得の間に状態が動いたときの振る舞い。
 * 行ロック下で滞留の述語ごと再評価するため、競合は例外ではなく Skipped になる。
 */

test('候補列挙後に commit された予約は Skipped で、回収は成功のまま終わる', function (): void {
    [$organization] = createOrganizationWithOwner();
    commitWinsService()->grantPurchased($organization, 10, 'cs_race', 'pi_race', 10000);
    $reservation = commitWinsService()->reserve($organization, 3);
    $this->travel(31)->minutes();

    $stream = app(ExpiredTicketReservationStream::class);
    $sweptAt = CarbonImmutable::now();
    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$reservation->id]);

    // 別プロセスが先に commit した状況を再現する
    expect(commitWinsService()->commit($reservation))->toBe(TicketCommitResult::Committed);

    // 例外を投げない = 運用アラートを鳴らさない (正常事象として数える)
    expect($stream->recover($reservation->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Committed);
});

test('候補列挙後に expires_at が延長された予約は解放されない', function (): void {
    [$organization] = createOrganizationWithOwner();
    commitWinsService()->grantPurchased($organization, 10, 'cs_extend', 'pi_extend', 10000);
    $reservation = commitWinsService()->reserve($organization, 3);
    $this->travel(31)->minutes();

    $stream = app(ExpiredTicketReservationStream::class);
    $sweptAt = CarbonImmutable::now();
    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$reservation->id]);

    // 述語が成立しなくなる状況 (有効期限の延長) を作る
    TicketReservation::query()->whereKey($reservation->id)
        ->update(['expires_at' => $sweptAt->addMinutes(30)]);

    expect($stream->recover($reservation->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Reserved);
});
