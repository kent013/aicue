<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\Billing\AutoRechargeDisabledReason;
use App\Enums\Billing\BillingNotificationType;
use App\Enums\Billing\GatewayFailureClass;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Security\ExternalCallKind;
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
use App\Support\JobExecution\AttemptOwnershipPreflight;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Tests\Support\Billing\GatewayFailureFixtures;
use Tests\Support\FakeAttemptOwnershipPreflight;
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
    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;

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

/*
 * ─────────────────────────────────────────────────────────────────────
 * T131 / S4: Stripe 呼び出し直前の所有権再検証 (preflight suppression) と
 *            中断時の invoice 終端 (裁定 AG-082)
 *
 * 配置 (placement) は `FakeAttemptOwnershipPreflight` (競合注入シーム) が固定する。
 * シームは **verdict を差し替えない** — checkpoint 直前に attempt 行を terminal 化して
 * `parent::stillPending()` へ委譲するだけなので、refresh / status 判定 /
 * 所有権喪失ログは常に本番実装が実行する。
 * ─────────────────────────────────────────────────────────────────────
 */

/**
 * 抑止ログ (`job_ownership_lost`) の必須キー集合。
 *
 * ★他テストファイルのグローバル定数を参照しない (Pest の --parallel はファイル単位で
 *   プロセスを分けるため未定義になりうる)。Manual 側 (JobOwnershipLostContextTest) と
 *   同じ集合をここにも書き、両者が一致していることを人が読める形で残す。
 *
 * @var list<string>
 */
const AUTO_RECHARGE_OWNERSHIP_LOST_REQUIRED_KEYS = [
    'event',
    'job_type',
    'job_id',
    'expected_status',
    'actual_status',
    'stage',
    'external_call',
];

/**
 * preflight シームを差し込んだ setup (service 解決より前に instance() する)。
 *
 * @return array{Organization, User, FakeAutoRechargeGateway, AutoRechargeService, FakeAttemptOwnershipPreflight}
 */
function autoRechargePreflightSetup(): array
{
    $preflight = new FakeAttemptOwnershipPreflight;
    app()->instance(AttemptOwnershipPreflight::class, $preflight);

    [$organization, $owner, $gateway, $service] = autoRechargeSetup();

    return [$organization, $owner, $gateway, $service, $preflight];
}

/** enabled 設定 + pending attempt を 1 件作る (残高 0 = 閾値割れ)。 */
function autoRechargePendingAttempt(
    Organization $organization,
    User $owner,
    AutoRechargeService $service,
): TicketAutoRechargeAttempt {
    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
        config()->string('billing.auto_recharge.consent_version'),
    ));

    $attempt = $service->maybeCreateAttempt($organization);
    expect($attempt)->not->toBeNull();
    assert($attempt instanceof TicketAutoRechargeAttempt);

    return $attempt;
}

test('配置: create の直前に preflight がある (terminalizeAt=create で invoice を作らない)', function (): void {
    Log::spy();
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoiceCreate];

    $service->executeAttempt($attempt);

    // create checkpoint で止まる = pay checkpoint へ到達しない
    expect($preflight->calls)->toBe([ExternalCallKind::StripeInvoiceCreate->value]);
    expect($gateway->createdInvoices)->toBe([]);
    expect($gateway->payCalls)->toBe([]);
    expect($gateway->terminated)->toBe([]); // 未作成なので終端対象が無い
    expect($attempt->refresh()->stripe_invoice_id)->toBeNull();

    // 所有権喪失ログ: Manual 側と必須 7 キーが一致し、Billing 固有の追加は attempt_ulid のみ
    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($attempt): bool {
            if (($context['event'] ?? null) !== ExternalCallKind::LOG_EVENT) {
                return false;
            }
            $keys = array_keys($context);
            sort($keys);
            $expected = array_merge(AUTO_RECHARGE_OWNERSHIP_LOST_REQUIRED_KEYS, ['attempt_ulid']);
            sort($expected);

            return $keys === $expected
                && $context['job_type'] === TicketAutoRechargeAttempt::class
                && $context['job_id'] === $attempt->id
                && $context['expected_status'] === 'pending'
                && $context['actual_status'] === 'canceled'
                && $context['stage'] === 'execute_attempt'
                && $context['external_call'] === ExternalCallKind::StripeInvoiceCreate->value
                && $context['attempt_ulid'] === $attempt->attempt_ulid;
        })
        ->once();
});

