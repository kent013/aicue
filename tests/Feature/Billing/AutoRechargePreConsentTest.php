<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\Enums\Billing\AutoRechargeDisabledReason;
use App\Enums\Billing\BillingNotificationType;
use App\Enums\CheckoutIntent;
use App\Jobs\Billing\SetDefaultPaymentMethodJob;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\BillingNotification;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Billing\TicketVolumePrice;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAutoRechargeGateway;

/*
 * P8a (D29(i)): 事前同意 → カード登録完了による自動有効化 (fail-closed)。
 *
 * recordPreConsent は enabled=false のまま同意証跡だけを記録し、
 * applySetupCompletion が PM snapshot と enabled=true を同一 TX で確定する。
 */

beforeEach(function (): void {
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
    $this->service = app(AutoRechargeService::class);
});

test('consentTermsFor は表示値と記録値の単一計算源 (サーバ再計算)', function (): void {
    $terms = $this->service->consentTermsFor();
    $max = config()->integer('billing.auto_recharge.default_max');
    $tier = TicketVolumePrice::currentTierFor($max);

    expect($terms->thresholdCount)->toBe(config()->integer('billing.auto_recharge.default_threshold'))
        ->and($terms->maxCount)->toBe($max)
        ->and($terms->unitAmountJpy)->toBe($tier->unitAmount)
        ->and($terms->maxAmountJpy)->toBe($tier->unitAmount * $max)
        ->and($terms->consentVersion)->toBe(config()->string('billing.auto_recharge.consent_version'));
});

test('recordPreConsent は enabled=false のまま同意 4 列を記録し pendingAutoEnable=true になる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $config = $this->service->recordPreConsent(
        $organization,
        $owner,
        new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
    );

    $terms = $this->service->consentTermsFor();
    expect($config->enabled)->toBeFalse()
        ->and($config->consented_at)->not->toBeNull()
        ->and($config->consent_version)->toBe($terms->consentVersion)
        ->and($config->consented_max_count)->toBe($terms->maxCount)
        ->and($config->consented_max_amount)->toBe($terms->maxAmountJpy);

    expect($this->service->isAutoEnablePending($organization))->toBeTrue();
    expect($this->service->settingsFor($organization, canManage: true)->pendingAutoEnable)->toBeTrue();
});

test('consent_version が現行版と不一致なら 422 (画面表示と異なる条件で同意記録しない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    expect(fn () => $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto('v0-old')))
        ->toThrow(ValidationException::class);

    expect(TicketAutoRecharge::query()->count())->toBe(0);
});

test('カード登録完了で自動有効化され通知は 1 回だけ送られる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    expect($this->service->applySetupCompletion($organization, 'pm_new_card'))->toBeTrue();

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeTrue()
        ->and($config->stripe_payment_method_id)->toBe('pm_new_card')
        ->and($config->failure_count)->toBe(0);

    // 再送 (同一 webhook の replay) では enabled 遷移が起きないため通知も増えない
    expect($this->service->applySetupCompletion($organization, 'pm_new_card'))->toBeFalse();

    expect(
        BillingNotification::query()
            ->where('organization_id', $organization->id)
            ->where('type', BillingNotificationType::AutoRechargeEnabled->value)
            ->count(),
    )->toBe(1);
});

test('fail-closed 1: 稼働中設定 (enabled=true) は事前同意で上書きされない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $this->gateway->withDefaultPaymentMethod();
    $this->service->updateSettings($organization, $owner, true, 7, 70, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $config = $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    expect($config->enabled)->toBeTrue()
        ->and($config->threshold_count)->toBe(7)
        ->and($config->max_count)->toBe(70);
});

test('fail-closed 2: disabled_reason を持つ行は自動有効化されない (停止の意思を尊重)', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create([
        'disabled_reason' => AutoRechargeDisabledReason::User,
    ]);

    expect($this->service->isAutoEnablePending($organization))->toBeFalse();
    expect($this->service->applySetupCompletion($organization, 'pm_after_stop'))->toBeFalse();

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeFalse()
        // PM snapshot は更新される (次回の手動有効化に使える) が enabled にはしない
        ->and($config->stripe_payment_method_id)->toBe('pm_after_stop')
        ->and($config->disabled_reason)->toBe(AutoRechargeDisabledReason::User);
});

