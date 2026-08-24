<?php

declare(strict_types=1);

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Onboarding\IntendedPlanResolver;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * P7: 料金表 → `/register?plan=` の plan 引き継ぎ HTTP テスト。
 *
 * HTTP は CreateNewUser → RegisterResponse まで一気通貫で走るため、テスト完了時点では:
 *   - pending key (`onboarding.intended_plan.pending`) は **forget** されている
 *   - org-scoped key (`onboarding.intended_plan.org.{personal_org_id}`) に promote されている
 * pending 単独の振る舞いは IntendedPlanResolver Unit テストで網羅済。
 */
beforeEach(function (): void {
    // Password::defaults() の uncompromised HIBP 通信を抑止する。
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);

    $this->validPayload = fn (array $overrides = []): array => array_merge([
        'name' => 'Plan Tester',
        'email' => 'plan-'.uniqid().'@example.com',
        'password' => 'CorrectHorse9Battery',
        'terms_accepted' => '1',
    ], $overrides);
});

// --- GET /register の intendedPlan prop (?plan= の allowlist 照合) ---

test('GET /register?plan={code} は allowlist 照合済みの intendedPlan prop を返す', function (string $raw, ?string $expected): void {
    $this->get('/register?plan='.$raw)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Register')
            ->where('intendedPlan', $expected));
})->with([
    'personal' => ['personal', 'personal'],
    'starter' => ['starter', 'starter'],
    'standard' => ['standard', 'standard'],
    'business' => ['business', 'business'],
    // enterprise は enum として有効だが intent としては採用しない (お問い合わせ営業導線)
    'enterprise は null' => ['enterprise', null],
    '未知値は null' => ['foo', null],
    '空文字は null' => ['', null],
    '大文字・空白は正規化' => ['%20Starter%20', 'starter'],
]);

test('GET /register (plan なし) の intendedPlan prop は null', function (): void {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Register')
            ->where('intendedPlan', null));
});

test('GET /register?plan[]=standard (配列の改ざん) でも 500 にならず intendedPlan は null', function (): void {
    $this->get('/register?plan[]=standard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('intendedPlan', null));
});

test('招待経由 GET /register の Cache-Control: no-store は ?plan= を足しても非退行', function (): void {
    [$organization] = createOrganizationWithOwner();
    [, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => 'invited-plan@example.com']);

    $response = $this->withSession(['invitation_token' => $token])->get('/register?plan=standard');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invitationEmail', 'invited-plan@example.com')
            ->where('intendedPlan', 'standard'));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

// --- POST /register の pending → org-scoped promote ---

test('POST /register intended_plan=starter は pending を消費し org-scoped に promote する', function (): void {
    $payload = ($this->validPayload)(['intended_plan' => 'starter']);

    $response = $this->post('/register', $payload);

    $response->assertRedirect(route('verification.notice'));
    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
    $personalOrg = $user->organizations()->firstOrFail();

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBe('starter');
});

test('POST /register intended_plan=enterprise は promote しない (org key 不在)', function (): void {
    $payload = ($this->validPayload)(['intended_plan' => 'enterprise']);

    $response = $this->post('/register', $payload);

    $response->assertRedirect(route('verification.notice'));
    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
    $personalOrg = $user->organizations()->firstOrFail();

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
});

test('POST /register intended_plan=foo (無効値) は 422 にならず promote もしない', function (): void {
    $payload = ($this->validPayload)(['intended_plan' => 'foo']);

    $response = $this->post('/register', $payload);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('verification.notice'));
    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
    $personalOrg = $user->organizations()->firstOrFail();

    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
});

test('POST /register で intended_plan キー不在なら stale pending は forget され promote されない', function (): void {
    session([IntendedPlanResolver::PENDING_KEY => 'business']);

    $payload = ($this->validPayload)(); // intended_plan キー不在

    $response = $this->post('/register', $payload);

    $response->assertRedirect(route('verification.notice'));
    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
    $personalOrg = $user->organizations()->firstOrFail();

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
});

test('POST /register intended_plan=null は stale pending を消し promote しない', function (): void {
    session([IntendedPlanResolver::PENDING_KEY => 'business']);

    $payload = ($this->validPayload)(['intended_plan' => null]);

    $response = $this->post('/register', $payload);

    $response->assertRedirect(route('verification.notice'));
    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
    $personalOrg = $user->organizations()->firstOrFail();

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
});

test('POST /register intended_plan が配列 (改ざん) でも 422 にならず pending は forget される', function (): void {
    session([IntendedPlanResolver::PENDING_KEY => 'business']);
    $payload = ($this->validPayload)(['intended_plan' => ['standard']]);

    $response = $this->post('/register', $payload);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('verification.notice'));
    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
    $personalOrg = $user->organizations()->firstOrFail();

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
});

// --- 招待経由との排他契約 (最重要) ---

test('招待受諾成立の登録は個人組織を作らず pending も continuation も残さない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $email = 'invited-conflict@example.com';
    [, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => $email]);

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => '招待 太郎',
        'email' => $email,
        'password' => 'CorrectHorse9Battery',
        'terms_accepted' => '1',
        'intended_plan' => 'starter',
    ]);

    $response->assertRedirect(route('verification.notice'));

    $user = User::query()->whereBlind('email', 'email_index', $email)->firstOrFail();

    // 招待組織へ参加し、初期組織は作られない (所属は招待組織の 1 件だけ)
    expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
    expect($user->organizations()->count())->toBe(1);

    // pending は forget され org key は一切作られない
    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($organization)))->toBeNull();

    // continuation を張らない
    $response->assertSessionMissing('verify_continue_organization_id');
});
