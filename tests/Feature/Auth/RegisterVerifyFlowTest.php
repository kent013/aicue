<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

/**
 * P7: メール/パスワード登録 → verification.notice ソフトゲート。
 *
 * EmailVerificationContinuation が session に personal org id を保持し、
 * /email/verify の二次 CTA (continueUrl) と verify 完了後の checkout 復帰を支える。
 * session 値は membership 確認 (relation 経由 fetch) を通らない限り URL 化されない。
 */
beforeEach(function (): void {
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);

    $this->validPayload = fn (array $overrides = []): array => array_merge([
        'name' => 'Verify Flow Tester',
        'email' => 'verify-flow-'.uniqid().'@example.com',
        'password' => 'CorrectHorse9Battery',
        'terms_accepted' => '1',
    ], $overrides);

    // Fortify 標準 verify controller が踏む signed URL を再現する。
    $this->verificationUrlFor = fn (User $user): string => URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
    );
});

test('登録 POST は verification.notice へ redirect し personal org id を session に保持する', function (): void {
    Notification::fake();
    $payload = ($this->validPayload)();

    $response = $this->post('/register', $payload);

    $response->assertRedirect(route('verification.notice'));

    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
    expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
});

test('登録後の /email/verify GET は continueUrl に onboarding.checkout を返す', function (): void {
    Notification::fake();
    $payload = ($this->validPayload)();
    $this->post('/register', $payload);

    $this->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/VerifyEmail')
            ->where('continueUrl', route('onboarding.checkout')));
});

test('continuation なしで /email/verify を直接開くと continueUrl は null', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/VerifyEmail')
            ->where('continueUrl', null));
});

test('session に他人の organization id が混入しても continueUrl は null (membership 確認)', function (): void {
    $otherOrg = Organization::factory()->create();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->withSession(['verify_continue_organization_id' => $otherOrg->id])
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('continueUrl', null));
});

test('session 値が int でない場合は continueUrl は null (値汚染防御)', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->withSession(['verify_continue_organization_id' => 'not-an-int'])
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('continueUrl', null));
});

test('verify 完了で onboarding.checkout へ redirect し continuation が消える', function (): void {
    Notification::fake();
    $payload = ($this->validPayload)();
    $this->post('/register', $payload);

    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();

    $response = $this->get(($this->verificationUrlFor)($user));

    $response->assertRedirect(route('onboarding.checkout'));
    $response->assertSessionMissing('verify_continue_organization_id');
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});

test('continuation なしの verify 完了は Fortify 既定と同値 (/dashboard?verified=1)', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(($this->verificationUrlFor)($user));

    $response->assertRedirect(config()->string('fortify.home').'?verified=1');
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});
