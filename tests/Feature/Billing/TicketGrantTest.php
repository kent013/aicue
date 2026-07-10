<?php

declare(strict_types=1);

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;

/*
 * 冪等付与 (grantMonthly / grantSignupGrant / grantPurchased) と期限付き残高。
 * - idempotency_key UNIQUE: 同一キーの再実行は no-op (webhook 再送の二重付与防止)
 * - balance(): expires_at 到達で期限付き付与が合算から外れる
 */

function grantService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

test('grantMonthly は同一 idempotency_key の再実行で二重付与しない', function (): void {
    [$organization] = createOrganizationWithOwner();

    grantService()->grantMonthly($organization, 100, null, 'monthly:in_1', '月次付与');
    grantService()->grantMonthly($organization, 100, null, 'monthly:in_1', '月次付与');

    expect(grantService()->balance($organization))->toBe(100);
    expect($organization->ticketLedgerEntries()->count())->toBe(1);
    $entry = $organization->ticketLedgerEntries()->firstOrFail();
    expect($entry->kind)->toBe(TicketLedgerKind::Grant);
    expect($entry->source)->toBe(TicketSource::Monthly);
    expect($entry->idempotency_key)->toBe('monthly:in_1');
    expect($entry->granted_at)->not->toBeNull();
});

test('idempotency_key が異なれば別付与として計上される', function (): void {
    [$organization] = createOrganizationWithOwner();

    grantService()->grantMonthly($organization, 100, null, 'monthly:in_1', '月次付与');
    grantService()->grantMonthly($organization, 100, null, 'monthly:in_2', '月次付与');

    expect(grantService()->balance($organization))->toBe(200);
});

test('期限付き付与は expires_at 到達で残高から外れる', function (): void {
    [$organization] = createOrganizationWithOwner();

    grantService()->grantMonthly(
        $organization,
        10,
        CarbonImmutable::now()->addDays(30),
        'monthly:in_exp',
        '期限付き付与',
    );
    grantService()->grantMonthly($organization, 5, null, 'monthly:in_perm', '無期限付与');

    expect(grantService()->balance($organization))->toBe(15);

    $this->travel(31)->days();

    // 期限付き 10 枚だけが失効し、無期限 5 枚が残る
    expect(grantService()->balance($organization))->toBe(5);
});

test('期限内の付与は reserve → commit で消費できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    grantService()->grantMonthly(
        $organization,
        10,
        CarbonImmutable::now()->addDays(30),
        'monthly:in_use',
        '期限付き付与',
    );

    $reservation = grantService()->reserve($organization, 3);
    grantService()->commit($reservation);

    expect(grantService()->balance($organization))->toBe(7);
});

test('grantSignupGrant は config の枚数・期限で冪等付与する', function (): void {
    [$organization] = createOrganizationWithOwner();
    config()->set('billing.signup_grant_tickets', 10);
    config()->set('billing.signup_grant_expiry_days', 30);

    grantService()->grantSignupGrant($organization, 'signup_grant:sub_1');
    grantService()->grantSignupGrant($organization, 'signup_grant:sub_1');

    expect(grantService()->balance($organization))->toBe(10);
    $entry = $organization->ticketLedgerEntries()->firstOrFail();
    expect($entry->source)->toBe(TicketSource::Monthly);
    expect($entry->expires_at?->toDateString())
        ->toBe(CarbonImmutable::now()->addDays(30)->toDateString());

    // 期限到達で失効する
    $this->travel(31)->days();
    expect(grantService()->balance($organization))->toBe(0);
});

test('grantSignupGrant は config が不正 (0 以下) なら停止する', function (): void {
    [$organization] = createOrganizationWithOwner();
    config()->set('billing.signup_grant_tickets', 0);

    expect(fn () => grantService()->grantSignupGrant($organization, 'signup_grant:sub_bad'))
        ->toThrow(InvalidArgumentException::class);
});

test('grantPurchased は checkout session id で冪等付与し、返金正本キーを記録する', function (): void {
    [$organization] = createOrganizationWithOwner();

    grantService()->grantPurchased($organization, 10, 'cs_1', 'pi_1', 5000);
    grantService()->grantPurchased($organization, 10, 'cs_1', 'pi_1', 5000); // 再送

    expect(grantService()->balance($organization))->toBe(10);
    expect($organization->ticketLedgerEntries()->count())->toBe(1);
    $entry = $organization->ticketLedgerEntries()->firstOrFail();
    expect($entry->source)->toBe(TicketSource::Purchased);
    expect($entry->stripe_checkout_session_id)->toBe('cs_1');
    expect($entry->payment_intent_id)->toBe('pi_1');
    expect($entry->purchase_amount)->toBe(5000);
    expect($entry->expires_at)->toBeNull(); // 買い切りは無期限
});
