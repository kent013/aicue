<?php

declare(strict_types=1);

use App\Models\Billing\TicketCheckoutSession;
use Carbon\CarbonImmutable;

/*
 * P8b (tc-5): live pending の定義が「行判定 (isLivePending)」と「SQL 判定 (scopeLivePending)」で
 * 乖離しないことを固定する。resume 状態機械 (TicketCheckoutService::resolveResumablePurchase) は
 * scope 側を使うため、片方だけ更新されると resume の可否だけ古い仕様で動く。
 */

test('scopeLivePending と isLivePending は同一の行集合を選ぶ', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $now = CarbonImmutable::now();

    $factory = TicketCheckoutSession::factory()->forOrganization($organization)->initiatedBy($owner);
    $live = $factory->create();
    $stale = $factory->stale()->create();
    $completed = $factory->completed()->create();
    $expired = $factory->expired()->create();

    $scoped = TicketCheckoutSession::query()
        ->where('organization_id', $organization->id)
        ->livePending($now)
        ->pluck('id')
        ->all();

    $rowWise = collect([$live, $stale, $completed, $expired])
        ->filter(fn (TicketCheckoutSession $session): bool => $session->isLivePending($now))
        ->pluck('id')
        ->all();

    expect($scoped)->toBe([$live->id])
        ->and($rowWise)->toBe($scoped);
});

test('期限ちょうど (expires_at == now) は live ではない (排他境界)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $now = CarbonImmutable::now();

    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create(['expires_at' => $now]);

    expect(TicketCheckoutSession::query()->livePending($now)->count())->toBe(0);
});
