<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketLedgerService;
use App\Services\Billing\TicketPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Cashier\Events\WebhookReceived;

/*
|--------------------------------------------------------------------------
| P6 (F2): 初回無償チケットの付与契機を「プラン有効化時」へ移す
|--------------------------------------------------------------------------
|
| 付与契機:
|   - free : PersonalPlanService::activate()          → signup_grant:personal:{orgId}
|   - paid : customer.subscription.created (webhook)  → signup_grant:{stripeSubId}
|
| 登録 (CreateNewUser) と invoice.paid は signup grant に一切関与しない (D29)。
| 真実源は organizations.signup_tickets_granted_at (条件付き UPDATE の先取)。
| 二重防御として ticket_ledger_entries の部分 UNIQUE index
| (organization_id WHERE idempotency_key LIKE 'signup_grant:%') が経路を跨いで
| org 生涯 1 行に閉じる。
*/

function activationGrantCustomer(string $stripeId = 'cus_activation_grant'): Organization
{
    // 未契約 (無料枠の自己申告もまだ) の組織を作る = activate() の対象になれる状態
    [$organization] = createOrganizationWithOwner('付与契機テスト組織', grandfatherFreePlan: false);
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
function subscriptionCreatedPayload(
    string $eventId = 'evt_sub_created_grant',
    string $stripeSubId = 'sub_activation_a',
    string $stripeId = 'cus_activation_grant',
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

function activationSignupEntries(Organization $organization): Collection
{
    return $organization->ticketLedgerEntries()
        ->where('idempotency_key', 'like', 'signup_grant:%')
        ->get();
}

function activationBalance(Organization $organization): int
{
    return app(TicketLedgerService::class)->balance($organization)->totalAvailable();
}

test('登録だけではチケットが付与されず marker も立たない', function (): void {
    $this->post('/register', [
        'name' => '山田 太郎',
        'email' => 'p6-signup@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'p6-signup@example.com')->firstOrFail();
    $organization = $user->organizations()->where('is_personal', true)->firstOrFail();

    expect(activationBalance($organization))->toBe(0);
    expect(activationSignupEntries($organization))->toHaveCount(0);
    expect($organization->signup_tickets_granted_at)->toBeNull();
});

test('Personal 有効化で marker 先取と同時に signup_grant:personal:{orgId} が付与される', function (): void {
    $organization = activationGrantCustomer();
    $owner = $organization->users()->firstOrFail();

    $result = app(PersonalPlanService::class)->activate($organization, $owner);

    expect($result->granted)->toBeTrue();

    // LP が約束する枚数 (TicketPricingService) = 実際に付与される枚数 (TicketLedgerService)。
    // 固定値を直書きしないことで config 変更時に文言と実挙動が同時に追随する。
    $promised = app(TicketPricingService::class)->signupGrantTickets();
    expect(activationBalance($organization))->toBe($promised);
    expect($promised)->toBe(config()->integer('billing.signup_grant_tickets'));

    $entries = activationSignupEntries($organization);
    expect($entries)->toHaveCount(1);
    expect($entries->first()?->idempotency_key)->toBe("signup_grant:personal:{$organization->id}");
    expect($entries->first()?->expires_at?->toDateString())->toBe(
        CarbonImmutable::now()->addDays(app(TicketPricingService::class)->signupGrantExpiryDays())->toDateString(),
    );

    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
});

test('marker 済み org を再 activate しても付与されない', function (): void {
    $organization = activationGrantCustomer();
    $owner = $organization->users()->firstOrFail();

    app(PersonalPlanService::class)->activate($organization, $owner);
    $balanceBefore = activationBalance($organization);

    $second = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);

    expect($second->granted)->toBeFalse();
    expect(activationSignupEntries($organization))->toHaveCount(1);
    expect(activationBalance($organization))->toBe($balanceBefore);
});

test('paid サブスク成立 (customer.subscription.created) で signup_grant:{stripeSubId} が付与される', function (): void {
    $organization = activationGrantCustomer();

    event(new WebhookReceived(subscriptionCreatedPayload()));

    $entries = activationSignupEntries($organization);
    expect($entries)->toHaveCount(1);
    expect($entries->first()?->idempotency_key)->toBe('signup_grant:sub_activation_a');
    expect(activationBalance($organization))->toBe(config()->integer('billing.signup_grant_tickets'));
    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
});

test('解約→再契約で再付与されない (marker と部分 UNIQUE index の二重防御)', function (): void {
    $organization = activationGrantCustomer();

    event(new WebhookReceived(subscriptionCreatedPayload('evt_sub_a', 'sub_activation_a')));
    expect(activationSignupEntries($organization))->toHaveCount(1);
    $balanceAfterFirst = activationBalance($organization);

    // 解約
    $deleted = subscriptionCreatedPayload('evt_sub_a_deleted', 'sub_activation_a');
    $deleted['type'] = 'customer.subscription.deleted';
    $deleted['data']['object']['status'] = 'canceled';
    event(new WebhookReceived($deleted));

    // 別 subscription で再契約 → marker が立っているため付与されない
    event(new WebhookReceived(subscriptionCreatedPayload('evt_sub_b', 'sub_activation_b')));

    expect(activationSignupEntries($organization))->toHaveCount(1);
    expect(activationBalance($organization))->toBe($balanceAfterFirst);

    // 二重防御の回帰: marker を人為的に落としても部分 UNIQUE index が二重付与を弾く
    $organization->forceFill(['signup_tickets_granted_at' => null])->save();
    event(new WebhookReceived(subscriptionCreatedPayload('evt_sub_c', 'sub_activation_c')));

    expect(activationSignupEntries($organization))->toHaveCount(1);
    expect(activationBalance($organization))->toBe($balanceAfterFirst);
});

test('free activate 先着 → paid webhook 後着でも二重付与しない', function (): void {
    $organization = activationGrantCustomer();
    $owner = $organization->users()->firstOrFail();

    $result = app(PersonalPlanService::class)->activate($organization, $owner);
    expect($result->granted)->toBeTrue();
    $balanceBefore = activationBalance($organization);

    event(new WebhookReceived(subscriptionCreatedPayload()));

    expect(activationSignupEntries($organization))->toHaveCount(1);
    expect(activationSignupEntries($organization)->first()?->idempotency_key)
        ->toBe("signup_grant:personal:{$organization->id}");
    expect(activationBalance($organization))->toBe($balanceBefore);
});

test('paid webhook 先着 → free activate 後着でも二重付与しない', function (): void {
    $organization = activationGrantCustomer();
    $owner = $organization->users()->firstOrFail();

    event(new WebhookReceived(subscriptionCreatedPayload()));
    expect(activationSignupEntries($organization))->toHaveCount(1);
    $balanceBefore = activationBalance($organization);

    // 後着は例外にせず正常終了する (granted=false)
    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);

    expect($result->granted)->toBeFalse();
    expect(activationSignupEntries($organization))->toHaveCount(1);
    expect(activationSignupEntries($organization)->first()?->idempotency_key)
        ->toBe('signup_grant:sub_activation_a');
    expect(activationBalance($organization))->toBe($balanceBefore);
});

test('付与が失敗すると marker も残らない (free activate: 同一 tx rollback)', function (): void {
    $organization = activationGrantCustomer();
    $owner = $organization->users()->firstOrFail();

    $this->mock(TicketLedgerService::class, function ($mock): void {
        $mock->shouldReceive('grantSignupGrant')
            ->once()
            ->andThrow(new RuntimeException('grant failed'));
        $mock->shouldIgnoreMissing();
    });

    expect(fn () => app(PersonalPlanService::class)->activate($organization, $owner))
        ->toThrow(RuntimeException::class);

    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
});

test('付与が失敗すると marker も残らない (paid webhook: 同一 tx rollback)', function (): void {
    $organization = activationGrantCustomer();

    $this->mock(TicketLedgerService::class, function ($mock): void {
        $mock->shouldReceive('grantSignupGrant')
            ->once()
            ->andThrow(new RuntimeException('grant failed'));
        $mock->shouldIgnoreMissing();
    });

    try {
        event(new WebhookReceived(subscriptionCreatedPayload()));
    } catch (Throwable) {
        // 冪等マシンの failed 記録経路。marker の原子性が本テストの関心
    }

    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
});

test('sub id が解決できない customer.subscription.created は付与しない (fail-closed)', function (): void {
    $organization = activationGrantCustomer();

    $payload = subscriptionCreatedPayload('evt_sub_nosubid');
    unset($payload['data']['object']['id']);

    event(new WebhookReceived($payload));

    expect(activationSignupEntries($organization))->toHaveCount(0);
    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
});

test('invoice.paid では signup grant が走らない (D29 の回帰)', function (): void {
    $organization = activationGrantCustomer();

    event(new WebhookReceived([
        'id' => 'evt_invoice_paid_no_signup',
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => 'in_no_signup',
                'customer' => 'cus_activation_grant',
                'billing_reason' => 'subscription_create',
            ],
        ],
    ]));

    expect(activationSignupEntries($organization))->toHaveCount(0);
    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
});

