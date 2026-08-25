<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/*
 * 招待経由登録への verified 付与と登録直後の着地 (正典 v1 i16 / 裁定 AG-214 / aicue:T263 施策 C)。
 *
 * join 成立 = 有効招待 + 宛先一致のロック下再照合を通過 = 招待メール URL の所持
 * = 受信箱の所有の証明。よって招待成立の登録は email 確認済みで作成し、確認メールを送らず、
 * 「認証してください」画面を経由させずに組織解決の正規入口 (app.entry) へ着地させる。
 * 受諾不能 (取消・組織論理削除等) の fallback 登録には付与しない (i16 後段の fail-closed)。
 */

/**
 * 指定 email 宛の active 招待を作り、平文 token とともに返す (factory ベース)。
 *
 * @return array{0: OrganizationInvitation, 1: string}
 */
function makeVerifiedFlowInvitation(Organization $organization, string $email): array
{
    return OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => $email]);
}

/**
 * 招待 token を session に載せて register POST する。
 *
 * @return TestResponse<Response>
 */
function registerWithInvitationToken(TestCase $test, ?string $token, string $email, string $name = '招待 花子')
{
    $request = $token !== null ? $test->withSession(['invitation_token' => $token]) : $test;

    return $request->post('/register', [
        'name' => $name,
        'email' => $email,
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);
}

test('招待経由登録の成立は verified で作成され確認メールを送らず app.entry へ着地する', function (): void {
    Notification::fake();
    [$organization] = createOrganizationWithOwner('招待組織');
    [, $token] = makeVerifiedFlowInvitation($organization, 'verified@example.com');

    $response = registerWithInvitationToken($this, $token, 'verified@example.com');

    // 「認証してください」画面を経由させず、組織解決の正規入口へ決定論的に送る
    $response->assertRedirect(route('app.entry'));

    $user = User::whereBlind('email', 'email_index', 'verified@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull();
    expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
    // 同一 tx 内で verified を立てるため Registered event の
    // SendEmailVerificationNotification は hasVerifiedEmail() を見て確認メールを送らない
    Notification::assertNotSentTo($user, VerifyEmail::class);
});

test('着地チェーンを一段ずつ固定: 登録 POST → app.entry → 招待組織 dashboard → 200', function (): void {
    // followRedirects では途中に verification.notice が挟まる経路も最終到達が同じなら
    // 緑になり「経由しない」の根拠にならないため、redirect を自動追跡せず一段ずつ検査する
    [$organization] = createOrganizationWithOwner('着地組織');
    [, $token] = makeVerifiedFlowInvitation($organization, 'landing@example.com');

    // 1. 登録 POST が app.entry へ redirect する
    registerWithInvitationToken($this, $token, 'landing@example.com')
        ->assertRedirect(route('app.entry'));

    // 2. app.entry は招待組織 (唯一の所属) の dashboard へ直接 redirect する
    $this->get(route('app.entry'))
        ->assertRedirectToRoute('dashboard', ['organization' => $organization->slug]);

    // 3. その dashboard は 200 (verified middleware を通過する)
    $this->get(route('dashboard', ['organization' => $organization->slug]))
        ->assertOk();
});

test('JSON (XHR) 後方互換: 招待成立の登録でも 201 のまま (membership と verified を併せて固定)', function (): void {
    [$organization] = createOrganizationWithOwner('XHR 組織');
    [, $token] = makeVerifiedFlowInvitation($organization, 'xhr@example.com');

    $response = $this->withSession(['invitation_token' => $token])->postJson('/register', [
        'name' => 'XHR 太郎',
        'email' => 'xhr@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertCreated();

    // 「未検証の通常登録が偶然 201」と区別する (偽グリーン防止)
    $user = User::whereBlind('email', 'email_index', 'xhr@example.com')->firstOrFail();
    expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
});

test('fallback (取消済み token) は unverified のまま確認メールが送られ verification.notice へ', function (): void {
    Notification::fake();
    [$organization] = createOrganizationWithOwner('取消組織');
    [$invitation, $token] = makeVerifiedFlowInvitation($organization, 'revoked@example.com');
    $invitation->forceFill(['revoked_at' => now()])->save();

    $response = registerWithInvitationToken($this, $token, 'revoked@example.com');

    // 付与側 (app.entry) と対称に固定する: 受諾不能の fallback は従来どおり認証促し画面へ
    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'revoked@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    expect($organization->users()->whereKey($user->getKey())->exists())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('通常登録 (継続なし) は unverified のまま確認メールが送られる (対称の負例)', function (): void {
    Notification::fake();

    $response = registerWithInvitationToken($this, null, 'plain@example.com', '通常 太郎');

    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'plain@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('論理削除組織の招待 token での登録は unverified の fallback (施策 A との結合 / i16 後段)', function (): void {
    Notification::fake();
    [$organization] = createOrganizationWithOwner('消えた組織');
    [, $token] = makeVerifiedFlowInvitation($organization, 'gone@example.com');
    $organization->delete();

    $response = registerWithInvitationToken($this, $token, 'gone@example.com');

    // 前提 (i13: join 成立) が成立しない登録に verified を与えない
    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'gone@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    expect($user->organizations()->count())->toBe(1); // 個人組織 fallback
    Notification::assertSentTo($user, VerifyEmail::class);
});
