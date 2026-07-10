<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Http\Middleware\RequireActiveSubscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * 課金ゲート (require-active-subscription)。
 * 判定は BillingAccess::hasActiveAccess のみ (subscription('default') が active/trialing)。
 * 未契約はブラウザなら billing へ redirect、JSON なら 402。billing 系 route は
 * gate group 外 (構造的 allowlist) で未契約でも checkout に到達できる。
 */

test('未契約の組織は業務 route から billing へ redirect される', function (): void {
    [, $owner] = createOrganizationWithOwner(subscribed: false);

    $this->actingAs($owner)->get('/projects')
        ->assertRedirect(route('billing.index'));
});

test('active subscription の組織は業務 route に到達できる', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects')->assertOk();
});

test('trialing subscription の組織は業務 route に到達できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(subscribed: false);
    createFakeSubscription($organization, status: 'trialing');

    $this->actingAs($owner)->get('/projects')->assertOk();
});

test('past_due / canceled の subscription では業務 route から billing へ redirect される', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner(subscribed: false);
    createFakeSubscription($organization, status: $status);

    $this->actingAs($owner)->get('/projects')
        ->assertRedirect(route('billing.index'));
})->with(['past_due', 'canceled', 'incomplete', 'unpaid']);

test('未契約の JSON リクエストは 402 Payment Required', function (): void {
    [, $owner] = createOrganizationWithOwner(subscribed: false);

    $this->actingAs($owner)->getJson('/projects')->assertStatus(402);
});

test('billing ページは未契約でも到達できる (構造的 allowlist)', function (): void {
    [, $owner] = createOrganizationWithOwner(subscribed: false);

    $this->actingAs($owner)->get('/billing')->assertOk();
});

test('BillingAccess は active/trialing のみ許可する (それ以外と未契約は fail-closed)', function (): void {
    $access = app(BillingAccess::class);

    // 未契約
    [$noSub] = createOrganizationWithOwner(subscribed: false);
    expect($access->hasActiveAccess($noSub))->toBeFalse();

    foreach (['active' => true, 'trialing' => true, 'past_due' => false, 'canceled' => false, 'incomplete' => false] as $status => $expected) {
        [$organization] = createOrganizationWithOwner(subscribed: false);
        createFakeSubscription($organization, status: $status);
        expect($access->hasActiveAccess($organization))->toBe($expected, "stripe_status={$status}");
    }
});

/*
 * route に {organization} binding がある場合は route の org を gate 対象にする
 * (current org の暗黙参照より route が優先。org セグメント route に適用するアプリ向け)。
 */

test('route bound organization が未契約なら redirect される (current org より route 優先)', function (): void {
    // current org は契約済み、route の org は未契約 (両方 owner が同一メンバー)
    [, $owner] = createOrganizationWithOwner();
    $gated = Organization::factory()->create(['slug' => 'gated-org']);
    $gated->users()->attach($owner);
    $owner->addRole(OrganizationRole::Member->value, $gated->laratrust_team_id);

    Route::middleware(['web', 'auth', 'require-active-subscription'])
        ->get('/__gate-test/{organization:slug}', fn (Organization $organization) => response('ok'));

    $this->actingAs($owner)->get('/__gate-test/gated-org')
        ->assertRedirect(route('billing.index'));
});

test('非メンバーが binder を通過しても middleware が 404 に倒す (binder 回帰の defense-in-depth)', function (): void {
    // MembershipScopedOrganizationBinder を経由しない直接呼び出しで、binder 回帰
    // (非メンバー org が route param に載る) を再現する。存在秘匿のため 403 ではなく 404。
    [$organization] = createOrganizationWithOwner(subscribed: false);
    $outsider = User::factory()->create();

    $request = Request::create('/__direct', 'GET');
    $request->setUserResolver(fn (): User => $outsider);
    $route = new RoutingRoute(['GET'], '/__direct/{organization}', []);
    $route->bind($request);
    $route->setParameter('organization', $organization);
    $request->setRouteResolver(fn (): RoutingRoute => $route);

    $middleware = app(RequireActiveSubscription::class);

    expect(fn () => $middleware->handle($request, fn (): never => throw new LogicException('next に到達してはならない')))
        ->toThrow(NotFoundHttpException::class);
});
