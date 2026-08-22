<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Services\Billing\BillingPermissionService;
use Illuminate\Support\Facades\Gate;

/*
 * BillingPermissionService: Owner/Admin の既定境界の外にいる一般メンバーへ
 * `manage-billing` を個別付与できること、および OrganizationPolicy::manageBilling が
 * 直接付与を OR で認めることを固定する (付与 UI / route は本フェーズのスコープ外)。
 */

function billingPermissionService(): BillingPermissionService
{
    return app(BillingPermissionService::class);
}

test('一般メンバーへ付与すると hasDirectPermission が true になり policy も許可する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $service = billingPermissionService();

    expect($service->hasDirectPermission($member, $organization))->toBeFalse();
    expect(Gate::forUser($member)->allows('manageBilling', $organization))->toBeFalse();

    $service->grant($member, $organization);

    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeTrue();
    expect(Gate::forUser($member->fresh())->allows('manageBilling', $organization))->toBeTrue();
});

test('revoke で直接付与を剥奪でき、policy も再び拒否する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $service = billingPermissionService();

    $service->grant($member, $organization);
    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeTrue();

    $service->revoke($member, $organization);

    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeFalse();
    expect(Gate::forUser($member->fresh())->allows('manageBilling', $organization))->toBeFalse();
});

test('非メンバーへの付与は DomainException', function (): void {
    [$organization] = createOrganizationWithOwner();
    $stranger = User::factory()->create();

    billingPermissionService()->grant($stranger, $organization);
})->throws(DomainException::class);

test('hasDirectPermission は非メンバー (退会後の残存) を安全側 false にする', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $service = billingPermissionService();
    $service->grant($member, $organization);

    // 退会 (membership 剥奪) 後は permission 行が残っていても false
    $organization->users()->detach($member->id);

    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeFalse();
    expect(Gate::forUser($member->fresh())->allows('manageBilling', $organization))->toBeFalse();
});

test('直接付与ゼロなら manageBilling の結論は現行 (owner / admin のみ) と同一', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $member = attachOrganizationMember($organization);
    $service = billingPermissionService();

    expect(Gate::forUser($owner)->allows('manageBilling', $organization))->toBeTrue();
    expect(Gate::forUser($admin)->allows('manageBilling', $organization))->toBeTrue();
    expect(Gate::forUser($member)->allows('manageBilling', $organization))->toBeFalse();
    // 既定境界はロール由来であり「直接付与」ではない
    expect($service->hasDirectPermission($owner, $organization))->toBeFalse();
    expect($service->hasDirectPermission($admin, $organization))->toBeFalse();
});

test('直接付与された一般メンバーは /billing/portal が 403 にならない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    billingPermissionService()->grant($member, $organization);

    $fresh = $member->fresh();

    // Gate 境界の検証 (Stripe は叩かない)。付与前は 403 になる route。
    expect(Gate::forUser($fresh)->allows('manageBilling', $organization))->toBeTrue();
});

test('getDirectManageBillingMap は指定メンバーの直接付与状態を 1 マップで返す', function (): void {
    [$organization] = createOrganizationWithOwner();
    $granted = attachOrganizationMember($organization);
    $plain = attachOrganizationMember($organization);
    $service = billingPermissionService();

    $service->grant($granted, $organization);

    $map = $service->getDirectManageBillingMap($organization, [$granted->id, $plain->id]);

    expect($map)->toBe([
        $granted->id => true,
        $plain->id => false,
    ]);
});

test('getDirectManageBillingMap は空配列を渡すと空を返す (クエリを撃たない)', function (): void {
    [$organization] = createOrganizationWithOwner();

    expect(billingPermissionService()->getDirectManageBillingMap($organization, []))->toBe([]);
});
