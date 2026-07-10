<?php

declare(strict_types=1);

use App\Enums\QuotaKey;
use App\Exceptions\Billing\QuotaExceededException;
use App\Models\Project;
use App\Services\Billing\QuotaService;

/*
 * QuotaService::checkAddition (施策2): 加算量つき上限チェック (容量セマンティクス)。
 * check() の「current >= limit で拒否」に対し「current + addition > limit で拒否」。
 */

test('加算後合計が上限以下なら通る (境界: current + addition == limit は許可)', function (): void {
    [$organization] = createOrganizationWithOwner();
    config()->set('quota.plans.free.max_storage_bytes', 1_000);

    app(QuotaService::class)->checkAddition($organization, QuotaKey::MaxStorageBytes, current: 400, addition: 600);
})->throwsNoExceptions();

test('加算後合計が上限を超えると QuotaExceededException', function (): void {
    [$organization] = createOrganizationWithOwner();
    config()->set('quota.plans.free.max_storage_bytes', 1_000);

    app(QuotaService::class)->checkAddition($organization, QuotaKey::MaxStorageBytes, current: 400, addition: 601);
})->throws(QuotaExceededException::class);

test('limits に key が無ければ無制限として通る', function (): void {
    [$organization] = createOrganizationWithOwner();
    config()->set('quota.plans.free', ['max_projects' => 1, 'max_members' => 3]);

    app(QuotaService::class)->checkAddition($organization, QuotaKey::MaxStorageBytes, current: PHP_INT_MAX - 1, addition: 1);
})->throwsNoExceptions();

test('organization_quotas の override が checkAddition にも反映される', function (): void {
    [$organization] = createOrganizationWithOwner();
    config()->set('quota.plans.free.max_storage_bytes', 1_000);
    $organization->quota()->create(['limits' => ['max_storage_bytes' => 2_000]]);
    $organization = $organization->fresh();
    assert($organization !== null);

    app(QuotaService::class)->checkAddition($organization, QuotaKey::MaxStorageBytes, current: 1_500, addition: 400);
})->throwsNoExceptions();

test('int overflow (current + addition が PHP_INT_MAX 超) は超過として拒否する', function (): void {
    [$organization] = createOrganizationWithOwner();
    config()->set('quota.plans.free.max_storage_bytes', 1_000);

    app(QuotaService::class)->checkAddition($organization, QuotaKey::MaxStorageBytes, current: PHP_INT_MAX, addition: 1);
})->throws(QuotaExceededException::class);

test('負の current / addition は事前条件違反 (無制限プランでも検査される)', function (): void {
    [$organization] = createOrganizationWithOwner();
    config()->set('quota.plans.free', ['max_projects' => 1]);

    expect(fn () => app(QuotaService::class)->checkAddition($organization, QuotaKey::MaxStorageBytes, current: -1, addition: 0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(QuotaService::class)->checkAddition($organization, QuotaKey::MaxStorageBytes, current: 0, addition: -1))
        ->toThrow(InvalidArgumentException::class);
});

test('web フォーム経路の QuotaExceededException は従来どおり back + error flash (回帰)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create(); // free の max_projects: 1 到達

    $response = $this->actingAs($owner)
        ->from('/projects/create')
        ->post('/projects', ['name' => '2 つ目']);

    $response->assertRedirect('/projects/create');
    $response->assertSessionHas('error');
});