test('配置: pay の直前に preflight がある (terminalizeAt=pay で pay せず invoice を終端する)', function (): void {
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];

    $service->executeAttempt($attempt);

    // preflight 1 は Pending で通過 → create → attach 1 行 → preflight 2 直前に canceled 化
    expect($preflight->calls)->toBe([
        ExternalCallKind::StripeInvoiceCreate->value,
        ExternalCallKind::StripeInvoicePay->value,
    ]);
    expect($gateway->createdInvoices)->toHaveCount(1);
    expect($gateway->payCalls)->toBe([]);

    $attempt->refresh();
    $invoiceId = $attempt->stripe_invoice_id;
    expect($invoiceId)->not->toBeNull(); // attach は成功している (DB に残る)
    // 作成された invoice id で 1 回だけ終端される (Canceled 分岐)
    expect($gateway->terminated)->toBe([$invoiceId]);
    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Canceled);
});

test('後始末: terminalStatus=failed のとき terminateInvoice を呼ばない (二重終端の抑止)', function (): void {
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $preflight->terminalStatus = AutoRechargeAttemptStatus::Failed;

    $service->executeAttempt($attempt);

    // failed へ遷移させた側 (terminateAndFail) が既に終端済みという前提に立つ
    expect($gateway->terminated)->toBe([]);
    expect($gateway->payCalls)->toBe([]);
});

test('後始末: terminalStatus=paid のとき terminateInvoice を呼ばない (void 不可の分類)', function (): void {
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $preflight->terminalStatus = AutoRechargeAttemptStatus::Paid;

    $service->executeAttempt($attempt);

    expect($gateway->terminated)->toBe([]);
    expect($gateway->payCalls)->toBe([]);
});

test('配置: 行が Pending のままなら create → pay が従来どおり進む (回帰)', function (): void {
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $gateway->payAmountPaid = $attempt->unit_amount * $attempt->quantity;

    $service->executeAttempt($attempt);

    // 2 つの checkpoint を**両方**通る
    expect($preflight->calls)->toBe([
        ExternalCallKind::StripeInvoiceCreate->value,
        ExternalCallKind::StripeInvoicePay->value,
    ]);
    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
    expect($gateway->payCalls)->toHaveCount(1);
});

test('preflight 2: terminateInvoice が例外を投げても課金処理へ進まない', function (): void {
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;

    $service->executeAttempt($attempt);

    expect($gateway->payCalls)->toBe([]);
    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::Grant)->count())->toBe(0);
});

test('後始末ログは別 event 名 job_ownership_lost_cleanup を使い独自 schema を持つ', function (): void {
    Log::spy();
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];

    $service->executeAttempt($attempt);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($attempt): bool {
            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
                return false;
            }
            $keys = array_keys($context);
            sort($keys);
            $expected = [
                'attempt_ulid', 'error_class', 'event', 'failure_class',
                'invoice_id', 'job_id', 'job_type', 'terminated',
            ];

            return $keys === $expected
                && $context['terminated'] === true
                // ★成功時も 2 キーは null で存在する (集計 schema を成否で割らない)
                && $context['failure_class'] === null
                && $context['error_class'] === null
                && $context['attempt_ulid'] === $attempt->attempt_ulid;
        })
        ->once();

    // 抑止ログと後始末ログが同じ event 名に混ざらない (同一 event = 同一集計 schema)
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
            && ! array_key_exists('invoice_id', $context))
        ->once();
});

test('後始末のログに外部由来のメッセージを載せない (分類 + 例外クラス名のみ)', function (): void {
    // Stripe SDK の例外メッセージは外部サービスが生成する可変文字列であり、構造化ログの
    // 集計語彙へ流さない。
    Log::spy();
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    // 本物の gateway が伝播させる実ライブラリ例外を投げる (message にマーカーが入る)
    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;

    $service->executeAttempt($attempt);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
                return false;
            }

            return $context['terminated'] === false
                && $context['failure_class'] === 'provider_unavailable'
                && $context['error_class'] === ApiConnectionException::class
                // ★マーカー非含有。gate が「fixture の message にマーカーが確かに入る」ことを
                //   保証しているため、この negative assertion は空虚にならない。
                && ! str_contains(
                    json_encode($context, JSON_THROW_ON_ERROR),
                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
                );
        })
        ->once();
});

