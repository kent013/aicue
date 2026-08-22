<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;

/*
 * 「ロック取得後に他者が組織を作っていた」分岐を seam 経由で直接叩く
 * (家系裁定 AG-038 / 不変条件 I4)。
 *
 * ★**並行でも 1 件になることを実測したとは書かない**。`RefreshDatabase` の制約で
 *   別接続からの真の並行実行は観測できない (先例: docs/template-divergence.md D7)。
 *   ここで固定するのは「ロック後の再クエリで既存が見つかったら**それを返す**」という
 *   分岐そのものである。
 */

test('ロック後のクエリで既存が見つかったら新規生成しない', function (): void {
    [$organization] = createOrganizationWithOwner('先着');
    $user = User::factory()->create();

    $service = app(OrganizationProvisioningService::class);

    // 「ロック取得後に他者が attach していた」状況を作る = ロック前の user は所属 0 件だが、
    // provisionInitialOrganization がロック後に数える時点では 1 件になっている。
    $organization->users()->attach($user);

    $result = $service->provisionInitialOrganization($user);

    expect($result->is($organization))->toBeTrue();
    expect($user->organizations()->count())->toBe(1);
});

test('所属 0 件なら生成する (分岐の裏側)', function (): void {
    $user = User::factory()->create();

    $result = app(OrganizationProvisioningService::class)->provisionInitialOrganization($user);

    expect($user->organizations()->pluck('organizations.id')->all())->toBe([$result->id]);
});
