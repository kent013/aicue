<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

/**
 * P7: メール/パスワード登録 → verification.notice。
 *
 * EmailVerificationContinuation が session に personal org id を保持し、
 * verify 完了後の checkout 復帰を支える。
 * session 値は membership 確認 (relation 経由 fetch) を通らない限り継続として成立しない。
 *
 * 認証**前**に onboarding.checkout へ進む CTA は出さない (bug-hunt F-2-01)。
 * route が ['auth','verified'] 配下 = 未認証は必ず差し戻されるため、画面へは
 * URL を渡さず「継続が実在するか」だけを continuesToCheckout として渡す。
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

test('登録後の /email/verify GET は continuesToCheckout=true を返し URL を渡さない', function (): void {
    Notification::fake();
    $payload = ($this->validPayload)();
    $this->post('/register', $payload);

    $this->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/VerifyEmail')
            ->where('continuesToCheckout', true)
            ->missing('continueUrl'));
});

test('continuation なしで /email/verify を直接開くと continuesToCheckout は false', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/VerifyEmail')
            ->where('continuesToCheckout', false)
            ->missing('continueUrl'));
});

test('session に他人の organization id が混入しても continuesToCheckout は false (membership 確認)', function (): void {
    $otherOrg = Organization::factory()->create();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->withSession(['verify_continue_organization_id' => $otherOrg->id])
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('continuesToCheckout', false)
            ->missing('continueUrl'));
});

test('session 値が int でない場合は continuesToCheckout は false (値汚染防御)', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->withSession(['verify_continue_organization_id' => 'not-an-int'])
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('continuesToCheckout', false)
            ->missing('continueUrl'));
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

test('continuation: ActiveFreePlan の非管理メンバーは verify 完了後 onboarding.checkout 経由で dashboard に着地する (Q-2-01)', function (): void {
    // ActiveFreePlan (free_plan_code=personal) の既契約 org を用意する
    // (hasActiveAccess=true かつ課金は非管理メンバーの管掌外)。
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $organization->forceFill(['plan_code' => 'standard'])->save();
    contractPaidPlan($organization, status: 'canceled');
    $organization->forceFill([
        'free_plan_code' => 'personal',
        'free_plan_activated_at' => now(),
        'personal_declared_at' => now(),
        'personal_declared_by_user_id' => $owner->getKey(),
    ])->save();

    // 非管理メンバー (unverified) を当該 org に所属させ、current org / continuation を張る。
    $member = User::factory()->unverified()->create();
    $organization->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($member)
        ->withSession(['verify_continue_organization_id' => $organization->id])
        ->get(($this->verificationUrlFor)($member));

    // 第一段: verify 完了は onboarding.checkout へ redirect し continuation が消える。
    $response->assertRedirect(route('onboarding.checkout'));
    $response->assertSessionMissing('verify_continue_organization_id');
    expect($member->fresh()?->hasVerifiedEmail())->toBeTrue();

    // 第二段: onboarding.checkout は非管理メンバーを dashboard へ寄せる (中間ホップを保証)。
    $this->actingAs($member->fresh())->get(route('onboarding.checkout'))
        ->assertRedirect(route('dashboard'));
});

test('continuation なしの verify 完了は Fortify 既定と同値 (/dashboard?verified=1)', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(($this->verificationUrlFor)($user));

    $response->assertRedirect(config()->string('fortify.home').'?verified=1');
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});
