<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Onboarding\OnboardingReturnResolver;
use Illuminate\Contracts\Session\Session;

/**
 * P7: onboarding gate の意図先 path 保持 Resolver の Unit テスト。
 * IntendedPlanResolverTest と同型。open-redirect 防御 (normalizePath) を網羅する。
 */
function onboardingReturnResolverContext(): array
{
    /** @var Session $session */
    $session = app('session.store');
    $resolver = new OnboardingReturnResolver($session);
    $org = Organization::factory()->make(['id' => 42]);

    return ['resolver' => $resolver, 'session' => $session, 'org' => $org];
}

beforeEach(function (): void {
    app('session.store')->flush();
});

describe('normalizePath (open-redirect 防御)', function (): void {
    it('allows same-origin internal paths', function (): void {
        expect(OnboardingReturnResolver::normalizePath('/projects/foo/manuals'))
            ->toBe('/projects/foo/manuals');
        expect(OnboardingReturnResolver::normalizePath('/ok'))->toBe('/ok');
    });

    it('keeps query but drops fragment', function (): void {
        expect(OnboardingReturnResolver::normalizePath('/ok?a=1'))->toBe('/ok?a=1');
        expect(OnboardingReturnResolver::normalizePath('/path#frag'))->toBe('/path');
        expect(OnboardingReturnResolver::normalizePath('/path?a=1#frag'))->toBe('/path?a=1');
    });

    it('rejects absolute URLs', function (): void {
        expect(OnboardingReturnResolver::normalizePath('http://x'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('https://x'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('https://evil.com/projects/foo'))->toBeNull();
    });

    it('rejects protocol-relative URLs', function (): void {
        expect(OnboardingReturnResolver::normalizePath('//x'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('//evil.com/path'))->toBeNull();
    });

    it('rejects backslash variants', function (): void {
        expect(OnboardingReturnResolver::normalizePath('\\x'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('/\\x'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('\\\\host'))->toBeNull();
    });

    it('rejects encoded protocol-relative / backslash evasion', function (): void {
        expect(OnboardingReturnResolver::normalizePath('/%2F%2Fx'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('%2f%2fx'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('/%5Cx'))->toBeNull();
    });

    it('rejects scheme like javascript:', function (): void {
        expect(OnboardingReturnResolver::normalizePath('javascript:alert(1)'))->toBeNull();
    });

    it('rejects user:pass@host and port-bearing values', function (): void {
        expect(OnboardingReturnResolver::normalizePath('https://user:pass@host/p'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('http://host:8080/p'))->toBeNull();
    });

    it('rejects relative (no leading slash) paths', function (): void {
        expect(OnboardingReturnResolver::normalizePath('foo'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('foo/bar'))->toBeNull();
    });

    it('rejects embedded control characters (header injection defense)', function (): void {
        expect(OnboardingReturnResolver::normalizePath("/ok\nSet-Cookie: x"))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath("/ok\ttab"))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath("/ok\x00null"))->toBeNull();
    });

    it('rejects percent-encoded control characters (decoded check)', function (): void {
        // %0a (LF) / %0d (CR) / %09 (TAB) / %00 (NUL) は rawurldecode 後に制御文字となるため
        // header/解釈差注入を防ぐべく拒否する。
        expect(OnboardingReturnResolver::normalizePath('/ok%0aSet-Cookie:%20x'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('/ok%0d%0a'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('/ok%09'))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('/ok%00'))->toBeNull();
    });

    it('rejects empty and non-string', function (): void {
        expect(OnboardingReturnResolver::normalizePath(''))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath('   '))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath(null))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath(['/x']))->toBeNull();
        expect(OnboardingReturnResolver::normalizePath(123))->toBeNull();
    });
});

describe('remember / peek / forget', function (): void {
    it('remembers and peeks a valid path', function (): void {
        ['resolver' => $resolver, 'org' => $org] = onboardingReturnResolverContext();

        $resolver->rememberForOrganization($org, '/projects/foo/manuals');

        expect($resolver->peekForOrganization($org))->toBe('/projects/foo/manuals');
    });

    it('is a no-op for invalid paths (does not clobber existing value)', function (): void {
        ['resolver' => $resolver, 'org' => $org] = onboardingReturnResolverContext();

        $resolver->rememberForOrganization($org, '/ok');
        $resolver->rememberForOrganization($org, 'https://evil.com'); // 不正 → no-op

        expect($resolver->peekForOrganization($org))->toBe('/ok');
    });

    it('forget consumes the value', function (): void {
        ['resolver' => $resolver, 'org' => $org] = onboardingReturnResolverContext();

        $resolver->rememberForOrganization($org, '/ok');
        $resolver->forgetForOrganization($org);

        expect($resolver->peekForOrganization($org))->toBeNull();
    });

    it('peek re-normalizes a tampered session value (defense in depth)', function (): void {
        ['resolver' => $resolver, 'session' => $session, 'org' => $org] = onboardingReturnResolverContext();

        // session に直接改ざん値が入っていても peek が normalizePath で再検証して弾く。
        $session->put(OnboardingReturnResolver::orgKey($org), 'https://evil.com/x');

        expect($resolver->peekForOrganization($org))->toBeNull();
    });

    it('orgKey is scoped per organization id', function (): void {
        $orgA = Organization::factory()->make(['id' => 1]);
        $orgB = Organization::factory()->make(['id' => 2]);

        expect(OnboardingReturnResolver::orgKey($orgA))->toBe('onboarding.return_to.org.1');
        expect(OnboardingReturnResolver::orgKey($orgA))->not->toBe(OnboardingReturnResolver::orgKey($orgB));
    });
});
