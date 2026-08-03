<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\Billing\AutoRechargeDisabledReason;
use App\Enums\Billing\BillingNotificationType;
use App\Models\Billing\BillingNotification;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketVolumePrice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAutoRechargeGateway;

/*
 * P8a: オートリチャージ中核サービス。**opt-in・既定 off** が最上位の回帰点。
 */

/** @return array{Organization, User, FakeAutoRechargeGateway, AutoRechargeService} */
function autoRechargeSetup(): array
{
    [$organization, $owner] = createOrganizationWithOwner();

    $gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $gateway);

    return [$organization, $owner, $gateway, app(AutoRechargeService::class)];
}

/** 指定種別の請求通知が台帳に記録されたか。 */
function billingNotificationExists(Organization $organization, BillingNotificationType $type): bool
{
    return BillingNotification::query()
        ->where('organization_id', $organization->getKey())
        ->where('type', $type->value)
        ->exists();
}

/** 与信残高を作る (無期限の purchased 付与)。 */
function grantTickets(Organization $organization, int $amount): void
{
    app(TicketLedgerService::class)->grant($organization, $amount, 'テスト付与');
}

test('既定は off — 設定行が無い組織では isEnabledFor / settingsFor.enabled が false で attempt も起票されない', function (): void {
    [$organization, , , $service] = autoRechargeSetup();

    expect($service->isEnabledFor($organization))->toBeFalse();

    $settings = $service->settingsFor($organization, canManage: true);
    expect($settings->enabled)->toBeFalse()
        ->and($settings->hasPaymentMethod)->toBeFalse()
        ->and($settings->pendingAutoEnable)->toBeFalse()
        ->and($settings->requiresReconsent)->toBeFalse()
        // 既定値は config 由来 (設定行が無くてもフォーム初期値が出る)
        ->and($settings->thresholdCount)->toBe(config()->integer('billing.auto_recharge.default_threshold'))
        ->and($settings->maxCount)->toBe(config()->integer('billing.auto_recharge.default_max'));

    // 残高 0 (= 閾値割れ) でも設定行が無ければ起票しない
    expect($service->maybeCreateAttempt($organization))->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
});

test('有効化は fail-closed — default PM が無ければ ValidationException', function (): void {
    [$organization, $owner, , $service] = autoRechargeSetup();

    expect(fn () => $service->updateSettings(
        $organization,
        $owner,
        enabled: true,
        threshold: 5,
        max: 50,
        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
    ))->toThrow(ValidationException::class);

    expect(TicketAutoRecharge::query()->count())->toBe(0);
});

test('有効化は fail-closed — 同意 version 不一致は ValidationException', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();

    expect(fn () => $service->updateSettings(
        $organization,
        $owner,
        enabled: true,
        threshold: 5,
        max: 50,
        consent: new AutoRechargeConsentDto('v0-obsolete'),
    ))->toThrow(ValidationException::class);
});

test('同意金額はサーバ再計算される (client の申告値を信用しない)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();

    $config = $service->updateSettings(
        $organization,
        $owner,
        enabled: true,
        threshold: 5,
        max: 50,
        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
    );

    $expected = TicketVolumePrice::currentTierFor(50)->unitAmount * 50;
    expect($config->enabled)->toBeTrue()
        ->and($config->consented_max_count)->toBe(50)
        ->and($config->consented_max_amount)->toBe($expected)
        ->and($config->stripe_payment_method_id)->toBe('pm_test_default');
});

test('再同意は 4 箇所で同一判定 — 価格改定で表示・起票・自動有効化が同時に止まる', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();

    $service->updateSettings(
        $organization,
        $owner,
        enabled: true,
        threshold: 5,
        max: 50,
        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
    );

    // 価格改定 (単価を引き上げ) → 現行最大請求額が同意額を超過する
    TicketVolumePrice::query()->where('is_current', true)->update(['unit_amount' => 200]);

    // (1) UI 表示
    expect($service->settingsFor($organization, canManage: true)->requiresReconsent)->toBeTrue();

    // (2) attempt 起票停止 (残高 0 = 閾値割れでも起票しない)
    expect($service->maybeCreateAttempt($organization))->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);

    // (3) 自動有効化の適格性 (停止 → 事前同意待ち相当に落として検査)
    TicketAutoRecharge::query()->where('organization_id', $organization->id)->update([
        'enabled' => false,
        'disabled_reason' => null,
        'stripe_payment_method_id' => null,
    ]);
    expect($service->isAutoEnablePending($organization))->toBeFalse();
});

