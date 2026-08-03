<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\EmailVerificationContinuation;
use Illuminate\Contracts\Session\Session;

/**
 * P7: 登録 → verify notice ソフトゲートの継続導線 (session に org id のみ保持) の Unit テスト。
 *
 * URL を直保持せず、参照時に membership 確認 → 引数なし route('onboarding.checkout') を再構築する
 * (IDOR 防御 = セキュリティ不変条件 #2 / #3)。
 */
beforeEach(function (): void {
    app('session.store')->flush();
});

it('remember → resolveUrl が引数なしの onboarding.checkout URL を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    /** @var Session $session */
    $session = app('session.store');

    EmailVerificationContinuation::remember($session, $organization->id);

    expect(EmailVerificationContinuation::resolveUrl($owner, $session))
        ->toBe(route('onboarding.checkout'));
});

it('他組織の id を session に注入しても null (membership 確認)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $otherOrg = Organization::factory()->create();
    /** @var Session $session */
    $session = app('session.store');

    EmailVerificationContinuation::remember($session, $otherOrg->id);

    expect(EmailVerificationContinuation::resolveUrl($owner, $session))->toBeNull();
});

it('session 値が int でなければ null (値汚染防御)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    /** @var Session $session */
    $session = app('session.store');
    $session->put('verify_continue_organization_id', 'not-an-int');

    expect(EmailVerificationContinuation::resolveUrl($owner, $session))->toBeNull();
});

it('user が null なら null', function (): void {
    [$organization] = createOrganizationWithOwner();
    /** @var Session $session */
    $session = app('session.store');

    EmailVerificationContinuation::remember($session, $organization->id);

    expect(EmailVerificationContinuation::resolveUrl(null, $session))->toBeNull();
});

it('session key 不在なら null', function (): void {
    [, $owner] = createOrganizationWithOwner();
    /** @var Session $session */
    $session = app('session.store');

    expect(EmailVerificationContinuation::resolveUrl($owner, $session))->toBeNull();
});

it('forget 後は null (寿命 = remember → forget)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    /** @var Session $session */
    $session = app('session.store');

    EmailVerificationContinuation::remember($session, $organization->id);
    EmailVerificationContinuation::forget($session);

    expect(EmailVerificationContinuation::resolveUrl($owner, $session))->toBeNull();
});

it('非メンバーの user では null (cross-org read 不可)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $stranger = User::factory()->create();
    /** @var Session $session */
    $session = app('session.store');

    EmailVerificationContinuation::remember($session, $organization->id);

    expect(EmailVerificationContinuation::resolveUrl($stranger, $session))->toBeNull();
});
