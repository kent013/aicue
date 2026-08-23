<?php

declare(strict_types=1);

use App\Enums\Billing\BillingFeedbackKind;
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
 *
 * one-shot (F-3-04) は **着地 query を canonical `/billing` へ 303 で畳み、kind を
 * 1 リクエストだけ生きる session flash で運ぶ**ことで担保する。着地 URL が履歴に
 * 残らないため、リロード・戻る・ブックマークでバナーが復活しない。
 */

/**
 * 「既に 1 リクエスト分歳を取った flash」を再現する session state。
 *
 * `withSession()` の素の put は flash 台帳に載らないため永続してしまい、
 * 「hop で落ちないこと」の検証にならない。`_flash.old` に載せることで
 * **次の save で消える寸前の flash** を作る (= 実際の着地直前と同じ状態)。
 *
 * @return array<string, mixed>
 */
function agedFlashSession(string $key, string $value): array
{
    return [$key => $value, '_flash' => ['old' => [$key], 'new' => []]];
}

test('one-shot 回帰 (F-3-04): 着地 query は 303 で畳まれ、バナーは次の 1 render だけ出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = BillingCheckoutSession::factory()->for($organization)->completed()->create();

    // (1) 着地: canonical URL へ 303。Location に着地 query が残らない。
    $landing = $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing?session_id=".$session->stripe_session_id)
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::PurchaseReceived->value);

    expect($landing->headers->get('Location'))->not->toContain('session_id');

    // (2) 追従先の 1 render だけバナーが出る。
    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.feedback.kind', 'purchase_received')
            ->where('page.feedback.message', 'お支払いを受け付けました。プランへの反映には数分かかる場合があります。')
            ->etc());

    // (3) リロード相当の再 GET では復活しない (= one-shot)。
    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null)->etc());
});

test('自 org の completed / pending は対応する kind を flash する', function (string $state, string $kind): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $factory = BillingCheckoutSession::factory()->for($organization);
    $session = ($state === 'completed' ? $factory->completed() : $factory)->create();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing?session_id=".$session->stripe_session_id)
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionHas(BillingFeedbackKind::FLASH_KEY, $kind);
})->with([
    'completed' => ['completed', 'purchase_received'],
    'pending' => ['pending', 'purchase_processing'],
]);

test('fail-closed: failed / expired / 未知 / 他 org / setup intent は畳むが flash を積まない', function (string $case): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$foreign] = createOrganizationWithOwner('他組織');

    $sessionId = match ($case) {
        'failed' => BillingCheckoutSession::factory()->for($organization)->failed()->create()->stripe_session_id,
        'expired' => BillingCheckoutSession::factory()->for($organization)->expired()->create()->stripe_session_id,
        'foreign' => BillingCheckoutSession::factory()->for($foreign)->completed()->create()->stripe_session_id,
        'setup' => BillingCheckoutSession::factory()->for($organization)->setupPaymentMethod()->completed()
            ->create()->stripe_session_id,
        default => 'cs_unknown_session',
    };

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing?session_id=".$sessionId)
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionMissing(BillingFeedbackKind::FLASH_KEY);
})->with([
    'failed' => ['failed'],
    'expired' => ['expired'],
    '未知' => ['unknown'],
    '他 org' => ['foreign'],
    'P8a の setup 行' => ['setup'],
]);

test('?portal=1 は portal_returned を flash し、追従先でバナーが 1 回だけ出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing?portal=1")
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::PortalReturned->value);

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'portal_returned')->etc());

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null)->etc());
});

test('?portal=1 + error flash では feedback を出さず、error を hop で取りこぼさない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $message = 'お支払い管理画面は有償プラン契約後にご利用いただけます。';

    $this->actingAs($owner)
        ->withSession(agedFlashSession('error', $message))
        ->get("/organizations/{$organization->slug}/billing?portal=1")
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionMissing(BillingFeedbackKind::FLASH_KEY)
        ->assertSessionHas('error', $message);

    // keep() の実効: 追従先の props まで error が届く (= toast が実際に出る)。
    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.error', $message)
            ->where('page.feedback', null)
            ->etc());
});

