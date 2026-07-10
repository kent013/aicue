<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use App\Models\Organization;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Notifications\User\TwoFactorResetSecurityNotification;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

/**
 * 組織単位の 2FA 強制。
 *
 * 機能契約: 1 つでも two_factor_required 組織に所属する未準拠 (disabled/pending) ユーザーは
 * ALLOWED_ROUTE_NAMES 以外の web 経路すべてがゲートされ 2FA 設定ページ (settings.security)
 * へ誘導される。準拠 (enabled) ユーザーの self-disable は 422 で拒否され、復旧は組織管理者の
 * resetTwoFactor 経由のみ。
 */

/** ユーザーに 2FA 状態 (disabled / pending / enabled) を直接セットする */
function tfeSetTwoFactorState(User $user, string $status): User
{
    if ($status === 'disabled') {
        return $user;
    }

    $attributes = [
        'two_factor_secret' => encrypt('test-totp-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one'], JSON_THROW_ON_ERROR)),
    ];
    if ($status === 'enabled') {
        $attributes['two_factor_confirmed_at'] = now();
    }
    $user->forceFill($attributes)->save();

    return $user;
}

/** @return array{Organization, User} 2FA 必須方針付きの組織 + enabled 準拠 Owner */
function tfeCreateOrganization(bool $twoFactorRequired = false, string $ownerStatus = 'enabled'): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    if ($twoFactorRequired) {
        $organization->forceFill(['two_factor_required' => true])->save();
    }
    tfeSetTwoFactorState($owner, $ownerStatus);

    return [$organization, $owner];
}

function tfeAddMember(
    Organization $organization,
    string $twoFactorStatus = 'disabled',
    OrganizationRole $role = OrganizationRole::Member,
): User {
    $member = attachOrganizationMember($organization, $role);

    return tfeSetTwoFactorState($member, $twoFactorStatus);
}

function tfeToggleUrl(Organization $organization): string
{
    return "/organizations/{$organization->slug}/two-factor-requirement";
}

function tfeResetUrl(Organization $organization, User $member): string
{
    return "/organizations/{$organization->slug}/members/{$member->id}/two-factor";
}

// ──────────────────────────── 必須化トグル ────────────────────────────

test('guest はトグルできない (login へ redirect)', function (): void {
    [$organization] = tfeCreateOrganization();

    $this->patch(tfeToggleUrl($organization), ['enabled' => true])
        ->assertRedirect(route('login'));
});

test('Owner 以外 (admin / member) はトグルできない (403)', function (OrganizationRole $role): void {
    [$organization] = tfeCreateOrganization();
    $actor = tfeAddMember($organization, 'enabled', $role);

    $this->actingAs($actor)
        ->withSession(['recent_auth_at' => time()])
        ->patch(tfeToggleUrl($organization), ['enabled' => true])
        ->assertForbidden();

    expect($organization->fresh()->two_factor_required)->toBeFalse();
})->with([
    'admin' => [OrganizationRole::Admin],
    'member' => [OrganizationRole::Member],
]);

test('鮮度切れ Owner は recent-auth confirm へ 302 (方針は変わらない)', function (): void {
    [$organization, $owner] = tfeCreateOrganization();

    $this->actingAs($owner)
        ->patch(tfeToggleUrl($organization), ['enabled' => true])
        ->assertRedirect(route('recent-auth.confirm'));

    expect($organization->fresh()->two_factor_required)->toBeFalse();
});

test('Owner 自身が未準拠なら有効化できない (validation error)', function (string $status): void {
    [$organization, $owner] = tfeCreateOrganization(ownerStatus: $status);

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->patch(tfeToggleUrl($organization), ['enabled' => true])
        ->assertSessionHasErrors(['enabled']);

    expect($organization->fresh()->two_factor_required)->toBeFalse();
})->with(['disabled', 'pending']);

test('準拠 Owner が有効化: two_factor_required=true + flash', function (): void {
    [$organization, $owner] = tfeCreateOrganization();

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->patch(tfeToggleUrl($organization), ['enabled' => true])
        ->assertRedirect(route('organizations.settings', $organization))
        ->assertSessionHas('success');

    expect($organization->fresh()->two_factor_required)->toBeTrue();
});

test('解除に前提条件は無い (enabled Owner が解除できる)', function (): void {
    [$organization, $owner] = tfeCreateOrganization(twoFactorRequired: true);

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->patch(tfeToggleUrl($organization), ['enabled' => false])
        ->assertRedirect(route('organizations.settings', $organization));

    expect($organization->fresh()->two_factor_required)->toBeFalse();
});

