<?php

declare(strict_types=1);

use App\Enums\Auth\EmailVerificationGateContext;

/*
 * gate context enum の文言と fallback route 名の不変条件 (純粋ロジック)。
 * fallback route が実在することの検証は app bootstrap が要るため Feature 側
 * (EmailVerificationGateTest) で行う。
 */

it('returns context-specific block messages', function (): void {
    expect(EmailVerificationGateContext::Invite->message())
        ->toContain('招待')
        ->and(EmailVerificationGateContext::OrganizationStore->message())
        ->toContain('組織');
});

it('falls back to verification.notice for both contexts', function (): void {
    expect(EmailVerificationGateContext::Invite->fallbackRouteName())->toBe('verification.notice')
        ->and(EmailVerificationGateContext::OrganizationStore->fallbackRouteName())->toBe('verification.notice');
});

it('resolves known context strings via tryFrom and rejects unknown ones', function (): void {
    expect(EmailVerificationGateContext::tryFrom('invite'))->toBe(EmailVerificationGateContext::Invite)
        ->and(EmailVerificationGateContext::tryFrom('organization-store'))->toBe(EmailVerificationGateContext::OrganizationStore)
        ->and(EmailVerificationGateContext::tryFrom('bogus'))->toBeNull();
});