test('後始末の例外報告は固定テンプレートと完全一致する (外部由来のメッセージを渡さない)', function (): void {
    // 「構造化ログに載せない」だけでは不十分 — 標準の exception handler は message と
    // スタックトレースを記録するため、原例外をそのまま report() すると保存場所が移るだけになる。
    Exceptions::fake();
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;

    $service->executeAttempt($attempt);

    // ★部分一致をやめ**完全一致**で固定する (予期しない文字列の追加を必ず検出する)。
    //   invoice_id は pay preflight より前に永続化されているため DB から取れる。
    $invoiceId = $attempt->refresh()->stripe_invoice_id;
    expect($invoiceId)->not->toBeNull();
    $expected = sprintf(
        'auto-recharge: invoice %s の終端に失敗しました (%s / %s)',
        $invoiceId,
        'provider_unavailable',
        ApiConnectionException::class,
    );

    Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported->getMessage() === $expected
        // previous chain も繋がない (reporter が previous を出力しうるため)
        && $reported->getPrevious() === null);
    Exceptions::assertReportedCount(1);
});

test('attach 0 行: invoice 作成成功と同時に canceled 化 → invoice_id を書かず invoice を終端する', function (): void {
    // 実 preflight を使う (競合点は gateway の duringCreateInvoice hook が作る)
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $gateway->duringCreateInvoice = function () use ($attempt): void {
        // Stripe 側の作成は成功したが、返る前に停止側が canceled 化した
        TicketAutoRechargeAttempt::query()->whereKey($attempt->id)->update([
            'status' => AutoRechargeAttemptStatus::Canceled->value,
        ]);
    };

    $service->executeAttempt($attempt);

    $attempt->refresh();
    expect($attempt->stripe_invoice_id)->toBeNull();  // DB には書かない
    expect($gateway->createdInvoices)->toHaveCount(1);
    // DB に保存済みであることに依存せず、ローカルの invoice id で終端する
    expect($gateway->terminated)->toHaveCount(1);
    expect($gateway->payCalls)->toBe([]);
});

test('attach 0 行: failed へ遷移していた場合も invoice を終端する (status を問わない)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $gateway->duringCreateInvoice = function () use ($attempt): void {
        TicketAutoRechargeAttempt::query()->whereKey($attempt->id)->update([
            'status' => AutoRechargeAttemptStatus::Failed->value,
        ]);
    };

    $service->executeAttempt($attempt);

    // failed へ遷移させた側は stripe_invoice_id === null を見ているため終端できない。
    // ここで終端しないと「誰も終端しない open invoice」が残る
    expect($gateway->terminated)->toHaveCount(1);
    expect($attempt->refresh()->stripe_invoice_id)->toBeNull();
});

test('前提: Failed へ遷移した attempt は invoice が終端済みである', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $attempt->forceFill(['stripe_invoice_id' => 'in_precondition'])->save();
    $gateway->invoiceStatuses['in_precondition'] = 'open';

    $service->terminateAndFail($organization, $attempt);

    // terminateAndFail は「invoice 終端成功 → failed 遷移」の順序を守る。
    // この前提が崩れると terminateInvoiceAfterOwnershipLost の Canceled 限定が壊れる
    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Failed);
    expect($gateway->terminated)->toBe(['in_precondition']);
});

test('前提: terminateInvoice が失敗したら attempt は Pending のまま (Failed へ遷移しない)', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $attempt->forceFill(['stripe_invoice_id' => 'in_stuck_precondition'])->save();
    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;

    $service->terminateAndFail($organization, $attempt);

    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
});

/** 所有権喪失 → 後始末までを 1 シナリオ実行する (cleanup ログの発生源)。 */
function autoRechargeRunCleanupScenario(?GatewayFailureClass $terminateFailure): void
{
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $gateway->terminateFailure = $terminateFailure;

    $service->executeAttempt($attempt);
}

test('cleanup event のキー集合が成功・失敗の両方で同一である (集計 schema を成否で割らない)', function (): void {
    // ★Log::spy() は既に mock 済みなら再作成しないため、1 本の spy で 2 シナリオを記録する。
    Log::spy();

    autoRechargeRunCleanupScenario(null);                                        // 終端成功
    autoRechargeRunCleanupScenario(GatewayFailureClass::ProviderUnavailable);     // 終端失敗

    $contexts = [];
    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use (&$contexts): bool {
            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
                return false;
            }
            // ★Mockery は照合と件数検証で closure を複数回呼ぶため、成否をキーにして
            //   冪等に記録する (append だと重複して数が合わない)。
            $contexts[$context['terminated'] === true ? 'success' : 'failure'] = $context;

            return true;
        })
        ->twice();

    expect(array_keys($contexts))->toEqualCanonicalizing(['success', 'failure']);
    $success = $contexts['success'];
    $failure = $contexts['failure'];

    expect(array_keys($success))->toBe(array_keys($failure));
    // 成功時も 2 キーは **null で存在**する
    expect($success['terminated'])->toBeTrue()
        ->and($success['failure_class'])->toBeNull()
        ->and($success['error_class'])->toBeNull();
    expect($failure['terminated'])->toBeFalse()
        ->and($failure['failure_class'])->toBe('provider_unavailable')
        ->and($failure['error_class'])->toBe(ApiConnectionException::class);
});