test('settings props に twoFactorRequired とメンバーの twoFactorStatus が乗る', function (): void {
    [$organization, $owner] = tfeCreateOrganization(twoFactorRequired: true);
    tfeAddMember($organization, 'pending');

    $this->actingAs($owner)
        ->get(route('organizations.settings', $organization))
        ->assertInertia(fn ($page) => $page
            ->component('Organizations/Settings')
            ->where('organization.twoFactorRequired', true)
            ->where('members.0.twoFactorStatus', 'enabled')
            ->where('members.1.twoFactorStatus', 'pending'));
});

// ──────────────────────────── 未準拠ユーザーの全画面ゲート ────────────────────────────

test('必須組織の未準拠メンバーは dashboard から settings.security へ 302 + flash に組織名', function (string $status): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, $status);

    $this->actingAs($member)
        ->get('/dashboard')
        ->assertRedirect(route('settings.security'));

    expect(session('info'))->toContain($organization->name);
    expect(session('info'))->toContain('2 段階認証を必須としています');
})->with(['disabled', 'pending']);

test('enabled メンバーは素通し', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($member)->get('/dashboard')->assertOk();
});

test('必須でない組織のみ所属の未準拠ユーザーは素通し', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: false);
    $member = tfeAddMember($organization, 'disabled');

    $this->actingAs($member)->get('/dashboard')->assertOk();
});

test('未認証リクエスト (GET /login) にゲートは干渉しない', function (): void {
    tfeCreateOrganization(twoFactorRequired: true);

    $this->get('/login')->assertOk();
});

test('allowlist の各 route はゲート中でも settings.security へ redirect されない', function (string $routeName): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'pending');

    $route = app('router')->getRoutes()->getByName($routeName);
    expect($route)->not->toBeNull();

    // URI パラメータを持つ route (verification.verify / social.*) はダミー値で充足
    $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
    $method = in_array('GET', $route->methods(), true) ? 'get' : strtolower($route->methods()[0]);

    $response = $this->actingAs($member)
        ->withSession(['recent_auth_at' => time()])
        ->{$method}($uri);

    // ゲートによる settings.security への redirect でないことのみ検証
    // (本来の応答 / 別 redirect / 4xx は許容。settings.security 自身の GET は 200)
    if ($routeName === 'settings.security') {
        $response->assertOk();
    } else {
        expect($response->headers->get('Location'))->not->toBe(route('settings.security'));
    }
})->with(array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES));

test('非許可 route の代表はゲート中必ず settings.security へ 302', function (string $path): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'disabled');

    $this->actingAs($member)
        ->withSession(['recent_auth_at' => time()])
        ->get($path)
        ->assertRedirect(route('settings.security'));
})->with([
    'dashboard' => ['/dashboard'],
    'billing' => ['/billing'],
    'projects' => ['/projects'],
]);

test('XHR (Accept: json) でゲート → 409 + code/message/redirect + no-store', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'disabled');

    $response = $this->actingAs($member)->getJson('/dashboard');

    $response->assertStatus(409)
        ->assertJsonStructure(['code', 'message', 'redirect'])
        // code 判別子 (recent_auth_required 409 との誤食防止。クライアントは code 厳格一致で処理)
        ->assertJsonPath('code', 'two_factor_required')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('redirect'))->toBe(route('settings.security'));
});

test('状態遷移 (Fortify 実経路): ゲート → enable → confirm → ゲート解除', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'disabled');

    // 1. ゲートされる
    $this->actingAs($member)->get('/dashboard')->assertRedirect(route('settings.security'));

    // 2. 設定ページは到達可能
    $this->get(route('settings.security'))->assertOk();

    // 3. Fortify 実 POST で enrollment 開始
    $this->post('/user/two-factor-authentication')->assertRedirect();
    $secret = decrypt($member->fresh()->two_factor_secret);
    expect($secret)->toBeString();

    // 4. 実 TOTP で confirm
    $code = app(Google2FA::class)->getCurrentOtp($secret);
    $this->post('/user/confirmed-two-factor-authentication', ['code' => $code])->assertRedirect();
    expect($member->fresh()->two_factor_confirmed_at)->not->toBeNull();

    // 5. ゲート解除
    $this->get('/dashboard')->assertOk();
});

// ──────────────────────────── self-disable のサーバ側禁止 ────────────────────────────

test('必須組織の準拠メンバーの self-disable は 422 (code) で拒否され secret が残る', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'enabled');

    $response = $this->actingAs($member)->deleteJson('/user/two-factor-authentication');

    $response->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_disable_forbidden');

    // 第二要素が削除されていない (= ログインバイパスにならない)
    expect($member->fresh()->two_factor_secret)->not->toBeNull();
    expect($member->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('HTML (非 XHR) の self-disable は flash error + back redirect で secret が残る', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($member)
        ->from(route('settings.security'))
        ->delete('/user/two-factor-authentication')
        ->assertRedirect(route('settings.security'))
        ->assertSessionHas('error');

    expect($member->fresh()->two_factor_secret)->not->toBeNull();
});

