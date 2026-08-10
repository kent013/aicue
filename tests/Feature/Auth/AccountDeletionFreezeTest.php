<?php

declare(strict_types=1);

use App\Enums\Account\AccountDeletionFreezeAllowance;
use App\Enums\OrganizationRole;
use App\Http\Middleware\EnsureAccountNotPendingDeletion;
use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Notifications\Account\AccountDeletionRequestedNotification;
use App\Services\Billing\TicketLedgerService;
use App\Services\Organization\OrganizationMembershipService;
use Database\Factories\UserFactory;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;

/*
 * 退会予約中の**凍結** (deny-by-default) の振る舞い固定 (T142 / PR-B の B4)。
 *
 * 構造 (母集団 `U` と allowlist `A` の一致・enum の形式) は
 * tests/Architecture/AccountDeletionFreezeRouteGateTest.php が固定する。
 * 本テストは **実 HTTP** で「遮断されること / 到達できること」を測る
 * (Architecture lane は DB を持てないため 2 本立てにしている)。
 *
 * 凍結の契約:
 *   - 遮断は **302 → /settings** (403 で突き放さない = 行き先のない詰みを作らない)
 *   - JSON/XHR は **409 Conflict** (課金ゲートの 402 とは別事由)
 *   - **認証回復と離脱の手段は凍結しない** (ログアウトは group の外)
 *   - **即時削除 (settings.account.destroy) は遮断する** (30 日猶予の迂回口を作らない)
 */

/** 予約中のユーザーを作り、認証主体として使える形で返す。 */
function frozenUser(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
    // actingAs は in-memory インスタンスを認証主体にするため DB の予約状態を読み直す
    $owner->refresh();

    return [$organization, $owner];
}

/**
 * 凍結母集団のうち **route parameter を持たない** route を [名前 => [method, uri]] で返す。
 *
 * ★parameter を持つ route を sweep から外すのは、ダミー id を与えるとテナント境界 404 が
 *   先に閉じる (それが正しい順序である) ため。順序そのものは下の「404 が 302 より前」と
 *   TenantBoundaryOrderingTest が固定する。
 *
 * @return array<string, array{string, string}>
 */
function freezeSweepTargets(): array
{
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    $targets = [];
    /** @var RoutingRoute $route */
    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();
        if (! in_array(EnsureAccountNotPendingDeletion::class, $middleware, true)
            && ! in_array('not-pending-deletion', $middleware, true)) {
            continue;
        }
        $name = $route->getName();
        if ($name === null || $route->parameterNames() !== []) {
            continue;
        }
        if (in_array($name, AccountDeletionFreezeAllowance::values(), true)) {
            continue;
        }
        $method = collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD');
        if (! is_string($method)) {
            continue;
        }
        $targets[$name] = [$method, '/'.ltrim($route->uri(), '/')];
    }

    return $targets;
}

test('凍結母集団 U − A の parameterless route はすべて /settings へ 302 する', function (): void {
    [, $owner] = frozenUser();
    $targets = freezeSweepTargets();

    expect(count($targets))->toBeGreaterThan(20); // 空振り防止 (sweep が 0 件でも緑にならない)

    $violations = [];
    foreach ($targets as $name => [$method, $uri]) {
        $response = $this->actingAs($owner)->call($method, $uri);
        if ($response->getStatusCode() !== 302 || $response->headers->get('Location') !== url('/settings')) {
            $violations[] = "{$name} ({$method} {$uri}): "
                .$response->getStatusCode().' '.(string) $response->headers->get('Location');
        }
    }

    expect($violations)->toBe([],
        '凍結対象の route が /settings へ遮断されていません。'
        .PHP_EOL.implode(PHP_EOL, $violations));

    // ★sweep を通した限り、退会通知**以外**の job は 1 件も投入されない。
    //   **Queue::fake() は使わず実 jobs 表**を payload (displayName) まで見て判定する
    //   (jobs 全体の件数だと退会予約の通知 job そのもので汚染される)。
    expect(queuedJobClassesExceptDeletionNotice())->toBe([]);
});

/**
 * 実 `jobs` 表に積まれた job のクラス名一覧から、**退会予約の通知 job だけ**を除いたもの。
 *
 * ★名前どおり「退会通知**以外**の queued class」であって「業務ジョブ」の一般的な分類ではない。
 *   凍結中に新しい非業務通知が増えたらこの検査は赤くなる (そのときは除外を増やすのではなく、
 *   「凍結中にその通知が積まれてよいのか」を先に考えること)。
 * ★`Queue::fake()` を使わないのはドメイン規約 11 の作法 (fake は enqueueUsing を通らない)。
 *
 * @return list<string>
 */
