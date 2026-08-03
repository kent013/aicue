<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketLedgerService;
use App\Services\Organization\OrganizationProvisioningService;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Events\WebhookReceived;

/*
|--------------------------------------------------------------------------
| 初回無償チケット付与の「org 単位で生涯 1 回」
|--------------------------------------------------------------------------
|
| 真実源は organizations.signup_tickets_granted_at マーカー (条件付き UPDATE の先取)。
| 二重防御として ticket_ledger_entries の部分 UNIQUE index
| (organization_id WHERE idempotency_key LIKE 'signup_grant:%') が経路・キー種別を跨いで
| org 生涯 1 行に閉じる。
|
| **移行期規約 (P6 まで)**: 付与契機は登録時 (CreateNewUser) のまま維持し、同一 tx で
| マーカーを先取する。free 有効化 (PersonalPlanService::activate) は先取できたときのみ付与する。
*/

function grantOnceCustomer(string $stripeId = 'cus_grant_once'): Organization
{
    [$organization] = createOrganizationWithOwner();
    // stripe_id は Cashier customer column (状態キー)。テストでは明示代入する
    $organization->stripe_id = $stripeId;
    $organization->save();

    return $organization;
}

/**
 * 初回契約の invoice.paid (billing_reason=subscription_create)。
 * signup grant は plan 解決より前に走るため lines は不要 (月次付与は plan なしで no-op)。
 *
 * @return array<string, mixed>
 */
function grantOnceInvoicePaidPayload(string $eventId = 'evt_grant_once', string $stripeId = 'cus_grant_once'): array
{
    return [
        'id' => $eventId,
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => 'in_grant_once',
                'customer' => $stripeId,
                'billing_reason' => 'subscription_create',
            ],
        ],
    ];
}

function grantOnceSignupEntryCount(Organization $organization): int
{
    return $organization->ticketLedgerEntries()
        ->where('idempotency_key', 'like', 'signup_grant:%')
        ->count();
}

test('移行期: 登録時に付与され、同一 tx でマーカーも立つ', function (): void {
    $this->post('/register', [
        'name' => '山田 太郎',
        'email' => 'grant-once@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'grant-once@example.com')->firstOrFail();
    $organization = $user->organizations()->where('is_personal', true)->firstOrFail();

    // 付与契機・枚数は不変 (現行挙動)
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())
        ->toBe(config()->integer('billing.signup_grant_tickets'));
    expect($organization->ticketLedgerEntries()->firstOrFail()->idempotency_key)
        ->toBe("signup_grant:org:{$organization->id}");

    // 移行期に追加される唯一の効果: マーカーが同時に立つ
    expect($organization->signup_tickets_granted_at)->not->toBeNull();
});

test('移行期: 登録済み (マーカー済み) の組織を activate しても再付与されない', function (): void {
    $this->post('/register', [
        'name' => '鈴木 花子',
        'email' => 'grant-once-2@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'grant-once-2@example.com')->firstOrFail();
    $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();

    $result = app(PersonalPlanService::class)->activate($organization, $user);

    expect($result->granted)->toBeFalse();
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
});

test('マーカー済み組織への直接 claim は先取できない (条件付き UPDATE の 0 件)', function (): void {
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, 'マーカー済み組織');

    expect(app(PersonalPlanService::class)->claimSignupGrantMarker($organization))->toBeTrue();
    // 2 回目は既にマーカーが立っているため先取できない (= 付与しない)
    expect(app(PersonalPlanService::class)->claimSignupGrantMarker($organization))->toBeFalse();
});

test('free 有効化済みの組織に paid webhook (subscription_create) が来ても二重付与しない', function (): void {
    $organization = grantOnceCustomer();
    $owner = $organization->users()->firstOrFail();

    app(PersonalPlanService::class)->activate($organization, $owner);
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();

    event(new WebhookReceived(grantOnceInvoicePaidPayload()));

    // 部分 UNIQUE index が経路 (signup_grant:personal:% ↔ signup_grant:org:%) を跨いで弾く
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
});

test('paid webhook で付与済みの組織を free 有効化しても二重付与しない (逆順)', function (): void {
    $organization = grantOnceCustomer();
    $owner = $organization->users()->firstOrFail();

    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();

    // paid webhook 経路も移行期規約 (marker 先取できたときのみ付与) に従うため、webhook 時点で
    // マーカーが立つ。よって後続の activate はマーカーを先取できず granted=false になる
    // (= 真実源であるマーカーと付与実績が一致する)。
    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();

    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);

    expect($result->granted)->toBeFalse();
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
});

test('登録経由でない組織の初回契約 (paid webhook) でもマーカーが立つ (付与実績と真実源が一致する)', function (): void {
    // 登録時 grant を受けていない組織 (= Organizations/Create で作った追加組織相当) を用意する
    $organization = grantOnceCustomer();
    expect($organization->signup_tickets_granted_at)->toBeNull();
    expect(grantOnceSignupEntryCount($organization))->toBe(0);

    event(new WebhookReceived(grantOnceInvoicePaidPayload()));

    // 付与が起きたなら、その事実がマーカーにも反映されていること (marker = 付与の唯一の真実源)
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
});

test('paid webhook: 付与が失敗したら marker も rollback される (marker だけ残って付与が永久に失われない)', function (): void {
    $organization = grantOnceCustomer();
    expect($organization->signup_tickets_granted_at)->toBeNull();

    // grantSignupGrant が失敗する状況を作る (付与のみ throw。marker の UPDATE は既に走っている)
    $this->mock(TicketLedgerService::class, function ($mock): void {
        $mock->shouldReceive('grantSignupGrant')
            ->once()
            ->andThrow(new RuntimeException('grant failed'));
        // 月次付与など他経路は素通しさせない (本テストは signup grant の原子性のみを見る)
        $mock->shouldIgnoreMissing();
    });

    // webhook 処理は例外を握って failed 記録する契約のため、ここでは例外の有無を問わない
    try {
        event(new WebhookReceived(grantOnceInvoicePaidPayload()));
    } catch (Throwable) {
        // 冪等マシンの failed 記録経路。marker の原子性が本テストの関心
    }

    // marker だけ commit されていたら、以後 claim できず二度と付与されない = 恒久的な取りこぼし
    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
    expect(grantOnceSignupEntryCount($organization))->toBe(0);
});

test('backfill migration: 付与履歴のある組織はマーカーが立ち、無い組織は null のまま (冪等)', function (): void {
    $granted = grantOnceCustomer('cus_backfill_granted');
    $notGranted = Organization::factory()->create();

    // 既存の付与履歴を作る (サービス経由。台帳は append-only)
    $grantedAt = CarbonImmutable::parse('2026-05-01 09:00:00');
    $this->travelTo($grantedAt);
    app(TicketLedgerService::class)->grantSignupGrant($granted, "signup_grant:org:{$granted->id}");
    $this->travelBack();

    // migration 適用前の既存データ相当へ戻す (マーカー未設定 + 付与済み)
    $granted->forceFill(['signup_tickets_granted_at' => null])->save();

    $migration = require database_path('migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php');
    $migration->up();

    expect($granted->refresh()->signup_tickets_granted_at?->toDateTimeString())
        ->toBe('2026-05-01 09:00:00');
    expect($notGranted->refresh()->signup_tickets_granted_at)->toBeNull();

    // 冪等: 再実行しても値は動かない
    $migration->up();
    expect($granted->refresh()->signup_tickets_granted_at?->toDateTimeString())
        ->toBe('2026-05-01 09:00:00');
});
