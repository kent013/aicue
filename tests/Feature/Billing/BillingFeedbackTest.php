<?php

declare(strict_types=1);

use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Organization;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * P9: /billing 着地の one-shot フィードバック。
 *
 * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
 * 唯一の経路**がこれ。UI は raw query を見ず DTO のみを描画する。
 * session_id は org スコープ relation 経由でのみ引き、intent 検証で fail-closed にする。
 */

test('自 org の completed / pending は対応する kind を返し、failed / expired は null', function (string $state, ?string $kind): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $factory = BillingCheckoutSession::factory()->for($organization);
    $stated = match ($state) {
        'completed' => $factory->completed(),
        'pending' => $factory,
        'failed' => $factory->failed(),
        default => $factory->expired(),
    };
    $session = $stated->create();

    $this->actingAs($owner)
        ->get('/billing?session_id='.$session->stripe_session_id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $kind === null
            ? $page->where('page.feedback', null)
            : $page->where('page.feedback.kind', $kind));
})->with([
    'completed' => ['completed', 'purchase_received'],
    'pending' => ['pending', 'purchase_processing'],
    'failed' => ['failed', null],
    'expired' => ['expired', null],
]);

test('他 org / 未知 / intent=setup_payment_method の session_id は feedback を出さない', function (string $case): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$foreign] = createOrganizationWithOwner('他組織');

    $sessionId = match ($case) {
        'foreign' => BillingCheckoutSession::factory()->for($foreign)->completed()->create()->stripe_session_id,
        'setup' => BillingCheckoutSession::factory()->for($organization)->setupPaymentMethod()->completed()
            ->create()->stripe_session_id,
        default => 'cs_unknown_session',
    };

    $this->actingAs($owner)
        ->get('/billing?session_id='.$sessionId)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
})->with(['他 org' => ['foreign'], '未知' => ['unknown'], 'P8a の setup 行' => ['setup']]);

test('?portal は portal_returned を返すが、error flash がある着地では null (成功偽装の抑止)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get('/billing?portal=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'portal_returned'));

    $this->actingAs($owner)
        ->withSession(['error' => 'お支払い管理画面は有償プラン契約後にご利用いただけます。'])
        ->get('/billing?portal=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
});

test('?replayed / ?retry は中立文言の kind を返す', function (string $query, string $kind): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get('/billing?'.$query.'=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', $kind));
})->with([
    'replayed' => ['replayed', 'purchase_already_received'],
    'retry' => ['retry', 'checkout_retry_required'],
]);

test('query の無い着地では feedback=null (one-shot: リロードで消える)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = BillingCheckoutSession::factory()->for($organization)->completed()->create();

    $this->actingAs($owner)
        ->get('/billing?session_id='.$session->stripe_session_id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'purchase_received'));

    // canonical URL への再訪 (= リロード相当) では feedback が消える
    $this->actingAs($owner)
        ->get('/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
});

test('C-2 との結合: Expired 行が遅延 completed で Completed になった後の着地は purchase_received', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_feedback_1';
    $organization->save();

    $session = BillingCheckoutSession::factory()->for($organization)->expired()->create([
        'stripe_session_id' => 'cs_feedback_1',
        'plan_code' => 'standard',
    ]);

    event(new WebhookReceived(feedbackCompletedPayload($organization)));
    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);

    $this->actingAs($owner)
        ->get('/billing?session_id=cs_feedback_1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'purchase_received'));
});

/**
 * @return array<string, mixed>
 */
function feedbackCompletedPayload(Organization $organization): array
{
    return [
        'id' => 'evt_feedback_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_feedback_1',
            'mode' => 'subscription',
            'customer' => 'cus_feedback_1',
            'payment_status' => 'paid',
            'metadata' => [
                'purpose' => 'subscription_start',
                'org_ref' => (string) $organization->id,
                'plan_code' => 'standard',
            ],
        ]],
    ];
}