function queuedJobClassesExceptDeletionNotice(): array
{
    $classes = [];
    foreach (DB::table('jobs')->pluck('payload') as $payload) {
        $decoded = json_decode((string) $payload, true);
        $name = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;
        if (! is_string($name) || $name === AccountDeletionRequestedNotification::class) {
            continue; // 退会予約そのものの通知 job は業務ジョブではない
        }
        $classes[] = $name;
    }
    sort($classes);

    return array_values(array_unique($classes));
}

test('予約中は自組織の {project} を持つ業務 route も遮断される (parameter 付きの代表 route)', function (): void {
    [$organization, $owner] = frozenUser();
    $project = Project::factory()->forOrganization($organization)->create();

    // ★parameterless sweep では測れない「有効な自組織 parameter を持つ route」を代表 3 本で固定する
    $this->actingAs($owner)->get("/projects/{$project->id}")->assertRedirect('/settings');
    $this->actingAs($owner)->get("/projects/{$project->id}/edit")->assertRedirect('/settings');
    $this->actingAs($owner)->patch("/projects/{$project->id}", ['name' => 'x'])->assertRedirect('/settings');
});

test('予約中は解析トリガー (チケット予約に至る業務経路) が遮断され、自動チャージ job も積まれない', function (): void {
    [$organization, $owner] = frozenUser();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    SourceDocument::factory()->forManual($manual)->create();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');

    // AutoRechargeTriggerJob を dispatch するのは TicketLedgerService::reserve() だけで、
    // reserve() を呼ぶのは解析・レンダ等の業務フローである。その入口が凍結で止まることを実測する。
    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertStatus(409);

    expect(AnalysisJob::query()->count())->toBe(0);
    // 名指しの禁止対象 + 「退会通知以外は 0 件」の 2 段で見る
    expect(queuedJobClassesExceptDeletionNotice())->not->toContain(AutoRechargeTriggerJob::class);
    expect(queuedJobClassesExceptDeletionNotice())->toBe([]);
});

test('予約中でも /settings は 200 で、そこから取消できる', function (): void {
    [, $owner] = frozenUser();

    $this->actingAs($owner)->get('/settings')->assertOk();

    $this->actingAs($owner)->from('/settings')
        ->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings');

    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
});

test('予約中は即時削除が遮断され、取消してからなら削除できる', function (): void {
    [, $owner] = frozenUser();

    // ★allowlist に settings.account.destroy を足すとこのテストが赤くなる (M17)
    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->delete('/settings/account')
        ->assertRedirect('/settings');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();

    $this->actingAs($owner)->delete('/settings/account/deletion-request');
    $owner->refresh();

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->delete('/settings/account')
        ->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('予約中でもログアウトできる (認証回復・離脱の手段は母集団の外)', function (): void {
    [, $owner] = frozenUser();

    $this->actingAs($owner)->post('/logout')->assertRedirect();
    $this->assertGuest();
});

test('予約中でも session.status は読める (bfcache 再検証の前提を凍結しない)', function (): void {
    [, $owner] = frozenUser();

    $this->actingAs($owner)->getJson('/session/status')->assertOk();
});

test('予約中でも解約導線 (billing.index / billing.portal) に到達できる', function (): void {
    [, $owner] = frozenUser();

    $this->actingAs($owner)->get('/billing')->assertOk();

    // portal は Stripe セッション生成へ進むため、ここでは「凍結で 302 されない」ことだけを見る
    $response = $this->actingAs($owner)->post('/billing/portal');
    expect($response->headers->get('Location'))->not->toBe(url('/settings'));
});

test('予約中でもオーナー移譲 (ブロッカー解消) の画面と操作に到達できる', function (): void {
    [$organization, $owner] = frozenUser();
    $member = attachOrganizationMember($organization);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/settings")->assertOk();

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $member->id])
        ->assertRedirect("/organizations/{$organization->slug}/settings");
});

test('予約中でも step-up 確認画面に到達できる (移譲に必要な satisfier)', function (): void {
    [, $owner] = frozenUser();

    // ★recent-auth.confirm を allowlist から外すとここが赤くなる (M25)
    $this->actingAs($owner)->get('/recent-auth/confirm')->assertOk();
    $this->actingAs($owner)->getJson('/recent-auth/status')->assertOk();
});

test('セッションが切れても再ログインしてから取消できる', function (): void {
    [, $owner] = frozenUser();

    $this->get('/settings')->assertRedirect('/login');

    $this->actingAs($owner)->from('/settings')
        ->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings');
    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
});

test('recent-auth の鮮度が切れていても取消できる (救済経路に step-up を課さない)', function (): void {
    [, $owner] = frozenUser();

    // recent_auth_at を一切持たないセッションで取消する
    $this->actingAs($owner)->from('/settings')
        ->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings');
    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
});

