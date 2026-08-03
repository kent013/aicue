<?php

declare(strict_types=1);

use App\Enums\Billing\AutoRechargeDisabledReason;
use App\Enums\Billing\BillingFeedbackKind;
use App\Enums\Billing\BillingNotificationType;
use App\Enums\Billing\SignupFundingChoice;
use App\Enums\CheckoutSessionStatus;
use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\BillingNotification;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Organization;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\Support\FakeAutoRechargeGateway;
use Tests\Support\FakeStripeGateway;

/*
 * P9 / T1004: 有償契約の決済カードをオートリチャージへ流用する。
 *
 * 3 段の fail-closed:
 *  (1) Request 層で consent_version の現行版一致を **checkout 開始前**に検証 (422)
 *  (2) recordPreConsent は enabled=false の同意 row のみ (課金経路に触れない)
 *  (3) applyReusedPaymentMethod が **適格性先行** — 不適格なら Stripe にも DB にも触らない
 */

beforeEach(function (): void {
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
    $this->service = app(AutoRechargeService::class);
});

function pmReuseNotificationCount(Organization $organization): int
{
    return BillingNotification::query()
        ->where('organization_id', $organization->getKey())
        ->where('type', BillingNotificationType::AutoRechargeEnabled->value)
        ->count();
}

/** @return array{Organization, BillingCheckoutSession} */
function pmReuseFixture(?string $fundingChoice = SignupFundingChoice::AutoRecharge->value): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_pmreuse_1';
    $organization->save();

    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt((string) Str::ulid(), 'standard')
        ->create([
            'stripe_session_id' => 'cs_test_pmreuse_1',
            'funding_choice' => $fundingChoice,
        ]);

    return [$organization, $session];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pmReusePayload(Organization $organization, string $eventId = 'evt_pmreuse_1', array $overrides = []): array
{
    $object = array_merge([
        'id' => 'cs_test_pmreuse_1',
        'mode' => 'subscription',
        'customer' => 'cus_pmreuse_1',
        'payment_status' => 'paid',
        'subscription' => 'sub_pmreuse_1',
        'metadata' => [
            'purpose' => 'subscription_start',
            'org_ref' => (string) $organization->id,
            'plan_code' => 'standard',
        ],
    ], $overrides);

    return ['id' => $eventId, 'type' => 'checkout.session.completed', 'data' => ['object' => $object]];
}

// ------------------------------------------------------------------
// dispatch 条件 (webhook 同期処理)
// ------------------------------------------------------------------

test('funding=auto_recharge + paid の completed で marker が立ち Job が dispatch される', function (): void {
    Queue::fake();
    [$organization, $session] = pmReuseFixture();

    event(new WebhookReceived(pmReusePayload($organization)));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
    expect($session->pm_reuse_dispatched_at)->not->toBeNull();
    Queue::assertPushed(
        ReuseSubscriptionPaymentMethodJob::class,
        fn (ReuseSubscriptionPaymentMethodJob $job): bool => $job->organizationId === (int) $organization->id
            && $job->stripeSubscriptionId === 'sub_pmreuse_1',
    );
});

test('決済未確定 (unpaid / payment_status 欠落) では dispatch されず marker も立たない', function (mixed $paymentStatus): void {
    Queue::fake();
    [$organization, $session] = pmReuseFixture();

    event(new WebhookReceived(pmReusePayload($organization, overrides: ['payment_status' => $paymentStatus])));

    expect($session->refresh()->pm_reuse_dispatched_at)->toBeNull();
    Queue::assertNotPushed(ReuseSubscriptionPaymentMethodJob::class);
})->with([
    'unpaid' => ['unpaid'],
    'null' => [null],
]);

test('funding=later / null (Plans 経路) では dispatch されない', function (?string $funding): void {
    Queue::fake();
    [$organization, $session] = pmReuseFixture($funding);

    event(new WebhookReceived(pmReusePayload($organization)));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
    expect($session->pm_reuse_dispatched_at)->toBeNull();
    Queue::assertNotPushed(ReuseSubscriptionPaymentMethodJob::class);
})->with([
    'later' => [SignupFundingChoice::Later->value],
    'null' => [null],
]);

test('subscription フィールドは string / expanded object の両形を受理し、それ以外は dispatch しない', function (mixed $subscription, bool $dispatched): void {
    Queue::fake();
    [$organization] = pmReuseFixture();

    event(new WebhookReceived(pmReusePayload($organization, overrides: ['subscription' => $subscription])));

    $dispatched
        ? Queue::assertPushed(ReuseSubscriptionPaymentMethodJob::class)
        : Queue::assertNotPushed(ReuseSubscriptionPaymentMethodJob::class);
})->with([
    // expand 指定の無い通常 payload は string ID = 本番の主経路
    'string id' => ['sub_pmreuse_1', true],
    'expanded object' => [['id' => 'sub_pmreuse_1', 'status' => 'active'], true],
    'null' => [null, false],
    'empty string' => ['', false],
    'other type' => [123, false],
]);

