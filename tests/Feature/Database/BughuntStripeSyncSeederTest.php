<?php

declare(strict_types=1);

use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\TicketLedgerService;
use Database\Seeders\BughuntStripeSyncSeeder;
use Illuminate\Support\Facades\DB;

/*
 * BughuntStripeSyncSeeder: bug-hunt env 専用の課金 fixture (有料プラン組織のみ
 * active subscription + 初期チケット 100。free 組織は未契約のまま温存)。
 * 三重 fail-secure (fake_externals / bughunt.local / bug_hunt DB 名) を固定する。
 *
 * DB 名は接続を張り替えず setDatabaseName で名前のみ差し替える (実 DB は test DB のまま)。
 * try/finally で必ず復元する。
 */

/**
 * bughunt guard 3 軸を成立させた状態で callback を実行する (env / DB 名は必ず復元)。
 */
function runWithBughuntGuardSatisfied(Closure $callback): void
{
    config(['testing.fake_externals' => true]);

    $app = app();
    $originalEnv = $app['env'];
    $connection = DB::connection();
    $originalDb = $connection->getDatabaseName();

    try {
        $app['env'] = 'bughunt.local';
        $connection->setDatabaseName('bug_hunt');
        $callback();
    } finally {
        $app['env'] = $originalEnv;
        $connection->setDatabaseName($originalDb);
    }
}

test('guard 不成立 (既定の testing env / 非 bughunt DB 名) では no-op', function (): void {
    [$organization] = createOrganizationWithOwner('標準組織');
    $organization->forceFill(['plan_code' => 'standard'])->save();

    $this->seed(BughuntStripeSyncSeeder::class);

    expect(Subscription::query()->count())->toBe(0);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
});

test('fake_externals=true でも env=testing のままなら no-op (flag 単独では点火しない)', function (): void {
    [$organization] = createOrganizationWithOwner('標準組織');
    $organization->forceFill(['plan_code' => 'standard'])->save();

    config(['testing.fake_externals' => true]);
    $this->seed(BughuntStripeSyncSeeder::class);

    expect(Subscription::query()->count())->toBe(0);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
});

test('guard 成立時: standard 組織のみ active sub + チケット 100 を付与し、再実行しても増えない (冪等)', function (): void {
    [$standardOrg] = createOrganizationWithOwner('標準組織');
    $standardOrg->forceFill(['plan_code' => 'standard'])->save();
    [$freeOrg] = createOrganizationWithOwner('無料組織');
    $freeOrg->forceFill(['plan_code' => 'free'])->save();

    runWithBughuntGuardSatisfied(function (): void {
        $this->seed(BughuntStripeSyncSeeder::class);
        // 冪等: 再実行しても subscription 1 行・残高 100 のまま増えない
        $this->seed(BughuntStripeSyncSeeder::class);
    });

    $standardOrg = Organization::query()->findOrFail($standardOrg->id);
    $freeOrg = Organization::query()->findOrFail($freeOrg->id);
    $tickets = app(TicketLedgerService::class);

    // standard 組織: active subscription (課金ゲート通過) + チケット 100
    expect(app(BillingAccess::class)->hasActiveAccess($standardOrg))->toBeTrue();
    expect($standardOrg->subscriptions()->count())->toBe(1);
    expect($tickets->balance($standardOrg)->totalAvailable())->toBe(100);

    // free 組織: subscription もチケットも付与されない (課金なし経路の温存)
    expect($freeOrg->subscriptions()->count())->toBe(0);
    expect($tickets->balance($freeOrg)->totalAvailable())->toBe(0);
});

test('既存 subscription が past_due でも再実行で active に回復する (行は増えない)', function (): void {
    [$organization] = createOrganizationWithOwner('標準組織');
    $organization->forceFill(['plan_code' => 'standard'])->save();
    createFakeSubscription($organization, 'past_due');

    runWithBughuntGuardSatisfied(function (): void {
        $this->seed(BughuntStripeSyncSeeder::class);
    });

    $organization = Organization::query()->findOrFail($organization->id);
    expect($organization->subscriptions()->count())->toBe(1);
    expect($organization->subscription('default')?->stripe_status)->toBe('active');
});