test('2FA 必須組織のユーザーでも取消できる (satisfier の到達性)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();

    // 2FA 準拠済みユーザー (未準拠だと 2FA 強制ゲートが先に短絡し、凍結の検証にならない)
    $user = User::factory()->withTwoFactor()->create();
    $organization->users()->attach($user);
    $user->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
    $user->forceFill(['current_organization_id' => $organization->id])->save();
    app(OrganizationMembershipService::class)->requestAccountDeletion($user);
    $user->refresh();

    $this->actingAs($user)->from('/settings')
        ->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings');
    expect($user->fresh()?->deletion_requested_at)->toBeNull();
});

/** 2FA 必須組織に所属する**未準拠**ユーザーを作り、退会予約中にして返す。 */
function twoFactorPendingFrozenUser(): User
{
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();
    $user = User::factory()->create(); // 2FA 未準拠
    $organization->users()->attach($user);
    $user->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
    $user->forceFill(['current_organization_id' => $organization->id])->save();
    app(OrganizationMembershipService::class)->requestAccountDeletion($user);
    $user->refresh();

    return $user;
}

test('2FA 未準拠でも退会予約を取り消せる (救済は 2FA ゲートも凍結も通る)', function (): void {
    // ★bug-hunt F-4-01 の再現条件。凍結側 allowlist は取消を通しているのに、priority list で
    //   **前**に走る 2FA 強制ゲートが取消 DELETE を settings.security へ倒していたため、
    //   「取り消したつもりで取り消せていない」状態が生まれていた。
    //   救済 (誤操作の取消) は業務の利用ではないので、両ゲートの判断を揃えて通す。
    $user = twoFactorPendingFrozenUser();

    // 負のコントロール (取消の**前**): 業務面は 2FA ゲートで遮断されている
    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('settings.security'));

    // 救済そのもの: 取消は通り、予約は実際に消える
    $this->actingAs($user)->from('/settings')
        ->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings');
    expect($user->fresh()?->deletion_requested_at)->toBeNull();

    // 負のコントロール (取消の**後**): 2FA 強制は 1mm も緩んでいない
    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('settings.security'));
    // 準拠判定 (two_factor_confirmed_at) も動かない = allowlist 通過はゲート解除ではない
    expect($user->fresh()?->two_factor_confirmed_at)->toBeNull();

    // ★準拠達成の入口 (settings.security) に到達できることが**詰みでないことの条件**。
    //   ここを凍結すると「取消は 2FA ゲート / 2FA 設定は凍結」の相互ブロックになる。
    $this->actingAs($user)->get('/settings/security')->assertOk();
    $this->actingAs($user)->get('/settings')->assertOk();

    // ★**同一ユーザー**で脱出の連鎖を固定する (未準拠 → settings.security → 2FA 準拠 → 業務面)。
    //   別ユーザーで代用すると「元のユーザーが本当に脱出できるか」を証明しないため、
    //   詰みの回帰防止にならない。準拠状態への遷移は UserFactory::withTwoFactor() と
    //   同一実装を共有する helper で行う。
    UserFactory::enableTwoFactorFor($user);
    $user->refresh();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('2FA 未準拠ユーザーの即時削除は通らない (救済だけを通す非対称)', function (): void {
    // 「削除系なら何でも通す」になっていないことの負のコントロール。
    // 即時削除 (settings.account.destroy) は救済ではなく 30 日猶予の迂回口である。
    $user = twoFactorPendingFrozenUser();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete('/settings/account')
        ->assertRedirect(route('settings.security'));

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('XHR は 409 Conflict で遮断される (302 に倒さない)', function (): void {
    [, $owner] = frozenUser();

    $this->actingAs($owner)->getJson('/dashboard')->assertStatus(409);
});

test('未予約ユーザーには一切影響しない (全 parameterless route が従来どおり)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $redirectedToSettings = [];
    foreach (freezeSweepTargets() as $name => [$method, $uri]) {
        $response = $this->actingAs($owner)->call($method, $uri);
        if ($response->getStatusCode() === 302 && $response->headers->get('Location') === url('/settings')) {
            $redirectedToSettings[] = $name;
        }
    }

    expect($redirectedToSettings)->toBe([],
        '未予約ユーザーが凍結されています (middleware が予約状態を見ていない疑い): '
        .implode(', ', $redirectedToSettings));
});

test('テナント境界 404 が凍結 302 より前に閉じる (存在オラクルを作らない)', function (): void {
    [, $owner] = frozenUser();
    [$otherOrganization] = createOrganizationWithOwner('他組織');
    $foreign = Project::factory()->forOrganization($otherOrganization)->create();

    // ★凍結 middleware を priority list でテナント境界より前へ動かすとここが 302 になる (M6)
    $this->actingAs($owner)->get("/projects/{$foreign->id}")->assertNotFound();
    $this->actingAs($owner)->get('/projects/999999999')->assertNotFound();
});
