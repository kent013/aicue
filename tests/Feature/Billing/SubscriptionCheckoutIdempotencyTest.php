<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\DataTransferObjects\Billing\RemoteSubscriptionState;
use App\Enums\Billing\BillingFeedbackKind;
use App\Enums\Billing\SubscriptionSwapOutcome;
use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Tests\Support\FakeStripeGateway;

/*
 * P9 (要件 1-7): サブスク checkout の冪等状態機械。
 *
 * - attempt_token 単位の冪等 (UNIQUE(org, intent, attempt_token) + Stripe idempotency key)
 * - org-wide の live pending dedup (subscription は org 単位の singleton)
 * - 他 org / 他 user の token は **認可より前に 404** (存在オラクル封じ)
 * - P8a の intent=setup_payment_method 行と混線しない (intent 軸の token 空間分離)
 */

function subCheckoutFake(): FakeStripeGateway
{
    /** @var FakeStripeGateway $fake */
    $fake = app(StripeGatewayInterface::class);

    return $fake;
}

function subAttemptToken(): string
{
    return (string) Str::ulid();
}

beforeEach(function (): void {
    $this->app->singleton(StripeGatewayInterface::class, fn (): FakeStripeGateway => new FakeStripeGateway);
});

test('同一 token + 同一 plan の 2 連投は 1 行に収束し既存 checkout_url へ replay する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    $first = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ]);
    // Inertia::location は非 Inertia リクエストでは 302 (Inertia リクエストでは 409 + X-Inertia-Location)
    $first->assertRedirectContains('https://checkout.stripe.test/');

    $second = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ]);
    $second->assertRedirectContains('https://checkout.stripe.test/');

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
    // Stripe 作成は 1 回だけ (2 回目は DB 行の checkout_url を再生)
    expect(subCheckoutFake()->created)->toHaveCount(1);
    expect($first->headers->get('Location'))->toBe($second->headers->get('Location'));
});

test('同一 token + 別 plan_code は 422 で、行も Stripe 呼び出しも増えない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ])->assertRedirectContains('https://checkout.stripe.test/');

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/billing/plans")
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'starter',
            'subscription_attempt_token' => $token,
        ])
        ->assertInvalid(['plan_code']);

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
    expect(subCheckoutFake()->created)->toHaveCount(1);
});

test('idempotency_key は sub_start:{token} で、同 key の再呼び出しは同一 sessionId を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ])->assertRedirectContains('https://checkout.stripe.test/');

    $row = BillingCheckoutSession::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($row->idempotency_key)->toBe('sub_start:'.$token);
    expect(subCheckoutFake()->created[0]['idempotencyKey'])->toBe('sub_start:'.$token);

    // key 空間の分離: チケット (purchase:) / カード登録 (auto-recharge-setup:) と衝突しない
    expect($row->idempotency_key)->not->toStartWith('purchase:');
    expect($row->idempotency_key)->not->toStartWith('auto-recharge-setup:');

    // 同一 key の再呼び出しで同一 sessionId (Stripe idempotency replay と同じ収束特性)
    $again = subCheckoutFake()->createSubscriptionCheckout(
        $organization, 'price_test', 'https://a.test', 'https://b.test', [], 'sub_start:'.$token,
    );
    expect($again->sessionId)->toBe($row->stripe_session_id);
});

test('他 org の token は manageBilling を持つ owner でも 404 で、行が作られない', function (): void {
    [$otherOrg, $otherOwner] = createOrganizationWithOwner('別組織');
    [$organization, $owner] = createOrganizationWithOwner();

    $token = subAttemptToken();
    BillingCheckoutSession::factory()
        ->for($otherOrg)
        ->initiatedBy((int) $otherOwner->id)
        ->withAttempt($token, 'standard')
        ->create();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ])->assertNotFound();

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->exists())->toBeFalse();
    expect(subCheckoutFake()->created)->toHaveCount(0);
});

test('同 org の他 user の token も 404 (token 所有者判定は actor スコープ)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);

    $token = subAttemptToken();
    BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $other->id)
        ->withAttempt($token, 'standard')
        ->create();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ])->assertNotFound();

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
    expect(subCheckoutFake()->created)->toHaveCount(0);
});

test('completed 行の token 再送は purchase_already_received を flash して /organizations/{slug}/billing へ倒し Stripe を呼ばない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt($token, 'standard')
        ->completed()
        ->create();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ])
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::PurchaseAlreadyReceived->value);

    expect(subCheckoutFake()->created)->toHaveCount(0);
});

test('expired / failed 行の token 再送は checkout_retry_required を flash して /organizations/{slug}/billing へ倒す', function (string $state): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    $factory = BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt($token, 'standard');

    ($state === 'expired' ? $factory->expired() : $factory->failed())->create();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ])
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::CheckoutRetryRequired->value);

    expect(subCheckoutFake()->created)->toHaveCount(0);
})->with(['expired', 'failed']);