test('subscription.created と invoice.paid(subscription_create) が両方来ても signup grant は高々 1 回', function (): void {
    // 実運用では初回契約でこの 2 イベントが必ず両方届く (順序は Stripe 側の都合で前後する)。
    // D29 で付与契機を created 単独へ寄せたため、順序に関わらず付与は 1 回でなければならない。
    $createdFirst = activationGrantCustomer('cus_order_created_first');
    $invoiceFirst = activationGrantCustomer('cus_order_invoice_first');

    $invoicePaidPayload = fn (string $eventId, string $stripeId): array => [
        'id' => $eventId,
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => 'in_'.$eventId,
                'customer' => $stripeId,
                'billing_reason' => 'subscription_create',
            ],
        ],
    ];

    // 順序 A: created → invoice.paid
    event(new WebhookReceived(subscriptionCreatedPayload('evt_a_created', 'sub_order_a', 'cus_order_created_first')));
    event(new WebhookReceived($invoicePaidPayload('evt_a_invoice', 'cus_order_created_first')));

    expect(activationSignupEntries($createdFirst))->toHaveCount(1);
    expect(activationSignupEntries($createdFirst)->first()?->idempotency_key)->toBe('signup_grant:sub_order_a');
    expect(activationBalance($createdFirst))->toBe(config()->integer('billing.signup_grant_tickets'));

    // 順序 B: invoice.paid → created (invoice.paid は marker も立てない = created が唯一の契機)
    event(new WebhookReceived($invoicePaidPayload('evt_b_invoice', 'cus_order_invoice_first')));
    expect($invoiceFirst->refresh()->signup_tickets_granted_at)->toBeNull();

    event(new WebhookReceived(subscriptionCreatedPayload('evt_b_created', 'sub_order_b', 'cus_order_invoice_first')));

    expect(activationSignupEntries($invoiceFirst))->toHaveCount(1);
    expect(activationSignupEntries($invoiceFirst)->first()?->idempotency_key)->toBe('signup_grant:sub_order_b');
    expect(activationBalance($invoiceFirst))->toBe(config()->integer('billing.signup_grant_tickets'));
});

test('移行期に旧鍵で付与済みの org を activate しても再付与されない', function (): void {
    $organization = activationGrantCustomer();
    $owner = $organization->users()->firstOrFail();

    // P1〜P6 の移行期に登録された org 相当 (旧鍵 signup_grant:org:{id} + marker 済み)
    app(TicketLedgerService::class)->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
    $organization->forceFill(['signup_tickets_granted_at' => CarbonImmutable::now()])->save();
    $balanceBefore = activationBalance($organization);

    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);

    expect($result->granted)->toBeFalse();
    expect(activationSignupEntries($organization))->toHaveCount(1);
    expect(activationBalance($organization))->toBe($balanceBefore);
});