test('C-2 結合: Expired 行への遅延 completed でも marker が立ち dispatch される / 再送では marker が更新されない', function (): void {
    Queue::fake();
    [$organization, $session] = pmReuseFixture();
    $session->forceFill(['status' => CheckoutSessionStatus::Expired->value])->save();

    event(new WebhookReceived(pmReusePayload($organization)));

    $marker = $session->refresh()->pm_reuse_dispatched_at;
    expect($marker)->not->toBeNull();

    // 終局 no-op = 再送では marker が延びない
    event(new WebhookReceived(pmReusePayload($organization, 'evt_pmreuse_2')));
    expect($session->refresh()->pm_reuse_dispatched_at?->toIso8601String())->toBe($marker?->toIso8601String());
    Queue::assertPushed(ReuseSubscriptionPaymentMethodJob::class, 1);
});

test('webhook 同期処理は外向き Stripe API を撃たない (PM 解決は Job 側のみ)', function (): void {
    Queue::fake();
    [$organization] = pmReuseFixture();

    event(new WebhookReceived(pmReusePayload($organization)));

    // settleSubscriptionCheckout は marker を立てて dispatch するだけ (retrieve しない)
    expect($this->gateway->resolvedSubscriptions)->toHaveCount(0);
    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
});

// ------------------------------------------------------------------
// applyReusedPaymentMethod (適格性先行 fail-closed)
// ------------------------------------------------------------------

test('事前同意 (v2) があれば default PM 設定 + snapshot + enabled=true + 通知 1 通', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();

    $enabledNow = $this->service->applyReusedPaymentMethod($organization, 'pm_reused_1');

    expect($enabledNow)->toBeTrue();
    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(1);

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeTrue();
    expect($config->stripe_payment_method_id)->toBe('pm_reused_1');
    expect($config->failure_count)->toBe(0);
    expect(pmReuseNotificationCount($organization))->toBe(1);
});

test('中核 fail-closed: 同意失効 (v1 残存) では Stripe にもローカルにも一切触れない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $config = TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
    $config->forceFill(['consent_version' => 'v1'])->save();

    $enabledNow = $this->service->applyReusedPaymentMethod($organization, 'pm_reused_1');

    expect($enabledNow)->toBeFalse();
    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
    expect($config->refresh()->enabled)->toBeFalse();
    expect($config->stripe_payment_method_id)->toBeNull();
});

test('config なし / disabled_reason あり は完全 no-op (gateway 呼び出し 0)', function (bool $withConfig): void {
    [$organization] = createOrganizationWithOwner();
    if ($withConfig) {
        $config = TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
        $config->forceFill(['disabled_reason' => AutoRechargeDisabledReason::User])->save();
    }

    expect($this->service->applyReusedPaymentMethod($organization, 'pm_reused_1'))->toBeFalse();
    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
})->with(['config なし' => [false], 'disabled_reason あり' => [true]]);

test('再実行 (enabled 遷移済み) は no-op で通知も再送されない', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();

    expect($this->service->applyReusedPaymentMethod($organization, 'pm_reused_1'))->toBeTrue();
    expect($this->service->applyReusedPaymentMethod($organization, 'pm_reused_1'))->toBeFalse();

    expect(pmReuseNotificationCount($organization))->toBe(1);
});

test('部分適用の顕在化: default PM 更新後に適格性が失われたら RuntimeException (silent no-op にしない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $config = TicketAutoRecharge::factory()->for($organization)->preConsented()->create();

    // Stripe 側 (customer default PM) だけ更新済みで、DB 確定の直前に適格性が失われた状況を作る。
    // applyReusedPaymentMethod が gateway に触れるのは setDefaultPaymentMethod のみ。
    $gateway = Mockery::mock(AutoRechargeGatewayInterface::class);
    $gateway->shouldReceive('setDefaultPaymentMethod')
        ->once()
        ->andReturnUsing(function () use ($config): void {
            $config->forceFill(['disabled_reason' => AutoRechargeDisabledReason::User])->save();
        });
    app()->instance(AutoRechargeGatewayInterface::class, $gateway);

    /** @var AutoRechargeService $service */
    $service = app(AutoRechargeService::class);

    // silent no-op ではなく例外で顕在化する (Job retry で収束 / 継続不適格は failed_jobs で検知)。
    expect(fn (): bool => $service->applyReusedPaymentMethod($organization, 'pm_reused_partial'))
        ->toThrow(RuntimeException::class);

    // 例外で TX が rollback されるため、ローカル snapshot は一切書かれていない。
    $config->refresh();
    expect($config->enabled)->toBeFalse();
    expect($config->stripe_payment_method_id)->toBeNull();
    expect(pmReuseNotificationCount($organization))->toBe(0);
});