test('別 token・同 plan の live pending は org-wide dedup で warning に倒れる (別 user でも 1 本)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);

    BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $other->id)
        ->withAttempt(subAttemptToken(), 'standard')
        ->create();

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/billing/plans")
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'standard',
            'subscription_attempt_token' => subAttemptToken(),
        ])
        ->assertRedirect("/organizations/{$organization->slug}/billing/plans")
        ->assertSessionHas('warning', '既に進行中の Checkout があります。数分お待ちください。');

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
    expect(subCheckoutFake()->created)->toHaveCount(0);
});

test('別 token・別 plan の live pending: expire=complete は CheckoutInProgress で停止する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt(subAttemptToken(), 'starter')
        ->create();

    subCheckoutFake()->expireResult = 'complete';

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/billing/plans")
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'standard',
            'subscription_attempt_token' => subAttemptToken(),
        ])
        ->assertRedirect("/organizations/{$organization->slug}/billing/plans")
        ->assertSessionHas('error', '直前の決済が処理中です。数分お待ちください。');

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
    expect(subCheckoutFake()->created)->toHaveCount(0);
});

test('別 token・別 plan の live pending: expire が throw したら local を上書きせず停止する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $old = BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt(subAttemptToken(), 'starter')
        ->create();

    subCheckoutFake()->failOnExpire = true;

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/billing/plans")
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'standard',
            'subscription_attempt_token' => subAttemptToken(),
        ])
        ->assertRedirect("/organizations/{$organization->slug}/billing/plans")
        ->assertSessionHas('error', '前回の決済セッションの整理に失敗しました。 数分後に再試行してください。');

    expect($old->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
    expect(subCheckoutFake()->created)->toHaveCount(0);
});

test('別 token・別 plan の live pending: expire=expired なら旧行が Expired になり新規発行が続行する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $old = BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt(subAttemptToken(), 'starter')
        ->create();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => subAttemptToken(),
    ])->assertRedirectContains('https://checkout.stripe.test/');

    expect($old->refresh()->status)->toBe(CheckoutSessionStatus::Expired->value);
    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(2);
    expect(subCheckoutFake()->created)->toHaveCount(1);
});

test('initiated_by_user_id が必ず非 null で記録される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => subAttemptToken(),
    ])->assertRedirectContains('https://checkout.stripe.test/');

    $row = BillingCheckoutSession::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($row->initiated_by_user_id)->toBe((int) $owner->id);
    expect($row->intent)->toBe(CheckoutIntent::SubscriptionStart->value);
});

test('P8a の setup 行が同 org に live pending でも段 2/3/4 に一切干渉しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    // 同一 attempt_token を持つ setup 行 (intent 軸で token 空間が分かれている)
    BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->setupPaymentMethod()
        ->withAttemptToken($token)
        ->create();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ])->assertRedirectContains('https://checkout.stripe.test/');

    expect(subCheckoutFake()->created)->toHaveCount(1);
    expect(BillingCheckoutSession::query()
        ->where('organization_id', $organization->id)
        ->where('intent', CheckoutIntent::SubscriptionStart->value)
        ->count())->toBe(1);
});

test('既に valid な subscription を持つ org は行を作らず error flash で停止する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/billing/plans")
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'standard',
            'subscription_attempt_token' => subAttemptToken(),
        ])
        ->assertRedirect("/organizations/{$organization->slug}/billing/plans")
        ->assertSessionHas('error', '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->exists())->toBeFalse();
});

