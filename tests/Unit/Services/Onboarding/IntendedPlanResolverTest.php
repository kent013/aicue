<?php

declare(strict_types=1);

use App\Enums\PlanCode;
use App\Models\Organization;
use App\Services\Onboarding\IntendedPlanResolver;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * P7: 料金表 → 登録 → Onboarding/Checkout の plan 引き継ぎ用 Resolver の Unit テスト。
 *
 * - pending 系の「常に書き換える」規約 (= stale 防止)
 * - org-scoped 系の「不在は no-op」規約 (= リロード耐性)
 * - promote (pending → org-scoped)
 * - normalize の Enterprise 除外 (Personal は普通の契約フローに露出する選択肢のため受理) + trim/strtolower
 */
function intendedPlanResolverWithRequest(Request $request): array
{
    $session = $request->session();
    /** @var Session $session */
    $resolver = new IntendedPlanResolver($session);

    return ['resolver' => $resolver, 'session' => $session, 'request' => $request];
}

function intendedPlanRequestWithQuery(array $query): Request
{
    $request = Request::create('/test', 'GET', $query);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

beforeEach(function (): void {
    app('session.store')->flush();
});

describe('normalizeRaw', function (): void {
    it('normalizes starter/standard/business to PlanCode', function (): void {
        expect(IntendedPlanResolver::normalizeRaw('starter'))->toBe(PlanCode::Starter);
        expect(IntendedPlanResolver::normalizeRaw('standard'))->toBe(PlanCode::Standard);
        expect(IntendedPlanResolver::normalizeRaw('business'))->toBe(PlanCode::Business);
    });

    it('returns null for enterprise (excluded from contract flow)', function (): void {
        expect(IntendedPlanResolver::normalizeRaw('enterprise'))->toBeNull();
    });

    it('normalizes personal (普通の契約フローに露出する選択肢のため受理)', function (): void {
        expect(IntendedPlanResolver::normalizeRaw('personal'))->toBe(PlanCode::Personal);
    });

    it('returns null for invalid string', function (): void {
        expect(IntendedPlanResolver::normalizeRaw('foo'))->toBeNull();
        expect(IntendedPlanResolver::normalizeRaw(''))->toBeNull();
    });

    it('handles uppercase and surrounding whitespace', function (): void {
        expect(IntendedPlanResolver::normalizeRaw('STANDARD'))->toBe(PlanCode::Standard);
        expect(IntendedPlanResolver::normalizeRaw(' Starter '))->toBe(PlanCode::Starter);
        expect(IntendedPlanResolver::normalizeRaw('  Business'))->toBe(PlanCode::Business);
    });

    it('returns null for non-string values', function (): void {
        expect(IntendedPlanResolver::normalizeRaw(null))->toBeNull();
        expect(IntendedPlanResolver::normalizeRaw(123))->toBeNull();
        expect(IntendedPlanResolver::normalizeRaw(['standard']))->toBeNull();
        expect(IntendedPlanResolver::normalizeRaw(false))->toBeNull();
    });
});

describe('rememberPendingFromQuery (always-overwrite contract)', function (): void {
    it('puts pending when ?plan=standard', function (): void {
        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'standard']));
        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);

        expect($ctx['session']->get(IntendedPlanResolver::PENDING_KEY))->toBe('standard');
    });

    it('forgets pending when ?plan=enterprise', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');

        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'enterprise']));
        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });

    it('forgets pending when ?plan=foo (invalid)', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');

        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'foo']));
        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });

    it('forgets pending when ?plan= empty string', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');

        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => '']));
        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });

    it('forgets stale pending when ?plan key is absent (fresh-state start)', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');

        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery([]));
        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });
});