test('空文字 PM は fail-fast (InvalidArgumentException)', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();

    $this->service->applyReusedPaymentMethod($organization, '');
})->throws(InvalidArgumentException::class);

// ------------------------------------------------------------------
// Job
// ------------------------------------------------------------------

test('Job 一気通貫: 事前同意 → PM 解決 → enabled=true', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
    $this->gateway->subscriptionPaymentMethodId = 'pm_from_subscription';

    (new ReuseSubscriptionPaymentMethodJob((int) $organization->id, 'sub_x'))
        ->handle($this->gateway, $this->service);

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeTrue();
    expect($config->stripe_payment_method_id)->toBe('pm_from_subscription');
});

test('Job の軽量 guard: isAutoEnablePending=false なら Stripe retrieve を呼ばない', function (): void {
    [$organization] = createOrganizationWithOwner(); // config なし = pending でない

    (new ReuseSubscriptionPaymentMethodJob((int) $organization->id, 'sub_x'))
        ->handle($this->gateway, $this->service);

    expect($this->gateway->resolvedSubscriptions)->toHaveCount(0);
    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
});

test('PM 解決不能 (null) は no-op で詰まない (例外を投げない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
    $this->gateway->subscriptionPaymentMethodId = null;

    (new ReuseSubscriptionPaymentMethodJob((int) $organization->id, 'sub_x'))
        ->handle($this->gateway, $this->service);

    expect($this->gateway->resolvedSubscriptions)->toHaveCount(1);
    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
    expect(TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail()->enabled)->toBeFalse();
});

test('org 不在は例外なしで return する', function (): void {
    (new ReuseSubscriptionPaymentMethodJob(999999, 'sub_x'))->handle($this->gateway, $this->service);

    expect($this->gateway->resolvedSubscriptions)->toHaveCount(0);
});

// ------------------------------------------------------------------
// setupPending (窓) / 着地 flash / 同意 fail-closed
// ------------------------------------------------------------------

test('setupPending: 決済確定した auto_recharge 契約 + 有効な事前同意の待機中は true', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
    BillingCheckoutSession::factory()
        ->for($organization)
        ->completed()
        ->fundingAutoRecharge()
        ->pmReuseDispatched()
        ->create();

    expect($this->service->settingsFor($organization, true)->setupPending)->toBeTrue();
});

test('setupPending: 同意失効 / funding=later / marker なし / 30 分超は false', function (callable $arrange): void {
    [$organization] = createOrganizationWithOwner();
    $arrange($organization);

    expect($this->service->settingsFor($organization, true)->setupPending)->toBeFalse();
})->with([
    '同意失効 (v1) では再同意導線を隠さない' => [function (Organization $org): void {
        $config = TicketAutoRecharge::factory()->for($org)->preConsented()->create();
        $config->forceFill(['consent_version' => 'v1'])->save();
        BillingCheckoutSession::factory()->for($org)->completed()->fundingAutoRecharge()->pmReuseDispatched()->create();
    }],
    'funding=later の契約完了' => [function (Organization $org): void {
        TicketAutoRecharge::factory()->for($org)->preConsented()->create();
        BillingCheckoutSession::factory()->for($org)->completed()->pmReuseDispatched()->create();
    }],
    'marker なし (未決済 completed)' => [function (Organization $org): void {
        TicketAutoRecharge::factory()->for($org)->preConsented()->create();
        BillingCheckoutSession::factory()->for($org)->completed()->fundingAutoRecharge()->create();
    }],
    'dispatch から 30 分超' => [function (Organization $org): void {
        TicketAutoRecharge::factory()->for($org)->preConsented()->create();
        BillingCheckoutSession::factory()->for($org)->completed()->fundingAutoRecharge()
            ->pmReuseDispatched(CarbonImmutable::now()->subMinutes(31))->create();
    }],
]);

test('着地 flash: 自 org の auto_recharge 完了 session は ?highlight=auto-recharge へ 303', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->completed()
        ->fundingAutoRecharge()
        ->pmReuseDispatched()
        ->create();

    $this->actingAs($owner)
        ->get('/billing?session_id='.$session->stripe_session_id)
        ->assertStatus(303)
        ->assertRedirect('/billing?highlight=auto-recharge')
        ->assertSessionHas('info', fn (string $m): bool => str_contains($m, '自動的に有効になります'));
});

