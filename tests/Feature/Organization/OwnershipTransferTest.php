<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\SecurityAuditEvent;
use App\Models\User;

/*
 * オーナー移譲 (recent-auth 必須 + 行ロックによる直列化)。
 */

test('移譲で両者のロールが入れ替わる (from は Admin / to は Owner)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $admin->id]);

    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHas('success', 'オーナーを移譲しました');
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Admin);
    expect($admin->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);

    // SecurityEventRecorder による監査記録 (metadata に from/to)
    $event = SecurityAuditEvent::query()->where('event_type', 'ownership_transferred')->sole();
    expect($event->metadata)->toMatchArray([
        'organization_id' => $organization->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $admin->id,
    ]);
});

test('recent-auth なしでは移譲できない (確認画面へ redirect)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);

    $response = $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $admin->id]);

    $response->assertRedirect();
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
    expect($admin->fresh()->organizationRole($organization))->toBe(OrganizationRole::Admin);
});

test('非 Owner は移譲できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($admin)
        ->withSession(['recent_auth_at' => time()])
        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $member->id]);

    $response->assertForbidden();
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});

test('非メンバーへは移譲できない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $outsider = User::factory()->create();

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $outsider->id]);

    $response->assertSessionHasErrors('user_id');
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
    expect($outsider->fresh()->organizationRole($organization))->toBeNull();
});

test('自分自身へは移譲できない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $owner->id]);

    $response->assertSessionHasErrors('user_id');
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});