test('並行 race: INSERT 直前に同 token 行が割り込んでも 500 にならず replay へ収束する (実 driver の UNIQUE)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    // 「Stripe 作成に成功した直後、DB INSERT の直前に別プロセスが同じ (org, intent, attempt_token)
    //  を先に commit した」を実 DB の UNIQUE で再現する (例外の自作注入ではない = isUniqueViolation()
    //  の判定文字列が実 driver (pgsql) の制約名と一致することまで固定する)。
    $racing = new class($organization, $owner) implements StripeGatewayInterface
    {
        public FakeStripeGateway $inner;

        public function __construct(
            private readonly Organization $organization,
            private readonly User $owner,
        ) {
            $this->inner = new FakeStripeGateway;
        }

        public function createSubscriptionCheckout(
            Organization $organization,
            string $stripePriceId,
            string $successUrl,
            string $cancelUrl,
            array $metadata,
            string $idempotencyKey,
        ): CreatedCheckoutSession {
            $created = $this->inner->createSubscriptionCheckout(
                $organization, $stripePriceId, $successUrl, $cancelUrl, $metadata, $idempotencyKey,
            );

            // 先着プロセスの行 (別 session id・同 attempt_token) を commit しておく。
            $token = (string) str_replace('sub_start:', '', $idempotencyKey);
            BillingCheckoutSession::factory()
                ->for($this->organization)
                ->initiatedBy((int) $this->owner->getKey())
                ->withAttempt($token, 'standard')
                ->create([
                    'stripe_session_id' => 'cs_test_winner_'.$token,
                    'idempotency_key' => 'sub_start:winner:'.$token,
                    'checkout_url' => 'https://checkout.stripe.test/c/pay/cs_test_winner',
                ]);

            return $created;
        }

        public function swapSubscriptionPrices(
            Organization $organization,
            string $basePriceId,
            string $idempotencyKey,
        ): SubscriptionSwapOutcome {
            return $this->inner->swapSubscriptionPrices($organization, $basePriceId, $idempotencyKey);
        }

        public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
        {
            return $this->inner->retrieveSubscriptionState($stripeSubscriptionId);
        }

        public function expireCheckoutSession(string $stripeSessionId): string
        {
            return $this->inner->expireCheckoutSession($stripeSessionId);
        }

        public function createPortalSession(
            Organization $organization,
            string $returnUrl,
        ): ExternalBillingRedirect {
            return $this->inner->createPortalSession($organization, $returnUrl);
        }

        public function syncCustomerDetails(Organization $organization): void
        {
            $this->inner->syncCustomerDetails($organization);
        }
    };
    $this->app->singleton(StripeGatewayInterface::class, fn (): StripeGatewayInterface => $racing);

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ]);

    // 500 に落ちず、先着行の checkout_url へ収束する (re-read replay)。
    $response->assertStatus(302);
    $response->assertRedirect('https://checkout.stripe.test/c/pay/cs_test_winner');

    expect(BillingCheckoutSession::query()
        ->where('organization_id', $organization->id)
        ->where('intent', CheckoutIntent::SubscriptionStart->value)
        ->count())->toBe(1);
});

test('並行 race: 先着行が stale pending なら replay せず checkout_retry_required を flash する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    $racing = new class($organization) implements StripeGatewayInterface
    {
        public FakeStripeGateway $inner;

        public function __construct(private readonly Organization $organization)
        {
            $this->inner = new FakeStripeGateway;
        }

        public function createSubscriptionCheckout(
            Organization $organization,
            string $stripePriceId,
            string $successUrl,
            string $cancelUrl,
            array $metadata,
            string $idempotencyKey,
        ): CreatedCheckoutSession {
            $created = $this->inner->createSubscriptionCheckout(
                $organization, $stripePriceId, $successUrl, $cancelUrl, $metadata, $idempotencyKey,
            );

            $token = (string) str_replace('sub_start:', '', $idempotencyKey);
            BillingCheckoutSession::factory()
                ->for($this->organization)
                ->withAttempt($token, 'standard')
                ->stale()
                ->create([
                    'stripe_session_id' => 'cs_test_stale_'.$token,
                    'idempotency_key' => 'sub_start:stale:'.$token,
                ]);

            return $created;
        }

        public function swapSubscriptionPrices(
            Organization $organization,
            string $basePriceId,
            string $idempotencyKey,
        ): SubscriptionSwapOutcome {
            return $this->inner->swapSubscriptionPrices($organization, $basePriceId, $idempotencyKey);
        }

        public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
        {
            return $this->inner->retrieveSubscriptionState($stripeSubscriptionId);
        }

        public function expireCheckoutSession(string $stripeSessionId): string
        {
            return $this->inner->expireCheckoutSession($stripeSessionId);
        }

        public function createPortalSession(
            Organization $organization,
            string $returnUrl,
        ): ExternalBillingRedirect {
            return $this->inner->createPortalSession($organization, $returnUrl);
        }

        public function syncCustomerDetails(Organization $organization): void
        {
            $this->inner->syncCustomerDetails($organization);
        }
    };
    $this->app->singleton(StripeGatewayInterface::class, fn (): StripeGatewayInterface => $racing);

    $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'standard',
            'subscription_attempt_token' => $token,
        ])
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::CheckoutRetryRequired->value);
});

test('attempt_token 以外の unique 違反 (stripe_session_id) は rethrow する (replay へ流さない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = subAttemptToken();

    // fake は idempotency key から session id を決定するため、同じ session id を持つ別 attempt の
    // 行を先に置いておくと stripe_session_id の UNIQUE に当たる (attempt_token 制約ではない)。
    $conflictingSessionId = 'cs_test_'.substr(hash('sha256', 'sub_start:'.$token), 0, 32);
    BillingCheckoutSession::factory()
        ->for($organization)
        ->withAttempt(subAttemptToken(), 'starter')
        ->create(['stripe_session_id' => $conflictingSessionId]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $token,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('subscription_attempt_token の欠落 / 非 ULID は 422', function (mixed $token): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $payload = ['plan_code' => 'standard'];
    if ($token !== null) {
        $payload['subscription_attempt_token'] = $token;
    }

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/billing/plans")
        ->post("/organizations/{$organization->slug}/billing/checkout", $payload)
        ->assertInvalid(['subscription_attempt_token']);
})->with([
    'missing' => [null],
    'not-ulid' => ['not-a-ulid'],
    'empty' => [''],
]);
