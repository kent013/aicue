<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketLedgerService;
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
| **P6 以降の付与契機**: free = PersonalPlanService::activate()、
| paid = customer.subscription.created (SubscriptionService::grantSignupInitialTickets)。
| 登録 (CreateNewUser) と invoice.paid は付与にも marker にも関与しない。
*/

function grantOnceCustomer(string $stripeId = 'cus_grant_once'): Organization
{
    // 未契約 (無料枠の自己申告もまだ) の組織 = activate() の対象になれる状態
    [$organization] = createOrganizationWithOwner('テスト組織', grandfatherFreePlan: false);
    // stripe_id は Cashier customer column (状態キー)。テストでは明示代入する
    $organization->stripe_id = $stripeId;
    $organization->save();

    return $organization;
}

/**
 * paid サブスク成立 (customer.subscription.created)。
 * signup grant に必要なのは data.object.id (sub id) と data.object.customer のみ。
 *
 * @return array<string, mixed>
 */
function grantOnceSubscriptionCreatedPayload(
    string $eventId = 'evt_grant_once',
    string $stripeId = 'cus_grant_once',
    string $stripeSubId = 'sub_grant_once',
): array {
    return [
        'id' => $eventId,
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => $stripeSubId,
                'customer' => $stripeId,
                'status' => 'active',
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

test('登録では付与もマーカーも起きない (付与契機はプラン有効化時)', function (): void {
    $this->post('/register', [
        'name' => '山田 太郎',
        'email' => 'grant-once@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'grant-once@example.com')->firstOrFail();
    $organization = $user->organizations()->firstOrFail();

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    expect(grantOnceSignupEntryCount($organization))->toBe(0);
    expect($organization->signup_tickets_granted_at)->toBeNull();
});

test('登録後に Personal を有効化すると 1 回だけ付与される (再 activate は付与しない)', function (): void {
    $this->post('/register', [
        'name' => '鈴木 花子',
        'email' => 'grant-once-2@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'grant-once-2@example.com')->firstOrFail();
    $organization = $user->organizations()->firstOrFail();

    $first = app(PersonalPlanService::class)->activate($organization, $user);
    expect($first->granted)->toBeTrue();
    expect($organization->ticketLedgerEntries()->firstOrFail()->idempotency_key)
        ->toBe("signup_grant:personal:{$organization->id}");
    $balanceAfterFirst = app(TicketLedgerService::class)->balance($organization)->totalAvailable();
    expect($balanceAfterFirst)->toBe(config()->integer('billing.signup_grant_tickets'));

    $second = app(PersonalPlanService::class)->activate($organization->refresh(), $user);

    expect($second->granted)->toBeFalse();
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceAfterFirst);
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
});

test('マーカー済み組織は activate でも先取できない (条件付き UPDATE の 0 件)', function (): void {
    $organization = grantOnceCustomer('cus_marked');
    $owner = $organization->users()->firstOrFail();

    // マーカーだけを先に立てた状態 (= 既に付与契機が走った org 相当)
    $organization->forceFill(['signup_tickets_granted_at' => CarbonImmutable::now()])->save();

    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);

    expect($result->granted)->toBeFalse();
    expect(grantOnceSignupEntryCount($organization))->toBe(0);
});

test('free 有効化済みの組織に paid webhook (subscription.created) が来ても二重付与しない', function (): void {
    $organization = grantOnceCustomer();
    $owner = $organization->users()->firstOrFail();

    app(PersonalPlanService::class)->activate($organization, $owner);
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();

    event(new WebhookReceived(grantOnceSubscriptionCreatedPayload()));

    // marker が主・部分 UNIQUE index (signup_grant:personal:% ↔ signup_grant:sub_%) が保険
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
});

test('paid webhook で付与済みの組織を free 有効化しても二重付与しない (逆順)', function (): void {
    $organization = grantOnceCustomer();
    $owner = $organization->users()->firstOrFail();

    event(new WebhookReceived(grantOnceSubscriptionCreatedPayload()));
    expect(grantOnceSignupEntryCount($organization))->toBe(1);
    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();

    // paid webhook 経路も同型の claim パターン (marker 先取できたときのみ付与) に従うため、
    // webhook 時点でマーカーが立つ。よって後続の activate は先取できず granted=false になる
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

    event(new WebhookReceived(grantOnceSubscriptionCreatedPayload()));

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
        event(new WebhookReceived(grantOnceSubscriptionCreatedPayload()));
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

    // 既存の付与履歴を作る (サービス経由。台帳は append-only)。
    // 旧鍵 (signup_grant:org:{id}) = P6 以前に登録経路で付与された移行期データ相当。
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
