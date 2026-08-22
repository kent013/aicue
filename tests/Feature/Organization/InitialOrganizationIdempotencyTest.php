<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;
use Illuminate\Support\Facades\Schema;

/*
 * 初期組織生成の冪等判定は「**所属組織が 0 件か**」(家系裁定 AG-038 / 不変条件 I4)。
 *
 * ★**保証範囲を誇張しない**: `RefreshDatabase` はテストを未 commit の
 *   トランザクション内で走らせるため、**別接続からの真の並行実行は観測できない**。
 *   ここが固定するのは「逐次に 2 回呼んでも 1 件」までである。
 *   行ロックが**書かれていること**は `OrganizationProvisioningCallSiteTest` が構文で、
 *   ロック後に他者が作っていた場合の分岐は `InitialOrganizationRaceBranchTest` が seam で見る。
 */

test('逐次 2 回呼んでも組織は 1 件 (同じ組織が返る)', function (): void {
    $user = User::factory()->create();
    $service = app(OrganizationProvisioningService::class);

    $first = $service->provisionInitialOrganization($user);
    $second = $service->provisionInitialOrganization($user);

    expect($second->is($first))->toBeTrue();
    expect($user->organizations()->count())->toBe(1);
});

test('既に別経路 (招待) で組織へ参加済みなら初期組織を作らない', function (): void {
    [$organization] = createOrganizationWithOwner('招待先');
    $user = User::factory()->create();
    $organization->users()->attach($user);

    $result = app(OrganizationProvisioningService::class)->provisionInitialOrganization($user);

    expect($result->is($organization))->toBeTrue();
    expect($user->organizations()->count())->toBe(1);
});

test('種別フラグは撤去されている (判定に使えない)', function (): void {
    expect(Schema::hasColumn('organizations', 'is_personal'))->toBeFalse();
});
