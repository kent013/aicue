<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * 組織招待 (送信 / 受諾 / 拒否系)。
 * 招待送信は back + success flash で完結すること (画面遷移しない。
 * devnotes/20260611-template-extraction/14 §4)。
 */

/**
 * service 経由で招待を送り、メールに載った平文 token を取り出す。
 */
function inviteAndCaptureToken(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): string
{
    Notification::fake();
    app(OrganizationMembershipService::class)->inviteMember($organization, $invitedBy, $email, $role);

    $plainToken = null;
    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification) use (&$plainToken): bool {
            parse_str((string) parse_url($notification->acceptUrl, PHP_URL_QUERY), $query);
            $plainToken = $query['token'] ?? null;

            return is_string($plainToken);
        },
    );
    assert(is_string($plainToken));

    return $plainToken;
}

test('招待送信は redirect back + success flash で完結する (画面遷移しない)', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/invitations", [
            'email' => 'invitee@example.com',
            'role' => OrganizationRole::Member->value,
        ]);

    // back (= 元画面の組織設定) へ戻ること。intended は使わない
    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHas('success', '招待メールを送信しました');

    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        fn (OrganizationInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'invitee@example.com',
    );

    // 平文 token は保存されない (sha256 のみ)
    $invitation = OrganizationInvitation::query()->sole();
    expect($invitation->getAttribute('token_hash'))->toHaveLength(64);
    expect($invitation->email)->toBe('invitee@example.com');
});

test('token 受諾でメンバーシップ + 招待ロールが付与される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token]);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('success');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();
    expect($invitee->organizationRole($organization))->toBe(OrganizationRole::Admin);
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeTrue();
});

test('受諾画面 (GET) は組織名と token を表示する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('受諾テスト組織');
    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Member);

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->get('/invitations/accept?token='.$token);

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->component('Invitations/Accept')
            ->where('organizationName', '受諾テスト組織')
            ->where('token', $token),
    );
});

test('失効した招待は受諾できない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = new OrganizationInvitation(['email' => 'expired@example.com']);
    $invitation->organization()->associate($organization);
    $invitation->forceFill([
        'role' => OrganizationRole::Member->value,
        'token_hash' => hash('sha256', 'expired-token'),
        'expires_at' => now()->subDay(),
    ]);
    $invitation->save();

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => 'expired-token']);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('error');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('受諾済みの招待は再受諾できない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = new OrganizationInvitation(['email' => 'used@example.com']);
    $invitation->organization()->associate($organization);
    $invitation->forceFill([
        'role' => OrganizationRole::Member->value,
        'token_hash' => hash('sha256', 'used-token'),
        'expires_at' => now()->addDay(),
        'accepted_at' => now(),
    ]);
    $invitation->save();

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => 'used-token']);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('error');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('既存メンバーへの再招待は中立メッセージで拒否される', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/invitations", [
            'email' => $member->email,
            'role' => OrganizationRole::Member->value,
        ]);

    // 既存メンバーであることを開示しない中立メッセージ
    $response->assertSessionHasErrors(['email' => 'このメールアドレスには招待を送信できません。']);
    Notification::assertNothingSent();
});

test('有効な既存招待がある email への再招待も中立メッセージで拒否される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    inviteAndCaptureToken($organization, $owner, 'pending@example.com', OrganizationRole::Member);

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
        'email' => 'pending@example.com',
        'role' => OrganizationRole::Member->value,
    ]);

    $response->assertSessionHasErrors(['email' => 'このメールアドレスには招待を送信できません。']);
    expect(OrganizationInvitation::query()->count())->toBe(1);
});

test('manageMembers 権限がない member は招待できない (403)', function (): void {
    Notification::fake();
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($member)->post("/organizations/{$organization->slug}/invitations", [
        'email' => 'someone@example.com',
        'role' => OrganizationRole::Member->value,
    ]);

    $response->assertForbidden();
    Notification::assertNothingSent();
});

test('Owner ロールでの招待は指定できない (transferOwnership のみが正規経路)', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
        'email' => 'someone@example.com',
        'role' => OrganizationRole::Owner->value,
    ]);

    $response->assertSessionHasErrors('role');
    Notification::assertNothingSent();
});

/*
 * 招待取り消し (論理失効)。
 */

test('Owner は招待を取り消せる (行削除ではなく revoked_at で失効)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->delete("/organizations/{$organization->slug}/invitations/{$invitation->id}");

    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHas('success', '招待を取り消しました');

    // 行は残り revoked_at が立つ (監査痕跡)
    $invitation->refresh();
    expect($invitation->isRevoked())->toBeTrue();
});

