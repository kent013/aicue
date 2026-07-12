<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Http\Middleware\RequireActiveSubscription;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * 課金ゲート (require-active-subscription)。
 * 判定は BillingAccess::hasActiveAccess のみ (billing entitlement):
 * - plan_code null (未契約) = fallback free プラン。支払い不要 tier として許可
 * - plan_code 非 null = 有償プラン契約状態。subscription('default') が active/trialing の
 *   ときのみ許可 (支払い不健全はブラウザなら billing へ redirect + 理由 flash、JSON なら 402)
 * billing 系 route は gate group 外 (構造的 allowlist) で遮断中でも checkout に到達できる。
 */

const BILLING_BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';

// ── 再現テスト (F-07。実装前に fail を確認する) ──

test('Free (未契約) 組織は業務 route に到達できる (F-07 再現)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects')->assertOk();
    $this->actingAs($owner)->get('/projects/create')->assertOk();
});

test('Free (未契約) 組織はプロジェクトを作成できる (F-07 再現)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post('/projects', ['name' => 'Free プロジェクト'])
        ->assertRedirect(); // projects.show へ (billing.index でないこと)
    expect(Project::query()->where('name', 'Free プロジェクト')->exists())->toBeTrue();
});

test('Free (未契約) 組織は撮影 PWA (/app) に到達できる (F-07 再現)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/app')
        ->assertRedirect(route('capture.manuals.index', ['project' => $project]));
});

// ── 有償プラン契約状態の支払い健全性 gate (fail-closed は plan_code 非 null に限定) ──

test('有償契約 + active/trialing は業務 route に到達できる', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: $status);

    $this->actingAs($owner)->get('/projects')->assertOk();
})->with(['active', 'trialing']);

test('有償契約 + 支払い不健全は billing へ redirect + 理由 flash', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: $status);

    $this->actingAs($owner)->get('/projects')
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('error', BILLING_BLOCKED_MESSAGE);
})->with(['past_due', 'canceled', 'incomplete', 'unpaid']);

test('有償契約 + subscription 行なしは fail-closed (webhook 順序逆転の防御)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['plan_code' => 'standard'])->save(); // 行はあえて作らない

    $this->actingAs($owner)->get('/projects')
        ->assertRedirect(route('billing.index'));
});

test('有償契約 + 支払い不健全の JSON は 402 + message 固定 (flash と同一文言。非 XHR の Accept: json も含む)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'past_due');

    // getJson は Accept: application/json のみ付与 (X-Requested-With なし) =
    // 「JSON を要求する非 XHR クライアント」のケースを踏む (wantsJson 経由で 402 になること)
    $this->actingAs($owner)->getJson('/projects')
        ->assertStatus(402)
        ->assertJsonPath('message', BILLING_BLOCKED_MESSAGE);
});

test('billing ページは遮断対象の組織でも到達できる (構造的 allowlist)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'past_due');

    $this->actingAs($owner)->get('/billing')->assertOk();
});

// ── 依存するデータモデル契約の固定 (plan_code 不変条件の前提) ──

test('free プランは Stripe Price を持たない (plan_code に free が入る経路がない前提の固定)', function (): void {
    $free = Plan::query()->where('code', config()->string('quota.fallback_plan'))->firstOrFail();

    // StripeWebhookProcessor::syncPlanCode は price.id → Plan 解決でのみ plan_code を set する。
    // fallback プランが Price を持たない限り、plan_code に「支払い不要プラン」が載ることはない
    expect($free->prices()->exists())->toBeFalse();
});

// ── BillingAccess 単体マトリクス ──

test('BillingAccess: plan_code null は常に許可、非 null は active/trialing のみ許可', function (): void {
    $access = app(BillingAccess::class);

    // 未契約 (free tier)
    [$freeOrg] = createOrganizationWithOwner();
    expect($access->hasActiveAccess($freeOrg))->toBeTrue();

    // 未契約 + subscription 行だけある (webhook の plan_code 同期前) も許可 (fail-open は free 相当のみ)
    [$syncLagOrg] = createOrganizationWithOwner();
    createFakeSubscription($syncLagOrg, status: 'active');
    expect($access->hasActiveAccess($syncLagOrg))->toBeTrue();

    // 有償契約状態: status マトリクス
    foreach (['active' => true, 'trialing' => true, 'past_due' => false, 'canceled' => false, 'incomplete' => false] as $status => $expected) {
        [$organization] = createOrganizationWithOwner();
        contractPaidPlan($organization, status: $status);
        expect($access->hasActiveAccess($organization))->toBe($expected, "stripe_status={$status}");
    }

    // 有償契約状態 + 行なし: fail-closed
    [$orphan] = createOrganizationWithOwner();
    $orphan->forceFill(['plan_code' => 'standard'])->save();
    expect($access->hasActiveAccess($orphan))->toBeFalse();
});

/*
 * route に {organization} binding がある場合は route の org を gate 対象にする
 * (current org の暗黙参照より route が優先。org セグメント route に適用するアプリ向け)。
 */

test('route bound organization が有償不健全なら redirect される (current org より route 優先)', function (): void {
    // current org は Free (許可)、route の org は有償不健全 (両方 owner が同一メンバー)
    [, $owner] = createOrganizationWithOwner();
    $gated = Organization::factory()->create(['slug' => 'gated-org']);
    $gated->users()->attach($owner);
    $owner->addRole(OrganizationRole::Member->value, $gated->laratrust_team_id);
    contractPaidPlan($gated, status: 'past_due');

    Route::middleware(['web', 'auth', 'require-active-subscription'])
        ->get('/__gate-test/{organization:slug}', fn (Organization $organization) => response('ok'));

    $this->actingAs($owner)->get('/__gate-test/gated-org')
        ->assertRedirect(route('billing.index'));
});

test('非メンバーが binder を通過しても middleware が 404 に倒す (binder 回帰の defense-in-depth)', function (): void {
    // MembershipScopedOrganizationBinder を経由しない直接呼び出しで、binder 回帰
    // (非メンバー org が route param に載る) を再現する。存在秘匿のため 403 ではなく 404。
    [$organization] = createOrganizationWithOwner();
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