test('quantity は attempt 作成時に一度だけ確定する (以降の残高変動で変わらない)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    grantTickets($organization, 2); // 残高 2 (< 閾値 5)

    $attempt = $service->maybeCreateAttempt($organization);
    expect($attempt)->not->toBeNull();
    expect($attempt->quantity)->toBe(48); // max 50 − 真値残高 2

    // 起票後に残高が増えても attempt.quantity は不変
    grantTickets($organization, 30);
    $attempt->refresh();
    expect($attempt->quantity)->toBe(48);
});

test('閾値判定は availableTrueBalance を使う — 返金債務で clamp された表示残高では判定しない', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    // monthly 10 枚 (閾値 5 以上) → 真値残高 10 なので起票しない
    app(TicketLedgerService::class)->grantMonthly($organization, 10, null, 'monthly:test', '月次');
    expect($service->maybeCreateAttempt($organization))->toBeNull();
});

test('org に pending attempt があるうちは新しい attempt を起票しない', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    expect($service->maybeCreateAttempt($organization))->not->toBeNull();
    expect($service->maybeCreateAttempt($organization))->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
});

test('停止後課金の禁止 — 停止で pending attempt が invoice 終端 + canceled になり以降 execute は no-op', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    expect($attempt)->not->toBeNull();
    // invoice を作らせておく (終端対象を実在させる)
    $attempt->forceFill(['stripe_invoice_id' => 'in_test_pending'])->save();
    $gateway->invoiceStatuses['in_test_pending'] = 'open';

    $service->updateSettings($organization, $owner, false, 5, 50, null);

    $attempt->refresh();
    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Canceled)
        ->and($gateway->terminated)->toContain('in_test_pending');

    // 停止後の execute は課金しない
    $payCallsBefore = count($gateway->payCalls);
    $service->executeAttempt($attempt);
    expect($gateway->payCalls)->toHaveCount($payCallsBefore);
});

test('連続失敗で自動無効化される (max_failures 到達で disabled_reason=payment_failures + 通知)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $maxFailures = config()->integer('billing.auto_recharge.max_failures');

    for ($i = 0; $i < $maxFailures; $i++) {
        $attempt = $service->maybeCreateAttempt($organization);
        expect($attempt)->not->toBeNull();
        $invoiceId = "in_fail_{$i}";
        $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->save();
        $gateway->invoiceStatuses[$invoiceId] = 'open';

        $service->handleChargeFailure($organization, $attempt, 'card_declined', requiresAction: false);

        $attempt->refresh();
        expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Failed);

        if ($i < $maxFailures - 1) {
            // 自動停止前は再有効化せずとも次の attempt が起票できる
            TicketAutoRecharge::query()->where('organization_id', $organization->id)->update(['enabled' => true]);
        }
    }

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->enabled)->toBeFalse()
        ->and($config->failure_count)->toBe($maxFailures)
        ->and($config->disabled_reason)->toBe(AutoRechargeDisabledReason::PaymentFailures);

    expect(
        billingNotificationExists($organization, BillingNotificationType::AutoRechargeDisabled),
    )->toBeTrue();
});

test('SCA は終端させない — pending 維持 + failure_count 据え置き + 認証要求通知', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    $attempt->forceFill(['stripe_invoice_id' => 'in_sca'])->save();
    $gateway->invoiceStatuses['in_sca'] = 'open';

    $service->handleChargeFailure($organization, $attempt, 'authentication_required', requiresAction: true);

    $attempt->refresh();
    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Pending)
        ->and($attempt->failure_code)->toBe('authentication_required')
        ->and($gateway->terminated)->toBe([]);

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($config->failure_count)->toBe(0);

    expect(
        billingNotificationExists($organization, BillingNotificationType::AutoRechargeActionRequired),
    )->toBeTrue();
});

test('invoice 終端に失敗したら pending を維持する (終端保証を破らない)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    $attempt->forceFill(['stripe_invoice_id' => 'in_stuck'])->save();
    $gateway->failOnTerminate = true;

    $service->terminateAndFail($organization, $attempt);

    $attempt->refresh();
    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Pending);
});

test('execute は invoice_id を pay より先に永続化し、成功でチケットを冪等付与する', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    $expectedAmount = $attempt->unit_amount * $attempt->quantity;
    $gateway->payAmountPaid = $expectedAmount;

    $service->executeAttempt($attempt);

    $attempt->refresh();
    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Paid)
        ->and($attempt->stripe_invoice_id)->not->toBeNull()
        // invoice 作成 → 保存 → pay の順序 (同一 invoice に対して pay が呼ばれている)
        ->and($gateway->payCalls[0]['invoiceId'])->toBe($attempt->stripe_invoice_id);

    $entry = TicketLedgerEntry::query()
        ->where('idempotency_key', "recharge:{$attempt->stripe_invoice_id}")
        ->firstOrFail();
    expect($entry->delta)->toBe($attempt->quantity)
        ->and($entry->purchase_amount)->toBe($expectedAmount)
        ->and($entry->payment_intent_id)->toBe('pi_test_autorecharge');
});