test('非必須組織のみ所属の準拠ユーザーは self-disable できる (secret 消去)', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: false);
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($member)
        ->delete('/user/two-factor-authentication')
        ->assertRedirect();

    expect($member->fresh()->two_factor_secret)->toBeNull();
});

// ──────────────────────────── 管理者によるメンバー 2FA リセット ────────────────────────────

test('Owner がメンバーの 2FA を解除: secret 消去 + 本人通知 + 監査記録 (理由込み)', function (): void {
    Notification::fake();
    [$organization, $owner] = tfeCreateOrganization();
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from(route('organizations.settings', $organization))
        ->delete(tfeResetUrl($organization, $member), ['reason' => '端末紛失によるロックアウト救済'])
        ->assertRedirect(route('organizations.settings', $organization))
        ->assertSessionHas('success');

    expect($member->fresh()->two_factor_secret)->toBeNull();
    expect($member->fresh()->two_factor_confirmed_at)->toBeNull();

    Notification::assertSentTo($member, TwoFactorResetSecurityNotification::class);

    $event = SecurityAuditEvent::query()
        ->where('event_type', 'org_member_two_factor_reset')
        ->sole();
    expect($event->user_id)->toBe($member->id);
    expect($event->metadata)->toMatchArray([
        'organization_id' => $organization->id,
        'actor_user_id' => $owner->id,
        'reason' => '端末紛失によるロックアウト救済',
    ]);
});

test('鮮度切れの Owner のリセットは recent-auth confirm へ 302 (解除されない)', function (): void {
    [$organization, $owner] = tfeCreateOrganization();
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($owner)
        ->delete(tfeResetUrl($organization, $member), ['reason' => '端末紛失によるロックアウト救済'])
        ->assertRedirect(route('recent-auth.confirm'));

    expect($member->fresh()->two_factor_secret)->not->toBeNull();
});

test('自分自身の 2FA はこの経路で解除できない (403)', function (): void {
    [$organization, $owner] = tfeCreateOrganization();

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $owner), ['reason' => '自分の 2FA を外したいだけ'])
        ->assertForbidden();

    expect($owner->fresh()->two_factor_secret)->not->toBeNull();
});

test('Admin は Member を解除できるが、同格 (Admin) 以上は 403', function (): void {
    Notification::fake();
    [$organization] = tfeCreateOrganization();
    $actor = tfeAddMember($organization, 'enabled', OrganizationRole::Admin);
    $peerAdmin = tfeAddMember($organization, 'enabled', OrganizationRole::Admin);
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($actor)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $peerAdmin), ['reason' => '同格を外そうとする濫用ケース'])
        ->assertForbidden();
    expect($peerAdmin->fresh()->two_factor_secret)->not->toBeNull();

    $this->actingAs($actor)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $member), ['reason' => '端末紛失によるロックアウト救済'])
        ->assertRedirect();
    expect($member->fresh()->two_factor_secret)->toBeNull();
});

test('Member (管理権限なし) はリセットできない (403)', function (): void {
    [$organization] = tfeCreateOrganization();
    $actor = tfeAddMember($organization, 'enabled');
    $target = tfeAddMember($organization, 'enabled');

    $this->actingAs($actor)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $target), ['reason' => '権限のないメンバーによる操作'])
        ->assertForbidden();
});

test('組織外の {user} は認可より前に 404 (存在を漏らさない)', function (): void {
    [$organization, $owner] = tfeCreateOrganization();
    [, $outsider] = tfeCreateOrganization();

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $outsider), ['reason' => 'cross-org の対象を指定する攻撃'])
        ->assertNotFound();
});

test('理由は必須 (10 文字未満は validation error)', function (?string $reason): void {
    [$organization, $owner] = tfeCreateOrganization();
    $member = tfeAddMember($organization, 'enabled');

    $payload = $reason === null ? [] : ['reason' => $reason];

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $member), $payload)
        ->assertSessionHasErrors(['reason']);

    expect($member->fresh()->two_factor_secret)->not->toBeNull();
})->with([
    '未指定' => [null],
    '短すぎる' => ['短い理由'],
]);

test('2FA 未設定 (disabled) のメンバーへのリセットは明示拒否 (validation error)', function (): void {
    [$organization, $owner] = tfeCreateOrganization();
    $member = tfeAddMember($organization, 'disabled');

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $member), ['reason' => '設定していない対象への誤操作'])
        ->assertSessionHasErrors(['two_factor']);
});

test('接続: 必須組織メンバーの 2FA を管理者が解除すると次のリクエストからゲートされる', function (): void {
    Notification::fake();
    [$organization, $owner] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'enabled');

    // 解除前は素通し
    $this->actingAs($member)->get('/dashboard')->assertOk();

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $member), ['reason' => '端末紛失によるロックアウト救済'])
        ->assertRedirect();

    $this->actingAs($member->fresh())
        ->get('/dashboard')
        ->assertRedirect(route('settings.security'));
});