test('取り消した招待は受諾できない (無効扱い、取り消しは開示しない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$invitation, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken();

    // 取り消し
    $this->actingAs($owner)->delete("/organizations/{$organization->slug}/invitations/{$invitation->id}");

    // 別ユーザーが取り消し済み token で受諾を試みる
    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token]);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('error', 'この招待は無効です。');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('manageMembers 権限がない member は招待を取り消せない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->create();

    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($member)
        ->delete("/organizations/{$organization->slug}/invitations/{$invitation->id}");

    $response->assertForbidden();
    expect($invitation->refresh()->isRevoked())->toBeFalse();
});

test('組織を跨いだ招待の取り消しは認可より前に 404 (scopeBindings)', function (): void {
    [$orgA] = createOrganizationWithOwner('組織A');
    [$orgB, $ownerB] = createOrganizationWithOwner('組織B');
    // 招待は組織 A に属する
    $invitation = OrganizationInvitation::factory()->forOrganization($orgA)->create();

    // 組織 B の owner が B の slug 経由で A の招待を取り消そうとする → relation 不整合で 404
    $response = $this->actingAs($ownerB)
        ->delete("/organizations/{$orgB->slug}/invitations/{$invitation->id}");

    $response->assertNotFound();
    expect($invitation->refresh()->isRevoked())->toBeFalse();
});

/*
 * 未ログインの招待リンク → session 保存 → register 誘導。
 */

test('未ログインの有効招待リンクは token を session 保存し register へ誘導する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = inviteAndCaptureToken($organization, $owner, 'guest@example.com', OrganizationRole::Member);

    $response = $this->get('/invitations/accept?token='.$token);

    $response->assertRedirect(route('register'));
    $response->assertSessionHas('invitation_token', $token);
});

test('無効な招待リンクは理由非開示の専用ページを返す (guest)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->revoked()->create();
    // token_hash から逆算できないため、既知の平文を持つ取り消し済み招待を用意する
    $invitation->forceFill(['token_hash' => hash('sha256', 'revoked-guest-token')])->save();

    $response = $this->get('/invitations/accept?token=revoked-guest-token');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Invitations/Invalid'));
    // 組織名を props に含めない (組織の実在を開示しない)
    $response->assertInertia(fn ($page) => $page->missing('organizationName'));
});

test('無効な招待リンクはログイン済みでも専用ページを返す (理由・組織名非開示)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->expired()->create();
    $invitation->forceFill(['token_hash' => hash('sha256', 'expired-auth-token')])->save();

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->get('/invitations/accept?token=expired-auth-token');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Invitations/Invalid'));
});

/*
 * register 経由の招待受諾 (session の invitation_token + email 照合)。
 */

test('招待 email で register すると個人組織を作らず招待組織へ参加する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('招待組織');
    $token = inviteAndCaptureToken($organization, $owner, 'newbie@example.com', OrganizationRole::Admin);

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => '新人 花子',
        'email' => 'newbie@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'newbie@example.com')->firstOrFail();
    // 招待組織へ参加し、招待ロールが付与される
    expect($organization->users()->whereKey($user->id)->exists())->toBeTrue();
    expect($user->organizationRole($organization))->toBe(OrganizationRole::Admin);
    // 個人組織は生成しない (招待組織を主所属にする)
    expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
    // 招待は受諾済みになる & session の token は落ちる
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeTrue();
    $response->assertSessionMissing('invitation_token');
});

test('招待 email と異なる email で register すると email エラーになる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', OrganizationRole::Member);

    $response = $this->withSession(['invitation_token' => $token])
        ->from('/register')
        ->post('/register', [
            'name' => '別人',
            'email' => 'someone-else@example.com',
            'password' => 'SecurePass1234',
            'terms_accepted' => '1',
        ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
    // 招待は未受諾のまま
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeFalse();
});

test('取り消し済みの招待 token で register すると通常登録 (個人組織生成) に fallback する', function (): void {
    [$organization] = createOrganizationWithOwner();
    [$invitation, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => 'fallback@example.com']);
    // 取り消し済み状態にする (HTTP 経路の acting user が register POST に漏れないよう直接失効)
    $invitation->forceFill(['revoked_at' => now()])->save();

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => 'フォールバック',
        'email' => 'fallback@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'fallback@example.com')->firstOrFail();
    // 招待組織へは参加せず、個人組織が生成される
    expect($organization->users()->whereKey($user->id)->exists())->toBeFalse();
    expect($user->organizations()->where('is_personal', true)->exists())->toBeTrue();
});
