<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Services\Organization\OrganizationMembershipService;

/*
 * 課金孤児の検知バッチが使う「Owner が 1 人も居ない組織」列挙 (読み取り専用)。
 * role 照合は必ず team (organizations.laratrust_team_id ⇔ role_user.team_id) を明示する
 * = セキュリティ不変条件「権限判定は常に laratrust_team_id を明示」。
 */

test('Owner が在籍する組織は organizationsWithoutOwner に現れない', function (): void {
    [$organization] = createOrganizationWithOwner();

    $ids = app(OrganizationMembershipService::class)->organizationsWithoutOwner()
        ->pluck('id')->all();

    expect($ids)->not->toContain($organization->id);
});

test('メンバーが 1 人も居ない組織は organizationsWithoutOwner に現れる', function (): void {
    $ownerless = Organization::factory()->create();

    $ids = app(OrganizationMembershipService::class)->organizationsWithoutOwner()
        ->pluck('id')->all();

    expect($ids)->toContain($ownerless->id);
});

test('別組織 (別 team) で Owner のユーザーが所属していても Owner 在籍とは数えない', function (): void {
    // 組織 A の Owner を、組織 B には Member として所属させる。
    // team を明示せず role 照合すると B が「Owner 在籍」に誤判定される (cross-team 誤判定)。
    [, $ownerOfA] = createOrganizationWithOwner('組織A');
    $organizationB = Organization::factory()->create();
    $organizationB->users()->attach($ownerOfA);
    $ownerOfA->addRole(OrganizationRole::Member->value, $organizationB->laratrust_team_id);

    $ids = app(OrganizationMembershipService::class)->organizationsWithoutOwner()
        ->pluck('id')->all();

    expect($ids)->toContain($organizationB->id);
});