test('制御フロー等価性: 分類ログを出しても収束先と gateway 呼び出し回数が変わらない', function (): void {
    // ★分類は**観測のため**であり課金の振る舞いを変えない。終端失敗時の収束先
    //   (pending 維持) と gateway 呼び出し回数を明示的に固定する。
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;

    $service->executeAttempt($attempt);

    // 所有権喪失で canceled 化済み (preflight が terminal 化させた側の結果)
    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Canceled);
    // 終端は失敗したので terminated 配列は空のまま / 課金 (pay) には進まない
    expect($gateway->terminated)->toBe([]);
    expect($gateway->payCalls)->toBe([]);
    expect($gateway->createdInvoices)->toHaveCount(1);
    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::Grant)->count())->toBe(0);
});

test('停止側の終端失敗ログにも分類が載る (message は載らない)', function (): void {
    // tryTerminateInvoice の catch。制御フローは現行のまま (pending 維持)。
    Log::spy();
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $attempt->forceFill(['stripe_invoice_id' => 'in_try_terminate'])->save();
    $gateway->terminateFailure = GatewayFailureClass::ProviderRejected;

    $service->terminateAndCancel($attempt);

    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            if ($message !== 'auto-recharge: invoice termination failed, keeping attempt pending') {
                return false;
            }

            return $context['failure_class'] === 'provider_rejected'
                && $context['error_class'] === InvalidRequestException::class
                && ! str_contains(
                    json_encode($context, JSON_THROW_ON_ERROR),
                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
                );
        })
        ->once();
});

test('冪等キーは 2 本ある: 同一 invoice の付与は台帳 1 件・attempt 遷移も 1 回', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $due = $attempt->unit_amount * $attempt->quantity;

    // 付与の一回性 = 台帳の recharge:{invoiceId} UNIQUE (invoice 単位)
    // attempt 遷移の一回性 = where status=pending の条件付き UPDATE (attempt 単位)
    $service->recordSuccessfulCharge($organization, $attempt, 'in_two_keys', $due, $due, 'pi_1');
    $resolvedAt = $attempt->refresh()->resolved_at;
    $service->recordSuccessfulCharge($organization, $attempt->fresh(), 'in_two_keys', $due, $due, 'pi_1');

    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_two_keys')->count())->toBe(1);
    $attempt->refresh();
    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Paid);
    // 2 回目は 0 行更新 = resolved_at が動かない
    expect((string) $attempt->resolved_at?->toJSON())->toBe((string) $resolvedAt?->toJSON());
});

test('Stripe idempotency key は操作ごとに異なり attempt_ulid に pin されている', function (): void {
    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $gateway->payAmountPaid = $attempt->unit_amount * $attempt->quantity;

    $service->executeAttempt($attempt);

    // key base は attempt_ulid に pin される (attempt が変われば必ず別キーになる)
    $expectedBase = "auto-recharge:{$attempt->attempt_ulid}";
    expect($gateway->createdInvoices[0]['keyBase'])->toBe($expectedBase);
    expect($gateway->payCalls[0]['keyBase'])->toBe($expectedBase);

    // gateway 実装が組む 4 キーは互いに異なる (同一キーだと Stripe が別操作を replay 扱いする)。
    // Stripe SDK へ到達させずに固定するため、実装ソースの接尾辞集合を検査する。
    $source = file_get_contents(app_path('Services/Billing/CashierAutoRechargeGateway.php'));
    expect($source)->toBeString();
    $suffixes = ['invoice', 'item', 'finalize', 'pay'];
    foreach ($suffixes as $suffix) {
        expect($source)->toContain("{\$idempotencyKeyBase}:{$suffix}");
    }
    expect(count(array_unique($suffixes)))->toBe(4);
});