test('fail-closed 3: PM snapshot 済みの行は pendingAutoEnable=false (有効化は請求ページに委ねる)', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRecharge::factory()->for($organization)->preConsented()->create([
        'stripe_payment_method_id' => 'pm_already',
    ]);

    expect($this->service->isAutoEnablePending($organization))->toBeFalse();
    expect($this->service->settingsFor($organization, canManage: true)->pendingAutoEnable)->toBeFalse();
});

test('事前同意なしの手動カード登録は snapshot のみ (勝手に有効化しない)', function (): void {
    [$organization] = createOrganizationWithOwner();

    expect($this->service->applySetupCompletion($organization, 'pm_manual'))->toBeFalse();

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeFalse()
        ->and($config->stripe_payment_method_id)->toBe('pm_manual')
        ->and($config->consented_at)->toBeNull();
});

test('SetDefaultPaymentMethodJob は gateway で default PM を設定してから適用する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    (new SetDefaultPaymentMethodJob($organization->id, 'seti_test'))
        ->handle($this->gateway, $this->service);

    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(1);

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeTrue()
        ->and($config->stripe_payment_method_id)->toBe($this->gateway->defaultPaymentMethodsSet[0]['paymentMethodId']);
});

test('価格改定後は事前同意が失効し自動有効化されない (再同意の 4 箇所一致)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    TicketVolumePrice::query()->where('is_current', true)->update(['unit_amount' => 500]);

    expect($this->service->isAutoEnablePending($organization))->toBeFalse();
    expect($this->service->applySetupCompletion($organization, 'pm_after_price_change'))->toBeFalse();
    expect(TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail()->enabled)->toBeFalse();
});

test('activate-personal の funding_choice 省略時は dashboard 着地のまま (既存挙動が変わらない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', ['declaration' => '1'])
        ->assertRedirect(route('dashboard'));

    expect(TicketAutoRecharge::query()->count())->toBe(0);
});

test('activate-personal + funding_choice=auto_recharge で事前同意を記録し setup Checkout へ送る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);

    $response = $this->actingAs($owner)->post('/onboarding/activate-personal', [
        'declaration' => '1',
        'funding_choice' => 'auto_recharge',
        'consent_version' => config()->string('billing.auto_recharge.consent_version'),
    ]);

    // Inertia::location は 非 Inertia リクエストでは通常の 302 リダイレクトになる
    // (Inertia リクエストでは 409 + X-Inertia-Location)
    $response->assertRedirect($this->gateway->setupUrl);

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeFalse()
        ->and($config->consented_at)->not->toBeNull()
        ->and($config->consented_max_amount)->toBe($this->service->consentTermsFor()->maxAmountJpy);
});

test('activate-personal の consent_version 欠落 / 現行版不一致は 422 (activate 前に fail-closed)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', ['declaration' => '1', 'funding_choice' => 'auto_recharge'])
        ->assertSessionHasErrors('consent_version');

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', [
            'declaration' => '1',
            'funding_choice' => 'auto_recharge',
            'consent_version' => 'v0-old',
        ])
        ->assertSessionHasErrors('consent_version');

    // activate 自体が起きていない (free 有効化も同意記録も無い)
    expect(TicketAutoRecharge::query()->count())->toBe(0);
    expect($organization->fresh()->free_plan_code)->toBeNull();
});

test('二重 submit でも SetupPaymentMethod 台帳が増殖しない (session 保持 token)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);
    $payload = [
        'declaration' => '1',
        'funding_choice' => 'auto_recharge',
        'consent_version' => config()->string('billing.auto_recharge.consent_version'),
    ];

    $this->actingAs($owner)->post('/onboarding/activate-personal', $payload);
    $this->actingAs($owner)->post('/onboarding/activate-personal', $payload);

    expect(
        BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
            ->count(),
    )->toBe(1);
});

test('funding_choice=tickets は購入ページへ直行する (UI 非提示だが永続値互換で受理)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', ['declaration' => '1', 'funding_choice' => 'tickets'])
        ->assertRedirect(route('billing.tickets.show'));
});

test('onboarding checkout の props に consentTerms / fundingChoices が届く (tickets は出さない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);

    $response = $this->actingAs($owner)->get('/onboarding/checkout')->assertOk();
    $pageData = $response->viewData('page')['props']['pageData'];

    expect($pageData['fundingChoices'])->toBe(['auto_recharge', 'later'])
        ->and($pageData['consentTerms'])->toBe($this->service->consentTermsFor()->toArray());
});
