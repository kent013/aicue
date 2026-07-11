<?php

declare(strict_types=1);

use App\Enums\AdminConsoleRole;
use App\Enums\OrganizationRole;

/*
 * メンバー管理 (ロール変更 / 削除)。
 * ロール操作は laratrust_team_id 明示 (strict_check=true) で行われる。
 * ロール変更は 3 値遷移コマンド (admin/editor/shooter = AdminConsoleRole)。
 * 遷移の最終状態 (org ロール + Default Project pivot) は ConsoleRoleTransitionTest が固定する。
 */

test('owner はメンバーのロールを変更できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->patch("/organizations/{$organization->slug}/members/{$member->id}", [
            'role' => AdminConsoleRole::Admin->value,
        ]);

    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHas('success');
    $member = $member->fresh();
    expect($member->organizationRole($organization))->toBe(OrganizationRole::Admin);
    // 旧ロールは残らない (removeRole → addRole)
    expect($member->hasRole(OrganizationRole::Member->value, $organization->laratrust_team_id))->toBeFalse();
});

test('最後の Owner は降格できない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$owner->id}", [
        'role' => AdminConsoleRole::Admin->value,
    ]);

    $response->assertSessionHasErrors('role');
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});

test('他に Owner がいれば Owner を降格できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Owner);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$owner->id}", [
        'role' => AdminConsoleRole::Admin->value,
    ]);

    $response->assertSessionHasNoErrors();
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Admin);
});

test('Owner への昇格はロール変更経路では指定できない (enum 外の値は errors)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => OrganizationRole::Owner->value,
    ]);

    $response->assertSessionHasErrors('role');
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});

test('Owner は削除できない (移譲が先)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($admin)->delete("/organizations/{$organization->slug}/members/{$owner->id}");

    $response->assertSessionHasErrors('member');
    expect($organization->users()->whereKey($owner->id)->exists())->toBeTrue();
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});

test('メンバー削除で membership / ロール / current_organization_id が掃除される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($owner)->delete("/organizations/{$organization->slug}/members/{$member->id}");

    $response->assertSessionHas('success');
    $member = $member->fresh();
    expect($organization->users()->whereKey($member->id)->exists())->toBeFalse();
    expect($member->organizationRole($organization))->toBeNull();
    expect($member->current_organization_id)->toBeNull();
});

test('manageMembers 権限がない member はロール変更できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $other = attachOrganizationMember($organization);

    $response = $this->actingAs($member)->patch("/organizations/{$organization->slug}/members/{$other->id}", [
        'role' => AdminConsoleRole::Admin->value,
    ]);

    $response->assertForbidden();
    expect($other->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});

test('旧契約値 (organization_admin / organization_member) は enum 外として errors になる', function (string $legacyRole): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => $legacyRole,
    ]);

    $response->assertSessionHasErrors('role');
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
})->with(['organization_admin', 'organization_member']);

test('保護キーを payload に混ぜたロール変更は errors (ProhibitsProtectedKeys)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => AdminConsoleRole::Admin->value,
        'organization_id' => 999,
    ]);

    $response->assertSessionHasErrors('organization_id');
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});
