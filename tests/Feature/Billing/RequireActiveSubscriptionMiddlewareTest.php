<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Http\Middleware\RequireActiveSubscription;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * 課金ゲート (require-active-subscription)。
 * 判定は BillingAccess::hasActiveAccess のみ (billing entitlement):
 * - plan_code null (未契約) = fallback free プラン。**移行 OR** で許可 (P4 で削除する 1 行)
 * - それ以外は BillingAccess::state()->grantsAccess() =
 *   SubscriptionService::deriveEntitlement による判定 (P2 で判定モデルを差し替え済み)。
 *   遮断はブラウザなら billing へ redirect + 理由 flash、JSON なら 402
 *
 * P2 の判定モデル置換で結論が反転した cohort (設計の cohort 表):
 * - C: active/trialing + trial 終了 + PM 無し = **遮断** (旧: status のみ見て許可)
 * - D: past_due + (trial 未終了 or PM 有り) = **許可** (旧: past_due を一律遮断)
 * 網羅は tests/Feature/Billing/BillingAccessStateTest.php (cohort A〜I) が固定する。
 *
 * billing 系 route は gate group 外 (構造的 allowlist) で遮断中でも checkout に到達できる。
 */

const BILLING_BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';

// ── 再現テスト (F-07。実装前に fail を確認する) ──

test('Free (未契約) 組織は業務 route に到達できる (F-07 再現)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")->assertOk();
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/create")->assertOk();
});

test('Free (未契約) 組織はプロジェクトを作成できる (F-07 再現)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/projects", ['name' => 'Free プロジェクト'])
        ->assertRedirect(); // projects.show へ (billing.index でないこと)
    expect(Project::query()->where('name', 'Free プロジェクト')->exists())->toBeTrue();
});

test('Free (未契約) 組織は撮影 PWA (/app) に到達できる (F-07 再現)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app")
        ->assertRedirect(route('capture.manuals.index', ['project' => $project]));
});

// ── 有償プラン契約状態の支払い健全性 gate (fail-closed は plan_code 非 null に限定) ──
//
// 有償 org は grandfatherFreePlan: false で作る。P4 の backfill 対象は
// 「plan_code IS NULL ∧ free_plan_code IS NULL ∧ ¬entitled」に閉じており、有償 org に
// grandfather マーカーが付くことは本番では起こらないため (付くと state() の解決順 2 番目
// = ActiveFreePlan が有償の支払い健全性判定を覆い隠す非現実な fixture になる)。
// **アサーションは P4 前後で 1 件も変えていない** (DoD (3): plan_code 非 null の結論は不変)。

test('有償契約 + active/trialing は業務 route に到達できる', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: $status);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")->assertOk();
})->with(['active', 'trialing']);

test('有償契約 + past_due は業務 route に到達できる (cohort D。dunning 中も利用継続)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'past_due');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")->assertOk();
});

test('past_due の猶予中は業務 route に到達できる (cohort D は猶予の期限内で維持)', function (): void {
    config()->set('billing.payment_grace_days', 14);
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $subscription = contractPaidPlan($organization, status: 'past_due');
    $subscription->forceFill(['past_due_since' => CarbonImmutable::now()->subDays(13)])->save();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")->assertOk();
});

test('past_due の猶予切れは遮断される (AG-035 (5))', function (): void {
    config()->set('billing.payment_grace_days', 14);
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $subscription = contractPaidPlan($organization, status: 'past_due');
    $subscription->forceFill(['past_due_since' => CarbonImmutable::now()->subDays(15)])->save();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")
        ->assertRedirect(route('onboarding.checkout', ['organization' => $organization->slug]))
        ->assertSessionMissing('error');
});

test('past_due の猶予切れの JSON は 402 + 既存文言 (遮断理由の文言は増やさない)', function (): void {
    config()->set('billing.payment_grace_days', 14);
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $subscription = contractPaidPlan($organization, status: 'past_due');
    $subscription->forceFill(['past_due_since' => CarbonImmutable::now()->subDays(15)])->save();

    $this->actingAs($owner)->getJson("/organizations/{$organization->slug}/projects")
        ->assertStatus(402)
        ->assertJsonPath('message', BILLING_BLOCKED_MESSAGE);
});

test('有償契約 + 支払い不健全は billing へ redirect + 理由 flash', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: $status);

    // P4: 遮断先は billing.index から onboarding.checkout へ (manageBilling 保持者)。
    // middleware は error flash を積まない (遮断理由は着地ページが持つ = aigenba 方式)。
    // **遮断されるという結論自体は P4 前後で不変** (DoD (3))。
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")
        ->assertRedirect(route('onboarding.checkout', ['organization' => $organization->slug]))
        ->assertSessionMissing('error');
})->with(['canceled', 'incomplete', 'unpaid', 'paused']);

test('有償契約 + trial 終了 + PM 無しは遮断される (cohort C / E)', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $subscription = contractPaidPlan($organization, status: $status);
    $subscription->forceFill([
        'trial_ends_at' => CarbonImmutable::now()->subDay(),
        'has_payment_method' => false,
    ])->save();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")
        ->assertRedirect(route('onboarding.checkout', ['organization' => $organization->slug]))
        ->assertSessionMissing('error');
})->with(['active', 'trialing', 'past_due']);

