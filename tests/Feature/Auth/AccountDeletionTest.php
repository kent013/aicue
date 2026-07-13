<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\SecurityAuditEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;

test('再認証 (step-up) なしではアカウント削除できない', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/settings/account');

    // recent-auth が確認画面へ redirect する
    $response->assertRedirect();
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('step-up 済みならアカウントを削除でき、関連データが掃除される', function (): void {
    $user = User::factory()->create();
    $social = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-123']);
    $social->user()->associate($user);
    $social->save();

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    $this->assertGuest();
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
    expect(SocialAccount::query()->whereKey($social->id)->exists())->toBeFalse();

    // 削除イベントは user_id が null 化されて残る (nullOnDelete)
    expect(
        SecurityAuditEvent::query()->where('event_type', 'account_deleted')->exists(),
    )->toBeTrue();
});

test('唯一オーナーで他メンバーが残る場合はアカウント削除がブロックされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin); // 孤児化する残存メンバー

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertRedirect('/settings');
    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue(); // 残存
});

test('唯一オーナーだが自分のみメンバー (個人組織) なら削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(); // owner 1 人・他メンバー無し

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('複数オーナーがいれば削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $second = attachOrganizationMember($organization, OrganizationRole::Owner);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
    expect($second->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});

test('ブロック→2人目オーナー追加後は削除できる (現在状態で再評価)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin);
    // この時点では唯一 Owner + 他メンバー有り → ブロックされるはず
    expect(app(OrganizationMembershipService::class)->organizationsBlockingDeletion($owner))->toHaveCount(1);

    attachOrganizationMember($organization, OrganizationRole::Owner); // 2 人目 Owner を追加

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('2オーナー→片方降格後は唯一オーナー+メンバーで削除がブロックされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $second = attachOrganizationMember($organization, OrganizationRole::Owner);
    attachOrganizationMember($organization, OrganizationRole::Member); // 孤児化するメンバー
    // service 正規経路で 2 人目 Owner を Admin へ降格 (owner を 1 人に戻す)
    app(OrganizationMembershipService::class)->changeRole($organization, $second, OrganizationRole::Admin);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});
