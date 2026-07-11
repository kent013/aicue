<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\CurrentOrganizationResolver;

/*
 * CurrentOrganizationResolver の解決契約 (概念設計 表示組織の解決規則)。
 * Service を直接駆動して契約を固定する (HTTP 経由は DashboardTest が担う)。
 * 競合分岐は resolve() 単体では割り込みタイミングを再現できないため、
 * seam である heal() を直接呼んで UPDATE の WHERE/EXISTS 契約を実分岐で固定する。
 */

test('current 非 null + 所属あり: その org を返す (書き込みなし)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $resolved = app(CurrentOrganizationResolver::class)->resolve($owner);

    expect($resolved?->id)->toBe($organization->id);
    expect($owner->fresh()->current_organization_id)->toBe($organization->id);
});

test('所属 0 件: null を返し current は変更されない', function (): void {
    $user = User::factory()->create();

    $resolved = app(CurrentOrganizationResolver::class)->resolve($user);

    expect($resolved)->toBeNull();
    expect($user->fresh()->current_organization_id)->toBeNull();
});

test('org はあるが current null: 候補 (organizations.id 昇順先頭) へ自己修復して返す', function (): void {
    [$first] = createOrganizationWithOwner('先頭組織');
    [$second] = createOrganizationWithOwner('二番目組織');
    $member = attachOrganizationMember($second);
    $first->users()->attach($member);
    expect($member->current_organization_id)->toBeNull();

    $resolved = app(CurrentOrganizationResolver::class)->resolve($member);

    $expectedId = min($first->id, $second->id);
    expect($resolved?->id)->toBe($expectedId);
    expect($member->fresh()->current_organization_id)->toBe($expectedId);
});

test('current が非所属 org を指す (dangling): 当該 org を返さず所属 org へ自己修復する', function (): void {
    [$foreign] = createOrganizationWithOwner('他人の組織');
    [$organization] = createOrganizationWithOwner('自分の組織');
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $foreign->id])->save();

    $resolved = app(CurrentOrganizationResolver::class)->resolve($member);

    expect($resolved?->id)->toBe($organization->id);
    expect($resolved?->id)->not->toBe($foreign->id);
    expect($member->fresh()->current_organization_id)->toBe($organization->id);
});

test('heal: 候補 membership 消失 (EXISTS 偽) は 0 件更新で current は null のまま', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $organization->users()->detach($member);

    $updated = app(CurrentOrganizationResolver::class)->heal($member, null, $organization->id);

    expect($updated)->toBe(0);
    expect($member->fresh()->current_organization_id)->toBeNull();
});

test('heal: 観測後に current が別 org へ変更済みなら上書きしない (WHERE 不一致)', function (): void {
    [$orgA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    $member = attachOrganizationMember($orgA);
    $orgB->users()->attach($member);
    // 観測 (null) の後に別セッションが current=B へ変更した状況を再現
    $member->forceFill(['current_organization_id' => $orgB->id])->save();

    $updated = app(CurrentOrganizationResolver::class)->heal($member, null, $orgA->id);

    expect($updated)->toBe(0);
    expect($member->fresh()->current_organization_id)->toBe($orgB->id);
});

test('heal: dangling 観測値は所属 org へ置換される (observed 一致分岐)', function (): void {
    [$foreign] = createOrganizationWithOwner('他人の組織');
    [$organization] = createOrganizationWithOwner('自分の組織');
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $foreign->id])->save();

    $updated = app(CurrentOrganizationResolver::class)
        ->heal($member, $foreign->id, $organization->id);

    expect($updated)->toBe(1);
    expect($member->fresh()->current_organization_id)->toBe($organization->id);
});

test('UPDATE 0 件後の resolve: fresh 再取得した最新状態で解決する (解決不能なら null)', function (): void {
    // 所属 0 件だが current が dangling を指す状態 → heal は EXISTS 偽で不発 →
    // fresh 再取得後の所属再確認でも解決できず null (無限再試行しない)
    [$foreign] = createOrganizationWithOwner('他人の組織');
    $user = User::factory()->create();
    $user->forceFill(['current_organization_id' => $foreign->id])->save();

    $resolved = app(CurrentOrganizationResolver::class)->resolve($user);

    expect($resolved)->toBeNull();
    // 修復対象候補が無いため dangling 値は残るが、描画には決して出ない (所属再確認)
    expect($user->fresh()->current_organization_id)->toBe($foreign->id);
});

test('resolve は Organization モデルを pivot relation 経由で返す (型契約)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $resolved = app(CurrentOrganizationResolver::class)->resolve($owner);

    expect($resolved)->toBeInstanceOf(Organization::class);
});