test('有償契約 + subscription 行なしは fail-closed (webhook 順序逆転の防御)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $organization->forceFill(['plan_code' => 'standard'])->save(); // 行はあえて作らない

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")
        ->assertRedirect(route('onboarding.checkout', ['organization' => $organization->slug]));
});

test('有償契約 + 支払い不健全の JSON は 402 + message 固定 (flash と同一文言。非 XHR の Accept: json も含む)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'canceled');

    // getJson は Accept: application/json のみ付与 (X-Requested-With なし) =
    // 「JSON を要求する非 XHR クライアント」のケースを踏む (wantsJson 経由で 402 になること)
    $this->actingAs($owner)->getJson("/organizations/{$organization->slug}/projects")
        ->assertStatus(402)
        ->assertJsonPath('message', BILLING_BLOCKED_MESSAGE);
});

test('billing ページは遮断対象の組織でも到達できる (構造的 allowlist)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'canceled');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")->assertOk();
});

// ── 依存するデータモデル契約の固定 (plan_code 不変条件の前提) ──

test('free プランは Stripe Price を持たない (plan_code に free が入る経路がない前提の固定)', function (): void {
    $free = Plan::query()->where('code', config()->string('quota.fallback_plan'))->firstOrFail();

    // StripeWebhookProcessor::syncPlanCode は price.id → Plan 解決でのみ plan_code を set する。
    // fallback プランが Price を持たない限り、plan_code に「支払い不要プラン」が載ることはない
    expect($free->prices()->exists())->toBeFalse();
});

// ── BillingAccess 単体マトリクス ──

test('BillingAccess: plan_code null は許可の理由にならない (P4 で移行 OR 削除) / 非 null は deriveEntitlement 判定', function (): void {
    $access = app(BillingAccess::class);

    // cohort I: 未契約 + 無申告は遮断 (P4 のゲート反転。移行 OR を削除した結果)
    [$freeOrg] = createOrganizationWithOwner(grandfatherFreePlan: false);
    expect($access->hasActiveAccess($freeOrg))->toBeFalse();

    // 既存 org は backfill が free_plan_code='personal' を書くため ActiveFreePlan で許可される
    // (= 締め出しゼロ。P4 の正味の変更は「新規発生する未契約 org が遮断される」ことだけ)
    [$grandfathered] = createOrganizationWithOwner();
    expect($access->hasActiveAccess($grandfathered))->toBeTrue();

    // cohort I: 未契約 + subscription 行だけある (webhook の plan_code 同期前)。
    // entitled な行があれば state()=Subscribed で許可される (plan_code は判定に使わない)
    [$syncLagOrg] = createOrganizationWithOwner(grandfatherFreePlan: false);
    createFakeSubscription($syncLagOrg, status: 'active');
    expect($access->hasActiveAccess($syncLagOrg))->toBeTrue();

    // 有償契約状態: status マトリクス (past_due = cohort D で許可へ反転済み)
    $matrix = [
        'active' => true,
        'trialing' => true,
        'past_due' => true,
        'canceled' => false,
        'incomplete' => false,
        'unpaid' => false,
        'incomplete_expired' => false,
        'paused' => false,
    ];
    foreach ($matrix as $status => $expected) {
        [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
        contractPaidPlan($organization, status: $status);
        expect($access->hasActiveAccess($organization))->toBe($expected, "stripe_status={$status}");
    }

    // cohort C / E: trial 終了 + PM 無しは status に依らず遮断
    foreach (['active', 'trialing', 'past_due'] as $status) {
        [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
        contractPaidPlan($organization, status: $status)->forceFill([
            'trial_ends_at' => CarbonImmutable::now()->subDay(),
            'has_payment_method' => false,
        ])->save();
        expect($access->hasActiveAccess($organization))->toBeFalse("trial ended + no PM: stripe_status={$status}");
    }

    // cohort H: 有償契約状態 + 行なしは fail-closed
    [$orphan] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $orphan->forceFill(['plan_code' => 'standard'])->save();
    expect($access->hasActiveAccess($orphan))->toBeFalse();
});

/*
 * route に {organization} binding がある場合は route の org を gate 対象にする
 * (current org の暗黙参照より route が優先。org セグメント route に適用するアプリ向け)。
 */

test('route bound organization が有償不健全なら redirect される (current org より route 優先)', function (): void {
    // current org は Free (許可)、route の org は有償不健全 (両方 owner が同一メンバー)
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $gated = Organization::factory()->create(['slug' => 'gated-org']);
    $gated->users()->attach($owner);
    $owner->addRole(OrganizationRole::Member->value, $gated->laratrust_team_id);
    contractPaidPlan($gated, status: 'canceled'); // cohort G (past_due は cohort D で許可へ反転済み)

    Route::middleware(['web', 'auth', 'require-active-subscription'])
        ->get('/__gate-test/{organization:slug}', fn (Organization $organization) => response('ok'));

    // route の org では owner は Member ロール = manageBilling を持たないため、
    // P4 の分岐は billing-required 側へ倒れる (契約できる人へ連絡を促す着地ページ)。
    $this->actingAs($owner)->get('/__gate-test/gated-org')
        ->assertRedirect(route('onboarding.billing-required', ['organization' => $gated->slug]));
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
