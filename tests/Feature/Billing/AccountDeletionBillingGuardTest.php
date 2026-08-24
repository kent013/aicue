<?php

declare(strict_types=1);

use App\DataTransferObjects\Organizations\AccountDeletionBlockerDto;
use App\Enums\AccountDeletionBlockerAction;
use App\Enums\AccountDeletionBlockReason;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\AccountDeletionBillingGuard;
use Illuminate\Support\Collection;

/*
 * 退会ガードの「生きた課金責務」判定 (AccountDeletionBillingGuard)。
 *
 * これは entitlement (利用可否) の判定ではなく「将来の請求を発生させうる subscription が
 * 残っているか」の判定。ends_at 付き (期末解約予約済み) を **通す** ことが要点で、
 * ここを塞ぐと「解約したのに退会できない」最長 1 課金周期の詰みが出る。
 */

/** ends_at (Cashier 列) は $fillable 外のため forceFill で明示代入する */
function markSubscriptionEndsAt(Subscription $subscription): void
{
    $subscription->forceFill(['ends_at' => now()->addDays(10)])->save();
}

test('active + ends_at なしは生きた課金責務ありと判定する', function (): void {
    [$organization] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');

    expect(app(AccountDeletionBillingGuard::class)->hasLiveBillingObligation($organization))->toBeTrue();
});

test('trialing + ends_at なしは生きた課金責務ありと判定する', function (): void {
    [$organization] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'trialing');

    expect(app(AccountDeletionBillingGuard::class)->hasLiveBillingObligation($organization))->toBeTrue();
});

test('past_due + ends_at なしは生きた課金責務ありと判定する (回復余地あり = 請求は続く)', function (): void {
    [$organization] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'past_due');

    expect(app(AccountDeletionBillingGuard::class)->hasLiveBillingObligation($organization))->toBeTrue();
});

test('paused は生きた課金責務なしと判定する', function (): void {
    [$organization] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'paused');

    expect(app(AccountDeletionBillingGuard::class)->hasLiveBillingObligation($organization))->toBeFalse();
});

test('canceled / unpaid / incomplete は生きた課金責務なしと判定する', function (string $status): void {
    [$organization] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: $status);

    expect(app(AccountDeletionBillingGuard::class)->hasLiveBillingObligation($organization))->toBeFalse();
})->with(['canceled', 'unpaid', 'incomplete', 'incomplete_expired']);

test('active でも ends_at が入っていれば (期末解約予約済み) 課金責務なしと判定する', function (): void {
    [$organization] = createOrganizationWithOwner();
    markSubscriptionEndsAt(createFakeSubscription($organization, status: 'active'));

    expect(app(AccountDeletionBillingGuard::class)->hasLiveBillingObligation($organization))->toBeFalse();
});

test('subscription 行が無い組織 (無料枠) は課金責務なしと判定する', function (): void {
    [$organization] = createOrganizationWithOwner();

    expect(app(AccountDeletionBillingGuard::class)->hasLiveBillingObligation($organization))->toBeFalse();
});

test('orphanBillingOrganizationIds は渡された組織のうち課金中の id だけを返す', function (): void {
    // 入力契約: Owner 不在の判定は呼び出し側の責務。guard は渡された集合を課金でフィルタするだけ。
    [$billing] = createOrganizationWithOwner();
    createFakeSubscription($billing, status: 'active');
    [$reserved] = createOrganizationWithOwner();
    markSubscriptionEndsAt(createFakeSubscription($reserved, status: 'active'));
    [$free] = createOrganizationWithOwner();

    /** @var Collection<int, Organization> $input */
    $input = collect([$billing, $reserved, $free]);

    expect(app(AccountDeletionBillingGuard::class)->orphanBillingOrganizationIds($input))
        ->toBe([$billing->id]);
});

test('AccountDeletionBlockerDto::build は理由から action を順序固定・重複なしで導出する', function (): void {
    [$organization] = createOrganizationWithOwner();

    $both = AccountDeletionBlockerDto::build($organization, [
        AccountDeletionBlockReason::ActiveBilling,
        AccountDeletionBlockReason::OwnerlessMembers,
    ]);
    // 出力順は TransferOwnership → billing 系で固定 (入力順に依らない)
    expect($both->actions)->toBe([
        AccountDeletionBlockerAction::TransferOwnership,
        AccountDeletionBlockerAction::OpenBilling,
    ]);
    expect($both->toArray())->toBe([
        'name' => $organization->name,
        'slug' => $organization->slug,
        'actions' => ['transfer_ownership', 'open_billing'],
    ]);

    // 組織文脈は URL だけで決まるので、どの組織でも「その組織の課金画面へ行く」1 手に倒れる
    // (切替を挟む一手は保持列・切替 endpoint の撤去とともに概念ごと消えた)
    $other = AccountDeletionBlockerDto::build($organization, [
        AccountDeletionBlockReason::ActiveBilling,
    ]);
    expect($other->actions)->toBe([AccountDeletionBlockerAction::OpenBilling]);

    // 同じ理由を重複して渡しても action は重複しない
    $duplicated = AccountDeletionBlockerDto::build($organization, [
        AccountDeletionBlockReason::OwnerlessMembers,
        AccountDeletionBlockReason::OwnerlessMembers,
        AccountDeletionBlockReason::ActiveBilling,
        AccountDeletionBlockReason::ActiveBilling,
    ]);
    expect($duplicated->actions)->toBe([
        AccountDeletionBlockerAction::TransferOwnership,
        AccountDeletionBlockerAction::OpenBilling,
    ]);
});

test('AccountDeletionBlockerDto::requirementLabel は理由集合ごとの短文を返す', function (): void {
    [$organization] = createOrganizationWithOwner('現場A');

    expect(AccountDeletionBlockerDto::build($organization, [
        AccountDeletionBlockReason::OwnerlessMembers,
    ])->requirementLabel())->toBe('「現場A」オーナーの移譲');

    expect(AccountDeletionBlockerDto::build($organization, [
        AccountDeletionBlockReason::ActiveBilling,
    ])->requirementLabel())->toBe('「現場A」サブスクリプションの解約');

    expect(AccountDeletionBlockerDto::build($organization, [
        AccountDeletionBlockReason::OwnerlessMembers,
        AccountDeletionBlockReason::ActiveBilling,
    ])->requirementLabel())
        ->toBe('「現場A」オーナーの移譲とサブスクリプションの解約');
});
