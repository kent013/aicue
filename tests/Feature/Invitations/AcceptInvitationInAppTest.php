<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use App\Models\User;

/*
 * アプリ内受諾 (POST invitations.accept-in-app) の**存在秘匿の網羅**。
 *
 * 業務上の受諾不能 (宛先不一致 / 不在 / 期限切れ / 取消済 / 受諾済 / 削除済み組織宛) は
 * **すべて 404** に畳む (403 を返さない = 招待の存在を教えない)。
 */

/** 受諾 URL。 */
function acceptInAppUrl(int|string $invitationId): string
{
    return "/invitations/{$invitationId}/accept-in-app";
}

test('自分宛の有効な招待を受諾できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->asAdmin()
        ->create(['email' => 'invitee@example.com']);

    $response = $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('success', "「{$organization->name}」に参加しました");
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();
    expect($invitation->refresh()->isAccepted())->toBeTrue();
    expect($invitee->fresh()?->organizationRole($organization))->toBe(OrganizationRole::Admin);
});

test('受諾しても現在組織は切り替わらない', function (): void {
    [$organization] = createOrganizationWithOwner();
    [$ownOrganization, $invitee] = createOrganizationWithOwner('自分の組織');
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => $invitee->email]);

    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertRedirect(route('dashboard'));

    expect($invitee->fresh()?->current_organization_id)->toBe($ownOrganization->id);
});

test('他人宛の実在する招待は 404 (403 ではない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'someone-else@example.com']);

    $response = $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id));

    $response->assertNotFound();
    expect($response->getStatusCode())->not->toBe(403); // 403 は存在を教えるため使わない
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('不在 id は 404', function (): void {
    $invitee = User::factory()->create();

    $this->actingAs($invitee)->post(acceptInAppUrl(999999))->assertNotFound();
});

test('期限切れ・取消済・受諾済は 404', function (string $state): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->{$state}()
        ->create(['email' => 'invitee@example.com']);

    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertNotFound();
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
})->with(['expired', 'revoked', 'accepted']);

test('削除済み (soft-deleted) 組織宛は 404', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'invitee@example.com']);
    $organization->delete();

    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertNotFound();
});

test('受諾直後の再 POST は 404 (冪等 200 にしない = 秘匿)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'invitee@example.com']);

    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertRedirect(route('dashboard'));
    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertNotFound();
});

test('既にメンバーの user 宛の招待は冪等に成功する (insertOrIgnore の 0 行分岐)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $organization->users()->attach($invitee);
    $invitee->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'invitee@example.com']);

    $response = $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('success');
    expect($organization->users()->whereKey($invitee->id)->count())->toBe(1);
    expect($invitation->refresh()->isAccepted())->toBeTrue();
});

test('未 verified は verified middleware で遮断され、実在 id と不在 id で応答が同一', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->unverified()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'invitee@example.com']);

    $existing = $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id));
    $missing = $this->actingAs($invitee)->post(acceptInAppUrl(999999));

    // 存在オラクルが無いこと: status も location も同一
    expect($existing->getStatusCode())->toBe($missing->getStatusCode());
    expect($existing->headers->get('Location'))->toBe($missing->headers->get('Location'));
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('guest は login へ 302', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'invitee@example.com']);

    $this->post(acceptInAppUrl($invitation->id))->assertRedirect(route('login'));
});

test('非数値 id / 19 桁 id は 404 (500 にならない)', function (string $id): void {
    $invitee = User::factory()->create();

    $this->actingAs($invitee)->post(acceptInAppUrl($id))->assertNotFound();
})->with(['abc', '1234567890123456789']);

test('throttle: 不在 id へ 10 回 POST はすべて 404、11 回目が 429', function (): void {
    $invitee = User::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($invitee)->post(acceptInAppUrl(999999))->assertNotFound();
    }

    $this->actingAs($invitee)->post(acceptInAppUrl(999999))->assertStatus(429);
});

test('throttle: 有効な招待への正常受諾 1 回は 429 にならない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'invitee@example.com']);

    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertRedirect(route('dashboard'));
});
