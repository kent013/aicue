<?php

declare(strict_types=1);

use App\Models\Project;
use App\Services\Project\DefaultProjectResolver;
use Illuminate\Support\Facades\DB;

/*
 * Default Project 解決規約の single source of truth (v1 = org の先頭 project)。
 * capture.home / 管理メニュー / ロール遷移 pivot 書き込みが全てここへ寄る。
 */

test('project が無い org では null を返す', function (): void {
    [$organization] = createOrganizationWithOwner();

    $resolver = app(DefaultProjectResolver::class);

    expect($resolver->resolve($organization))->toBeNull();
    DB::transaction(function () use ($resolver, $organization): void {
        expect($resolver->resolveForUpdate($organization))->toBeNull();
    });
});

test('複数 project がある場合は最小 id (先頭 project) を返す', function (): void {
    [$organization] = createOrganizationWithOwner();
    $first = Project::factory()->forOrganization($organization)->create();
    Project::factory()->forOrganization($organization)->create();

    $resolver = app(DefaultProjectResolver::class);

    expect($resolver->resolve($organization)?->id)->toBe($first->id);
    DB::transaction(function () use ($resolver, $organization, $first): void {
        expect($resolver->resolveForUpdate($organization)?->id)->toBe($first->id);
    });
});

test('別 org の project は返さない (cross-org 不変条件)', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    Project::factory()->forOrganization($organizationB)->create();

    $resolver = app(DefaultProjectResolver::class);

    expect($resolver->resolve($organizationA))->toBeNull();
});

test('resolveForUpdate は tx 中に project が消えたら null (呼び出し側の不在時契約へ倒す)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    // 削除競合の最終挙動 = null 契約 (真の並行実行は並列 DB テストでは flaky のため逐次で固定。
    // 競合時のユーザー向け挙動は ConsoleRoleTransitionTest の不在時エラー/未割当が担保)
    $resolver = app(DefaultProjectResolver::class);
    DB::transaction(function () use ($resolver, $organization, $project): void {
        $project->delete();
        expect($resolver->resolveForUpdate($organization))->toBeNull();
    });
});
