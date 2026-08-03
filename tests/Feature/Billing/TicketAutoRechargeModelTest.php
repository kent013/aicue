<?php

declare(strict_types=1);

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Support\Security\MassAssignmentProtectedKeys;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * P8a: オートリチャージのモデル / DB 不変条件。
 */

test('org あたり pending attempt は同時に 1 つ (partial unique index が最終防衛)', function (): void {
    [$organization] = createOrganizationWithOwner();

    TicketAutoRechargeAttempt::factory()->for($organization)->create();

    // 2 件目の pending は DB の partial unique が弾く (pgsql は TX を abort させるため
    // 例外後に同一 TX でクエリを続けない)
    expect(fn () => TicketAutoRechargeAttempt::factory()->for($organization)->create())
        ->toThrow(QueryException::class);
});

test('終端済み attempt は何件でも共存できる (partial index の述語が pending のみ)', function (): void {
    [$organization] = createOrganizationWithOwner();

    TicketAutoRechargeAttempt::factory()->for($organization)->paid()->create();
    TicketAutoRechargeAttempt::factory()->for($organization)->failed()->create();
    TicketAutoRechargeAttempt::factory()->for($organization)->canceled()->create();
    TicketAutoRechargeAttempt::factory()->for($organization)->create(); // pending 1 件

    expect(TicketAutoRechargeAttempt::query()->where('organization_id', $organization->id)->count())->toBe(4);
});

test('別 org の pending は互いに干渉しない', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');

    TicketAutoRechargeAttempt::factory()->for($organizationA)->create();
    TicketAutoRechargeAttempt::factory()->for($organizationB)->create();

    expect(TicketAutoRechargeAttempt::query()->where('status', AutoRechargeAttemptStatus::Pending)->count())->toBe(2);
});

test('stripe_invoice_id は全体で UNIQUE (同一 invoice が 2 attempt に紐づかない)', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');

    TicketAutoRechargeAttempt::factory()->for($organizationA)->paid()->create(['stripe_invoice_id' => 'in_shared']);

    expect(fn () => TicketAutoRechargeAttempt::factory()->for($organizationB)->paid()->create(['stripe_invoice_id' => 'in_shared']))
        ->toThrow(QueryException::class);
});

test('設定行は 1 org 1 行 (organization_id UNIQUE)', function (): void {
    [$organization] = createOrganizationWithOwner();

    TicketAutoRecharge::factory()->for($organization)->create();

    expect(fn () => TicketAutoRecharge::factory()->for($organization)->create())
        ->toThrow(QueryException::class);
});

test('max_count > threshold_count は DB CHECK で強制される (pgsql/mysql)', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
        $this->markTestSkipped('CHECK 制約は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応)');
    }

    [$organization] = createOrganizationWithOwner();

    expect(fn () => TicketAutoRecharge::factory()->for($organization)->create([
        'threshold_count' => 50,
        'max_count' => 50,
    ]))->toThrow(QueryException::class);
});

test('保護キーは $fillable に載っていない (mass assignment 出口防御)', function (): void {
    $protected = MassAssignmentProtectedKeys::all();

    foreach ([new TicketAutoRecharge, new TicketAutoRechargeAttempt] as $model) {
        expect(array_intersect($protected, $model->getFillable()))->toBe([]);
    }
});

test('attempt_ulid は UNIQUE (Stripe 冪等キーの外部識別子)', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $ulid = strtolower((string) Str::ulid());

    TicketAutoRechargeAttempt::factory()->for($organizationA)->paid()->create(['attempt_ulid' => $ulid]);

    expect(fn () => TicketAutoRechargeAttempt::factory()->for($organizationB)->paid()->create(['attempt_ulid' => $ulid]))
        ->toThrow(QueryException::class);
});

test('組織の物理削除で設定・試行行が cascade 削除される (Organization は soft delete のため forceDelete)', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->create();
    TicketAutoRechargeAttempt::factory()->for($organization)->create();

    // soft delete では FK cascade は走らない (行が残るのが正しい挙動)
    $organization->delete();
    expect(TicketAutoRecharge::query()->count())->toBe(1)
        ->and(TicketAutoRechargeAttempt::query()->count())->toBe(1);

    $organization->forceDelete();
    expect(TicketAutoRecharge::query()->count())->toBe(0)
        ->and(TicketAutoRechargeAttempt::query()->count())->toBe(0);
});