test('amount cross-check は fail-closed — amount_due 不一致で例外・付与なし', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);

    expect(fn () => $service->recordSuccessfulCharge(
        $organization,
        $attempt,
        'in_mismatch',
        amountPaid: 1,
        amountDue: 1, // pin した unit × quantity と一致しない
        paymentIntentId: null,
    ))->toThrow(RuntimeException::class);

    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_mismatch')->exists())->toBeFalse();
});

test('credit balance 適用 (amount_paid < amount_due) は正当 — 付与は成立し purchase_amount は実回収額', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    $due = $attempt->unit_amount * $attempt->quantity;

    $service->recordSuccessfulCharge($organization, $attempt, 'in_credit', amountPaid: 0, amountDue: $due, paymentIntentId: null);

    $entry = TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_credit')->firstOrFail();
    expect($entry->purchase_amount)->toBe(0)
        ->and($entry->delta)->toBe($attempt->quantity);
});

test('同一 invoice の付与は 1 回だけ (webhook 再送・リコンサイル重複でも二重付与しない)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    $due = $attempt->unit_amount * $attempt->quantity;

    $service->recordSuccessfulCharge($organization, $attempt, 'in_dup', $due, $due, 'pi_1');
    $service->recordSuccessfulCharge($organization, $attempt->fresh(), 'in_dup', $due, $due, 'pi_1');

    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_dup')->count())->toBe(1);
});

test('payment_intent_id は null → 値の単調 backfill のみ行う (値の上書きはしない)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    $due = $attempt->unit_amount * $attempt->quantity;
    $ledger = app(TicketLedgerService::class);

    // 1 回目: PI 欠落
    $ledger->grantAutoRecharge($organization, $attempt->quantity, 'in_backfill', $due, null);
    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_backfill')->firstOrFail()->payment_intent_id)->toBeNull();

    // 2 回目: PI つきの再送 → backfill される
    $ledger->grantAutoRecharge($organization, $attempt->quantity, 'in_backfill', $due, 'pi_late');
    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_backfill')->firstOrFail()->payment_intent_id)->toBe('pi_late');

    // 3 回目: 別 PI での上書きは起きない (改竄防止)
    $ledger->grantAutoRecharge($organization, $attempt->quantity, 'in_backfill', $due, 'pi_other');
    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_backfill')->firstOrFail()->payment_intent_id)->toBe('pi_late');

    // 付与行は 1 行のまま
    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_backfill')->count())->toBe(1);
});

test('card_declined の同期 pay 失敗は invoice を終端して failed にする', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    $gateway->payResult = OffSessionChargeResultDto::failed('placeholder', 'card_declined', 'generic_decline');

    $service->executeAttempt($attempt);

    $attempt->refresh();
    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Failed)
        ->and($attempt->failure_code)->toBe('card_declined')
        ->and($gateway->terminated)->toHaveCount(1);
});

test('部分補充でも 1 回の請求額が同意上限を超えない (適用単価は同意した Max 枚の tier で pin する)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
    $consentedAmount = $config->consented_max_amount;
    expect($consentedAmount)->not->toBeNull();

    // 逐減単価表は「数量が少ないほど単価が高い」ため、総額は数量に対して単調ではない。
    // この前提が崩れるとテストが空振りするため fixture 側で明示的に pin する。
    expect(TicketVolumePrice::currentTierFor(48)->unitAmount * 48)
        ->toBeGreaterThan($consentedAmount);

    grantTickets($organization, 2); // 真値残高 2 (< 閾値 5) → quantity 48 (< max 50)

    $attempt = $service->maybeCreateAttempt($organization);
    expect($attempt)->not->toBeNull();
    expect($attempt->quantity)->toBe(48)
        // 適用単価は quantity ではなく同意した max_count の tier (UI 表示の「1 枚あたり」と一致)
        ->and($attempt->unit_amount)->toBe(TicketVolumePrice::currentTierFor(50)->unitAmount)
        ->and($attempt->stripe_price_id)->toBe(TicketVolumePrice::currentTierFor(50)->stripePriceId);

    // hard invariant: 実請求額 (attempt に pin した単価 × 数量) は同意上限を超えない
    expect($attempt->unit_amount * $attempt->quantity)->toBeLessThanOrEqual($consentedAmount);
});
