<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Billing\TicketLedgerService;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Inertia\Testing\AssertableInertia;

/**
 * 招待経由の register 画面での招待 email prefill (T055)。
 *
 * - active token を session に持つ GET /register は招待先 email を prop `invitationEmail` に返し、
 *   PII を含むため応答に Cache-Control: no-store を付ける。active token は session に維持される
 *   (後続 POST の受諾に必要)。
 * - stale/invalid token (失効/取消/受諾済/不在/非文字列) は GET 時点で null + session forget。
 * - token 無し (通常登録) は prop null かつ no-store を付けない (非退行)。
 */

/**
 * 招待先 email に固定した active 招待を作り、平文 token を session に載せた状態を作る。
 *
 * @return array{OrganizationInvitation, string, string, Organization}
 */
function makeInvitationWithToken(string $email = 'invitee@example.com'): array
{
    [$organization] = createOrganizationWithOwner();
    /** @var OrganizationInvitation $invitation */
    [$invitation, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => $email]);

    return [$invitation, $token, $email, $organization];
}

test('active token を session に持つ GET /register は招待 email を prefill し no-store を付け token を維持する', function (): void {
    [, $token, $email] = makeInvitationWithToken();

    $response = $this->withSession(['invitation_token' => $token])->get('/register');

    $response->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Auth/Register')
                ->where('invitationEmail', $email)
                ->has('socialProviders'),
        );

    // PII を含むため HTTP キャッシュへの保存を禁止する
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // active token は POST 受諾のため session に維持される (GET で forget しない)
    $response->assertSessionHas('invitation_token', $token);
});

test('expired token → invitationEmail null かつ session から forget', function (): void {
    [$organization] = createOrganizationWithOwner();
    [, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->expired()
        ->createWithPlainToken(['email' => 'invitee@example.com']);

    $response = $this->withSession(['invitation_token' => $token])->get('/register');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
    $response->assertSessionMissing('invitation_token');
});

test('revoked token → invitationEmail null かつ forget', function (): void {
    [$organization] = createOrganizationWithOwner();
    [, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->revoked()
        ->createWithPlainToken(['email' => 'invitee@example.com']);

    $response = $this->withSession(['invitation_token' => $token])->get('/register');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
    $response->assertSessionMissing('invitation_token');
});

test('accepted token → invitationEmail null かつ forget', function (): void {
    [$organization] = createOrganizationWithOwner();
    [, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->accepted()
        ->createWithPlainToken(['email' => 'invitee@example.com']);

    $response = $this->withSession(['invitation_token' => $token])->get('/register');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
    $response->assertSessionMissing('invitation_token');
});

test('存在しない token (DB 不在) → invitationEmail null かつ forget', function (): void {
    $response = $this->withSession(['invitation_token' => 'nonexistent-token'])->get('/register');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
    $response->assertSessionMissing('invitation_token');
});

test('非文字列 session 値 (配列) → invitationEmail null かつ forget (fail-secure)', function (): void {
    $response = $this->withSession(['invitation_token' => ['tampered']])->get('/register');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
    $response->assertSessionMissing('invitation_token');
});

test('token 無し GET /register は invitationEmail null・socialProviders あり・no-store を付けない', function (): void {
    $response = $this->get('/register');

    $response->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Auth/Register')
                ->where('invitationEmail', null)
                ->has('socialProviders'),
        );

    // PII を含まない通常応答には no-store を付けない (不要なキャッシュ抑止を避ける)
    expect((string) $response->headers->get('Cache-Control'))->not->toContain('no-store');
});

test('resolver は空 email の active 招待では null を返し token を forget する (S2↔S3 契約: 非null=非空)', function (): void {
    [$organization] = createOrganizationWithOwner();
    // 想定外の欠損 (空 email) を持つ active 招待でも prefill しない = 非空契約を固定する
    [, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => '']);

    $session = new SessionStore('test-session', new ArraySessionHandler(60));
    $session->put('invitation_token', $token);

    $email = app(OrganizationMembershipService::class)->resolveRegisterPrefillEmail($session);

    expect($email)->toBeNull();
    expect($session->has('invitation_token'))->toBeFalse();
});

test('GET で active prefill 後 POST 前に revoke されても登録は成立し個人組織へ fallback する', function (): void {
    [$invitation, $token, $email, $organization] = makeInvitationWithToken('fallback@example.com');

    // GET: active なので prefill され token は維持される
    $this->withSession(['invitation_token' => $token])->get('/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', $email));

    // POST 前に招待が取り消される
    $invitation->forceFill(['revoked_at' => now()])->save();

    // POST: MatchesInvitationEmail は no-op (active 不在) → 登録成立 → 招待受諾は null → 個人組織 fallback
    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => '山田 太郎',
        'email' => $email,
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertRedirect(route('verification.notice'));
    $this->assertAuthenticated();

    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();

    // 招待組織のメンバーシップには含まれない
    expect($organization->users()->whereKey($user->getKey())->exists())->toBeFalse();

    // 個人組織は生成されるが未付与 (P6/F2: 付与契機はプラン有効化時)
    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())->toBe(0);
    expect($personalOrg->signup_tickets_granted_at)->toBeNull();

    // session の invitation_token は登録確定で forget されている
    $response->assertSessionMissing('invitation_token');

    // P7: 個人組織 fallback 分岐では継続導線を張り、plan 意図なしなら org key は作らない
    expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
});

test('P7: 招待受諾成立の登録では pending を消費せず継続導線も張らない', function (): void {
    [, $token, $email, $organization] = makeInvitationWithToken('accepted-invitee@example.com');

    session([IntendedPlanResolver::PENDING_KEY => 'standard']);

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => '招待 花子',
        'email' => $email,
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
        'intended_plan' => 'starter',
    ]);

    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();
    expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
    expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();

    // pending は forget され、招待組織の org key は作られない (promote 対象が存在しない)
    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($organization)))->toBeNull();
    $response->assertSessionMissing('verify_continue_organization_id');
});
