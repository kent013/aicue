<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Services\ApiKey\ApiKeyPermissionService;
use Illuminate\Support\Facades\Gate;

/*
 * ApiKeyPermissionService: Owner/Admin の既定境界の外にいる一般メンバーへ
 * `manage-api-keys` を個別付与できること、および OrganizationPolicy::manageApiKeys が
 * 直接付与を OR で認めることを固定する。
 */

function apiKeyPermissionService(): ApiKeyPermissionService
{
    return app(ApiKeyPermissionService::class);
}

test('一般メンバーへ付与すると hasDirectPermission が true になり policy も許可する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $service = apiKeyPermissionService();

    expect($service->hasDirectPermission($member, $organization))->toBeFalse();
    expect(Gate::forUser($member)->allows('manageApiKeys', $organization))->toBeFalse();

    $service->grant($member, $organization);

    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeTrue();
    expect(Gate::forUser($member->fresh())->allows('manageApiKeys', $organization))->toBeTrue();
});

test('revoke で直接付与を剥奪でき、policy も再び拒否する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $service = apiKeyPermissionService();

    $service->grant($member, $organization);
    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeTrue();

    $service->revoke($member, $organization);

    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeFalse();
    expect(Gate::forUser($member->fresh())->allows('manageApiKeys', $organization))->toBeFalse();
});

test('非メンバーへの付与は DomainException', function (): void {
    [$organization] = createOrganizationWithOwner();
    $stranger = User::factory()->create();

    apiKeyPermissionService()->grant($stranger, $organization);
})->throws(DomainException::class);

test('hasDirectPermission は非メンバー (退会後の残存) を安全側 false にする', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $service = apiKeyPermissionService();
    $service->grant($member, $organization);

    // 退会 (membership 剥奪) 後は permission 行が残っていても false
    $organization->users()->detach($member->id);

    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeFalse();
});

test('Owner / Admin は直接付与なしでも policy は許可する (hasDirectPermission は false)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $service = apiKeyPermissionService();

    expect(Gate::forUser($owner)->allows('manageApiKeys', $organization))->toBeTrue();
    expect(Gate::forUser($admin)->allows('manageApiKeys', $organization))->toBeTrue();
    // 既定境界はロール由来であり「直接付与」ではない
    expect($service->hasDirectPermission($owner, $organization))->toBeFalse();
    expect($service->hasDirectPermission($admin, $organization))->toBeFalse();
});

test('getDirectMap は指定メンバーの直接付与状態を 1 マップで返す', function (): void {
    [$organization] = createOrganizationWithOwner();
    $granted = attachOrganizationMember($organization);
    $plain = attachOrganizationMember($organization);
    $service = apiKeyPermissionService();

    $service->grant($granted, $organization);

    $map = $service->getDirectMap($organization, [$granted->id, $plain->id]);

    expect($map)->toBe([
        $granted->id => true,
        $plain->id => false,
    ]);
});

test('接続セッション画面の境界 (manageForOrganization) も直接付与メンバーを許可する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    apiKeyPermissionService()->grant($member, $organization);

    // OauthSessionPolicy::manageForOrganization は manageApiKeys へ委譲している
    $this->actingAs($member->fresh())
        ->get("/organizations/{$organization->slug}/api-keys/sessions")
        ->assertOk();
});