test('着地 flash: marker なし / 同意失効では確定表現を避けた誘導文言になる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->completed()
        ->fundingAutoRecharge()
        ->create();

    $this->actingAs($owner)
        ->get('/billing?session_id='.$session->stripe_session_id)
        ->assertStatus(303)
        ->assertSessionHas('info', 'お支払いを受け付けました。オートリチャージの設定はこの画面から確認できます。');
});

test('着地 flash: 他 org / setup_payment_method の session_id は T1004 着地にならない (IDOR 防御)', function (bool $otherOrg): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$foreign] = createOrganizationWithOwner('他組織');

    $factory = BillingCheckoutSession::factory()
        ->for($otherOrg ? $foreign : $organization)
        ->completed()
        ->fundingAutoRecharge();
    $session = ($otherOrg ? $factory : $factory->setupPaymentMethod())->create();

    // F-3-04 以降、?session_id は P9 の feedback 着地として canonical へ畳まれる (303)。
    // 守る不変条件は「T1004 の highlight 着地にならない / 成功文言を出さない」こと。
    $this->actingAs($owner)
        ->get('/billing?session_id='.$session->stripe_session_id)
        ->assertRedirect('/billing')
        ->assertSessionMissing('info')
        ->assertSessionMissing(BillingFeedbackKind::FLASH_KEY);
})->with(['他 org' => [true], 'setup_payment_method' => [false]]);

test('同意 fail-closed (Request 層): consent_version 欠落 / 旧版は 422 で行も Stripe 呼び出しも増えない', function (?string $consentVersion, string $expectedMessage): void {
    $this->app->singleton(StripeGatewayInterface::class, fn (): FakeStripeGateway => new FakeStripeGateway);
    [$organization, $owner] = createOrganizationWithOwner();

    $payload = [
        'plan_code' => 'standard',
        'subscription_attempt_token' => (string) Str::ulid(),
        'funding_choice' => SignupFundingChoice::AutoRecharge->value,
    ];
    if ($consentVersion !== null) {
        $payload['consent_version'] = $consentVersion;
    }

    $this->actingAs($owner)
        ->from('/onboarding/checkout')
        ->post('/billing/checkout', $payload)
        ->assertInvalid(['consent_version' => $expectedMessage]);

    expect(TicketAutoRecharge::query()->where('organization_id', $organization->id)->exists())->toBeFalse();
    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->exists())->toBeFalse();

    /** @var FakeStripeGateway $fake */
    $fake = app(StripeGatewayInterface::class);
    expect($fake->created)->toHaveCount(0);
})->with([
    '欠落' => [null, '自動購入への同意が必要です。'],
    '旧版 v1' => ['v1', '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。'],
]);

test('同意記録の順序: recordPreConsent → startCheckout の順で走り、課金は発生しない', function (): void {
    $this->app->singleton(StripeGatewayInterface::class, function (): FakeStripeGateway {
        $fake = new FakeStripeGateway;
        $fake->failOnCreate = true; // Checkout 作成が失敗しても同意 row は残る

        return $fake;
    });
    [$organization, $owner] = createOrganizationWithOwner();

    try {
        $this->withoutExceptionHandling()->actingAs($owner)->post('/billing/checkout', [
            'plan_code' => 'standard',
            'subscription_attempt_token' => (string) Str::ulid(),
            'funding_choice' => SignupFundingChoice::AutoRecharge->value,
            'consent_version' => config()->string('billing.auto_recharge.consent_version'),
        ]);
    } catch (Throwable) {
        // Checkout 作成の失敗そのものは本テストの検証対象ではない
    }

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeFalse();
    expect($config->consent_version)->toBe(config()->string('billing.auto_recharge.consent_version'));
    expect(TicketAutoRechargeAttempt::query()->where('organization_id', $organization->id)->count())->toBe(0);
});

test("consent_version='v2' 改定の効果: v1 同意行は自動失効し PM 流用でも有効化されない", function (): void {
    [$organization] = createOrganizationWithOwner();
    $config = TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
    $config->forceFill(['consent_version' => 'v1'])->save();

    expect($this->service->isAutoEnablePending($organization))->toBeFalse();
    expect($this->service->settingsFor($organization, true)->pendingAutoEnable)->toBeFalse();

    (new ReuseSubscriptionPaymentMethodJob((int) $organization->id, 'sub_x'))
        ->handle($this->gateway, $this->service);

    expect($config->refresh()->enabled)->toBeFalse();
    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
});
