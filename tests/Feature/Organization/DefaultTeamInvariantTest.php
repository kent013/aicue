<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;

/*
 * Default Team パターンの不変条件 (docs/default-team-pattern.md)。
 */

test('provisioning で組織を作ると Default Team がちょうど 1 つ生成される', function (): void {
    $user = User::factory()->create();

    $organization = app(OrganizationProvisioningService::class)->provision($user, 'テスト組織');

    expect($organization->customTeams()->where('is_default', true)->count())->toBe(1);
    expect($organization->defaultTeam()->name)->toBe('テスト組織');
});

test('Factory で組織を作っても Default Team が生成される', function (): void {
    $organization = Organization::factory()->create();

    expect($organization->customTeams()->where('is_default', true)->count())->toBe(1);
});

test('登録経路でも個人組織 + Default Team + Owner ロールが揃う', function (): void {
    $this->post('/register', [
        'name' => '山田 太郎',
        'email' => 'taro@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $user = User::whereBlind('email', 'email_index', 'taro@example.com')->firstOrFail();
    /** @var Organization $organization */
    $organization = $user->organizations()->firstOrFail();

    expect($organization->is_personal)->toBeTrue();
    expect($organization->customTeams()->where('is_default', true)->count())->toBe(1);
    expect($user->organizationRole($organization)?->value)->toBe('organization_owner');
    expect($user->refresh()->current_organization_id)->toBe($organization->id);
});

test('個人組織の provisioning は冪等 (二重生成しない)', function (): void {
    $user = User::factory()->create();
    $service = app(OrganizationProvisioningService::class);

    $first = $service->provisionPersonalOrganization($user);
    $second = $service->provisionPersonalOrganization($user);

    expect($second->id)->toBe($first->id);
    expect($user->organizations()->where('is_personal', true)->count())->toBe(1);
});

test('組織ロール判定は team 明示で行われる (strict_check=true で他組織のロールが漏れない)', function (): void {
    $user = User::factory()->create();
    $service = app(OrganizationProvisioningService::class);
    $orgA = $service->provision($user, '組織A');
    $orgB = Organization::factory()->create();

    expect($user->organizationRole($orgA)?->value)->toBe('organization_owner');
    expect($user->organizationRole($orgB))->toBeNull();
});
