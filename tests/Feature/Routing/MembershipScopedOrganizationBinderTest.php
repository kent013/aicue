<?php

declare(strict_types=1);

use App\Http\Routing\MembershipScopedOrganizationBinder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| org-boundary-404: MembershipScopedOrganizationBinder の経路固定
|--------------------------------------------------------------------------
|
| AppServiceProvider の Route::bind('organization', ...) 登録を実 route
| (slug binding = organizations.settings) と binder 直接呼び (id binding) で検証し、
| binder 単体の fail-closed 分岐 (guest / 未知 field / 非数値 id / 非 scalar) を直接呼びで
| 固定する。membership は organization_user pivot (Organization::users) で判定する。
*/

it('slug binding: メンバーは解決できる (organizations.settings 200)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get(route('organizations.settings', $organization))
        ->assertOk();
});

it('slug binding: 非メンバーは 404 (テナント存在秘匿)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->get(route('organizations.settings', $organization))
        ->assertNotFound();
});

it('slug binding: 不在 slug は非メンバーと同一の 404', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get('/organizations/no-such-organization/settings')
        ->assertNotFound();
});

/*
| id binding の検証は **binder 直接呼び**で行う。
|
| 家系裁定 AG-037 に従い切替 endpoint (organizations.switch) を撤去したため、
| `{organization}` を **field 無指定 (= id)** で受ける web route は 1 本も無い
| (MachinePlaneOrganizationReferenceTest が 0 件であることを固定する)。
| binder 自身は allowlist として id を引き続き受け付けるので、その fail-closed 分岐は
| ここで直接固定する (経路が消えたからといって分岐の検証まで消さない)。
*/

it('id binding: メンバーは id でも解決できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $binder = new MembershipScopedOrganizationBinder;

    expect($binder->bind((string) $organization->id)->is($organization))->toBeTrue();
});

it('id binding: 非メンバーは ModelNotFoundException (旧 403 でなく存在秘匿)', function (): void {
    [, $user] = createOrganizationWithOwner('組織A');
    [$other] = createOrganizationWithOwner('組織B');
    $this->actingAs($user);

    $binder = new MembershipScopedOrganizationBinder;

    expect(fn (): Organization => $binder->bind((string) $other->id))
        ->toThrow(ModelNotFoundException::class);
});

it('id binding: 不在 id は非メンバーと同一の ModelNotFoundException', function (): void {
    [, $user] = createOrganizationWithOwner();
    $this->actingAs($user);

    $binder = new MembershipScopedOrganizationBinder;

    expect(fn (): Organization => $binder->bind('987654321'))
        ->toThrow(ModelNotFoundException::class);
});

it('id binding に非数値文字列は fail-closed (500 化しない)', function (): void {
    [, $user] = createOrganizationWithOwner();
    $this->actingAs($user);

    $binder = new MembershipScopedOrganizationBinder;

    expect(fn (): Organization => $binder->bind('not-a-number'))
        ->toThrow(ModelNotFoundException::class);
});

it('id binding に bigint 範囲外の巨大数値文字列は fail-closed (500 化しない)', function (): void {
    [, $user] = createOrganizationWithOwner();
    $this->actingAs($user);

    // 64bit signed (bigint) を超える数値文字列。where('id', ...) で範囲外キャストによる
    // 500 を避け、存在し得ない id として fail-closed にすることを固定する。
    $binder = new MembershipScopedOrganizationBinder;

    expect(fn (): Organization => $binder->bind('99999999999999999999999999'))
        ->toThrow(ModelNotFoundException::class);
});

it('id binding に先頭ゼロ付き (非 canonical) 値は fail-closed', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    // '0'+id は非 canonical な数値表現。round-trip 不一致で fail-closed になることを固定する
    // (メンバーであっても解決対象外 = 存在秘匿と同列に倒す)。
    $binder = new MembershipScopedOrganizationBinder;

    expect(fn (): Organization => $binder->bind('0'.$organization->id))
        ->toThrow(ModelNotFoundException::class);
});

it('guest fail-closed: 未認証コンテキストの bind() 直接呼び出しは ModelNotFoundException', function (): void {
    [$organization] = createOrganizationWithOwner();
    $binder = new MembershipScopedOrganizationBinder;

    expect(fn (): Organization => $binder->bind((string) $organization->id))
        ->toThrow(ModelNotFoundException::class);
});

it('bind() に非 scalar 値が来たら fail-closed で ModelNotFoundException', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $binder = new MembershipScopedOrganizationBinder;

    expect(fn (): Organization => $binder->bind(['nested' => 'value']))
        ->toThrow(ModelNotFoundException::class);
});

it('未知 binding field は 404 に倒す (500 化しない) + warning ログ', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    // route 定義側で {organization:uuid} のような allowlist 外 field を指定した状況を再現する
    $route = new Route(['GET'], '/organizations/{organization}', ['as' => 'test.unsupported-field']);
    $route->setBindingFields(['organization' => 'uuid']);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'unsupported binding field'));

    $binder = new MembershipScopedOrganizationBinder;

    expect(fn (): Organization => $binder->bind((string) $organization->id, $route))
        ->toThrow(ModelNotFoundException::class);
});

it('scopeBindings 子 binding ({apiKey}) は親 Organization 経由で解決される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('組織A');
    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
    [$apiKey] = issueApiKey($organization, $owner);
    [$foreignKey] = issueApiKey($organizationB, $ownerB);

    // 自組織の key は親 Organization 経由で解決し失効できる (302。foreign key の 404 と
    // 対にすることで scopeBindings の親子整合を両側で固定する)
    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete("/organizations/{$organization->slug}/api-keys/{$apiKey->id}")
        ->assertRedirect();
    expect($apiKey->refresh()->isRevoked())->toBeTrue();

    // 他組織の key id は scopeBindings により認可より前に 404
    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete("/organizations/{$organization->slug}/api-keys/{$foreignKey->id}")
        ->assertNotFound();
    expect($foreignKey->refresh()->isRevoked())->toBeFalse();
});
