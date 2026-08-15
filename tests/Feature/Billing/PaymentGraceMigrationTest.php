<?php

declare(strict_types=1);

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * past_due_since の移行安全性。
 *
 * 既存の past_due 行は「実際に失敗した時刻」を復元できないため、backfill は migration 実行時刻を
 * 起点として打つ = 移行と同時に既存利用者を遮断しない (遡って遮断すると告知なしに突然止まる)。
 * 既に起点がある行は上書きしない (再実行で猶予が先送りされない)。
 */

function runPastDueSinceBackfill(): void
{
    $migration = require database_path(
        'migrations/2026_08_15_000210_backfill_past_due_since_on_subscriptions.php'
    );
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->up();
}

/** past_due_since を直接指定した subscription 行 (列既定は NULL)。 */
function subscriptionWithPastDueSince(string $status, ?CarbonImmutable $since): int
{
    $organization = Organization::factory()->create();
    $subscription = createFakeSubscription($organization, status: $status);
    DB::table('subscriptions')->where('id', $subscription->getKey())->update([
        'past_due_since' => $since,
    ]);

    return $subscription->getKey();
}

test('past_due_since の列既定は NULL', function (): void {
    $organization = Organization::factory()->create();
    $subscription = createFakeSubscription($organization, status: 'past_due');

    expect($subscription->fresh()?->past_due_since)->toBeNull();
});

test('backfill は起点なしの past_due 行を実行時刻で埋める', function (): void {
    $id = subscriptionWithPastDueSince('past_due', null);

    $this->travelTo(CarbonImmutable::parse('2026-08-15 12:00:00'));
    runPastDueSinceBackfill();

    $filled = DB::table('subscriptions')->where('id', $id)->value('past_due_since');
    expect($filled)->not->toBeNull()
        ->and(CarbonImmutable::parse((string) $filled)->toDateTimeString())->toBe('2026-08-15 12:00:00');
});

test('backfill は past_due 以外の行には触れない', function (string $status): void {
    $id = subscriptionWithPastDueSince($status, null);

    runPastDueSinceBackfill();

    expect(DB::table('subscriptions')->where('id', $id)->value('past_due_since'))->toBeNull();
})->with(['active', 'trialing', 'unpaid', 'canceled', 'paused']);

test('backfill は既に起点がある行を上書きしない (再実行で猶予が先送りされない)', function (): void {
    $existing = CarbonImmutable::parse('2026-08-01 09:00:00');
    $id = subscriptionWithPastDueSince('past_due', $existing);

    $this->travelTo(CarbonImmutable::parse('2026-08-15 12:00:00'));
    runPastDueSinceBackfill();
    runPastDueSinceBackfill();

    $value = DB::table('subscriptions')->where('id', $id)->value('past_due_since');
    expect(CarbonImmutable::parse((string) $value)->toDateTimeString())->toBe('2026-08-01 09:00:00');
});