describe('rememberPendingFromForm (always-overwrite contract)', function (): void {
    it('puts pending when intended_plan=standard', function (): void {
        $session = app('session.store');
        $resolver = new IntendedPlanResolver($session);

        $resolver->rememberPendingFromForm(['intended_plan' => 'standard']);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBe('standard');
    });

    it('forgets stale pending when intended_plan key is absent', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
        $resolver = new IntendedPlanResolver($session);

        $resolver->rememberPendingFromForm([]);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });

    it('forgets pending on explicit null', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
        $resolver = new IntendedPlanResolver($session);

        $resolver->rememberPendingFromForm(['intended_plan' => null]);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });

    it('forgets pending on tampered array value', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
        $resolver = new IntendedPlanResolver($session);

        $resolver->rememberPendingFromForm(['intended_plan' => ['standard']]);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });

    it('forgets pending on enterprise', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
        $resolver = new IntendedPlanResolver($session);

        $resolver->rememberPendingFromForm(['intended_plan' => 'enterprise']);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });
});

describe('peekPending / forgetPending', function (): void {
    it('peekPending reads without consuming', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'standard');
        $resolver = new IntendedPlanResolver($session);

        expect($resolver->peekPending())->toBe(PlanCode::Standard);
        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBe('standard');
    });

    it('peekPending returns null when key absent', function (): void {
        $resolver = new IntendedPlanResolver(app('session.store'));

        expect($resolver->peekPending())->toBeNull();
    });

    it('forgetPending clears the key', function (): void {
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'standard');
        $resolver = new IntendedPlanResolver($session);

        $resolver->forgetPending();

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });
});

describe('rememberForOrganizationFromQuery (no-op on absence)', function (): void {
    it('puts org-scoped on ?plan=standard', function (): void {
        $org = Organization::factory()->create();
        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'standard']));
        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $org);

        expect($ctx['session']->get(IntendedPlanResolver::orgKey($org)))->toBe('standard');
    });

    it('preserves session when ?plan absent (reload resilience)', function (): void {
        $org = Organization::factory()->create();
        $session = app('session.store');
        $session->put(IntendedPlanResolver::orgKey($org), 'standard');

        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery([]));
        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $org);

        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBe('standard');
    });

    it('forgets org-scoped on enterprise', function (): void {
        $org = Organization::factory()->create();
        $session = app('session.store');
        $session->put(IntendedPlanResolver::orgKey($org), 'standard');

        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'enterprise']));
        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $org);

        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBeNull();
    });

    it('forgets org-scoped on invalid plan', function (): void {
        $org = Organization::factory()->create();
        $session = app('session.store');
        $session->put(IntendedPlanResolver::orgKey($org), 'standard');

        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'foo']));
        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $org);

        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBeNull();
    });

    it('isolates org keys (write to A does not affect B)', function (): void {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $session = app('session.store');
        $session->put(IntendedPlanResolver::orgKey($orgB), 'business');

        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'standard']));
        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $orgA);

        expect($session->get(IntendedPlanResolver::orgKey($orgA)))->toBe('standard');
        expect($session->get(IntendedPlanResolver::orgKey($orgB)))->toBe('business');
    });

    it('orgKey has the documented shape', function (): void {
        $org = Organization::factory()->create();

        expect(IntendedPlanResolver::orgKey($org))->toBe("onboarding.intended_plan.org.{$org->id}");
    });
});

describe('promotePendingToOrganization', function (): void {
    it('moves pending value to org-scoped and clears pending', function (): void {
        $org = Organization::factory()->create();
        $session = app('session.store');
        $session->put(IntendedPlanResolver::PENDING_KEY, 'standard');
        $resolver = new IntendedPlanResolver($session);

        $resolver->promotePendingToOrganization($org);

        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBe('standard');
        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });

    it('no-ops when pending is absent (org key untouched)', function (): void {
        $org = Organization::factory()->create();
        $session = app('session.store');
        $resolver = new IntendedPlanResolver($session);

        $resolver->promotePendingToOrganization($org);

        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBeNull();
        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    });

    it('clears pending even when value is enterprise (no promote to org)', function (): void {
        $org = Organization::factory()->create();
        $session = app('session.store');
        // 直接 enterprise を session に入れた (= 通常 Resolver 経由では起こらないが防御確認)
        $session->put(IntendedPlanResolver::PENDING_KEY, 'enterprise');
        $resolver = new IntendedPlanResolver($session);

        $resolver->promotePendingToOrganization($org);

        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBeNull();
    });
});