test('?session_id 着地でも error flash があれば feedback を出さず error を keep する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = BillingCheckoutSession::factory()->for($organization)->completed()->create();
    $message = '既に進行中の Checkout があります。数分お待ちください。';

    // error 抑止は portal 専用ではなく着地 hop 共通の分岐であることを固定する。
    $this->actingAs($owner)
        ->withSession(agedFlashSession('error', $message))
        ->get("/organizations/{$organization->slug}/billing?session_id=".$session->stripe_session_id)
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionMissing(BillingFeedbackKind::FLASH_KEY)
        ->assertSessionHas('error', $message);

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.error', $message)
            ->where('page.feedback', null)
            ->etc());
});

test('着地 query は値が不正でも必ず canonical へ畳まれる', function (string $query, bool $expectsFlash): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = BillingCheckoutSession::factory()->for($organization)->completed()->create();

    $url = "/organizations/{$organization->slug}/billing?".str_replace('{valid}', (string) $session->stripe_session_id, $query);

    $response = $this->actingAs($owner)
        ->get($url)
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing");

    $expectsFlash
        ? $response->assertSessionHas(BillingFeedbackKind::FLASH_KEY)
        : $response->assertSessionMissing(BillingFeedbackKind::FLASH_KEY);
})->with([
    '有効な session_id' => ['session_id={valid}', true],
    '未知の session_id' => ['session_id=cs_unknown', false],
    '空の session_id' => ['session_id=', false],
    '配列の session_id' => ['session_id[]=cs_x', false],
    'portal=1' => ['portal=1', true],
    'portal (値なし)' => ['portal', false],
    'portal=forged' => ['portal=forged', false],
    '配列の portal' => ['portal[]=x', false],
]);

test('highlight は着地 hop を跨いで保持される (副作用のない anchor)', function (string $landingQuery): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = BillingCheckoutSession::factory()->for($organization)->completed()->create();
    $setup = BillingCheckoutSession::factory()->for($organization)->setupPaymentMethod()->completed()->create();

    $query = $landingQuery === 'session_id'
        ? 'session_id='.$session->stripe_session_id
        : 'setup_session_id='.$setup->stripe_session_id;

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing?".$query.'&highlight=auto-recharge')
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing?highlight=auto-recharge");
})->with(['feedback 着地' => ['session_id'], 'カード登録着地' => ['setup_session_id']]);

test('着地の優先順位: ?setup_session_id が ?session_id より先着で相互排他', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $setup = BillingCheckoutSession::factory()->for($organization)->setupPaymentMethod()->completed()->create();
    $checkout = BillingCheckoutSession::factory()->for($organization)->completed()->create();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing?setup_session_id=".$setup->stripe_session_id.'&session_id='.$checkout->stripe_session_id)
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionHas('success')
        ->assertSessionMissing(BillingFeedbackKind::FLASH_KEY);
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
        ->get("/organizations/{$organization->slug}/billing?session_id=cs_feedback_1")
        ->assertStatus(303)
        ->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::PurchaseReceived->value);
});

test('発行側 flash (checkout の replay / stale) も 1 render だけバナーになる', function (string $kind, string $message): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->withSession(agedFlashSession(BillingFeedbackKind::FLASH_KEY, $kind))
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.feedback.kind', $kind)
            ->where('page.feedback.message', $message)
            ->etc());

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null)->etc());
})->with([
    'replay' => ['purchase_already_received', 'この内容のお支払いは既に受け付け済みです。'],
    'stale' => ['checkout_retry_required', 'お手続きの有効期限が切れました。画面を再読み込みして再試行してください。'],
]);

test('未知の flash 値は feedback を出さない (fail-closed)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->withSession(agedFlashSession(BillingFeedbackKind::FLASH_KEY, 'not_a_kind'))
        ->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null)->etc());
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
