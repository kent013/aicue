<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\SecurityAuditEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Organization\OrganizationMembershipService;
use App\Services\Organization\OrganizationProvisioningService;
use Illuminate\Support\Collection;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia;

/**
 * ブロック時に session へ積まれた errors.account の文言を取り出す。
 * session の 'errors' は ViewErrorBag のことも、flash 済みの生配列のこともある
 * (どちらも同じ MessageBag の内容)。読み出し側で両方の形を吸収する。
 */
function accountDeletionError(): string
{
    $errors = session('errors');
    expect($errors)->not->toBeNull();

    if ($errors instanceof ViewErrorBag) {
        return (string) $errors->getBag('default')->first('account');
    }

    expect($errors)->toBeArray();

    return (string) ($errors['default']['messages']['account'][0] ?? '');
}

test('再認証 (step-up) なしではアカウント削除できない', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/settings/account');

    // recent-auth が確認画面へ redirect する
    $response->assertRedirect();
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('step-up 済みならアカウントを削除でき、関連データが掃除される', function (): void {
    $user = User::factory()->create();
    $social = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-123']);
    $social->user()->associate($user);
    $social->save();

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    // 破壊的操作の flash 規約: 着地先 (未認証面 = GuestLayout) で toast として表示される
    $response->assertSessionHas('success', 'アカウントを削除しました');
    $this->assertGuest();
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
    expect(SocialAccount::query()->whereKey($social->id)->exists())->toBeFalse();

    // 削除イベントは user_id が null 化されて残る (nullOnDelete)
    expect(
        SecurityAuditEvent::query()->where('event_type', 'account_deleted')->exists(),
    )->toBeTrue();
});

test('唯一オーナーで他メンバーが残る場合はアカウント削除がブロックされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin); // 孤児化する残存メンバー

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertRedirect('/settings');
    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue(); // 残存
});

// 課金なし前提のテスト (T115 で「課金責務があれば個人組織でもブロックする」を足したため、
// 本ケースは「孤児化するメンバーも課金責務も無い個人組織は退会できる」ことを固定する)
test('唯一オーナーだが自分のみメンバー (個人組織) なら削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(); // owner 1 人・他メンバー無し

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('複数オーナーがいれば削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $second = attachOrganizationMember($organization, OrganizationRole::Owner);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
    expect($second->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});

test('ブロック→2人目オーナー追加後は削除できる (現在状態で再評価)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin);
    // この時点では唯一 Owner + 他メンバー有り → ブロックされるはず
    expect(app(OrganizationMembershipService::class)->organizationsBlockingDeletion($owner))->toHaveCount(1);

    attachOrganizationMember($organization, OrganizationRole::Owner); // 2 人目 Owner を追加

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('2オーナー→片方降格後は唯一オーナー+メンバーで削除がブロックされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $second = attachOrganizationMember($organization, OrganizationRole::Owner);
    attachOrganizationMember($organization, OrganizationRole::Member); // 孤児化するメンバー
    // service 正規経路で 2 人目 Owner を Admin へ降格 (owner を 1 人に戻す)
    app(OrganizationMembershipService::class)->changeRole($organization, $second, OrganizationRole::Admin, null);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

/*
 * 退会時の課金ガード (T115)。
 * 「唯一 Owner かつ生きた課金責務が残る」組織は、個人組織でも退会をブロックする
 * (退会後に Owner 不在のまま課金が宙づりになるため)。
 */

test('課金中の個人組織の唯一オーナーは退会がブロックされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('現場A');
    createFakeSubscription($organization, status: 'active');

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertRedirect('/settings');
    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
    expect(accountDeletionError())->toContain('現場A')->toContain('サブスクリプションの解約');
});

test('解約予約済み (ends_at あり) の個人組織なら退会できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active')
        ->forceFill(['ends_at' => now()->addDays(10)])->save();

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('paused / canceled の個人組織なら退会できる', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: $status);

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
})->with(['paused', 'canceled']);

test('課金中でも 2 人目オーナーがいれば退会できる (課金の引受先が残る)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');
    attachOrganizationMember($organization, OrganizationRole::Owner);

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('課金中 + 他メンバー有りの唯一オーナーは移譲と解約の両方を求められる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('現場A');
    createFakeSubscription($organization, status: 'active');
    attachOrganizationMember($organization, OrganizationRole::Admin);

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertSessionHasErrors('account');
    expect(accountDeletionError())
        ->toContain('オーナーの移譲')
        ->toContain('サブスクリプションの解約');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

test('trialing の個人組織は退会がブロックされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'trialing');

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

test('退会成功経路では決済事業者 API を呼ばない', function (): void {
    [, $owner] = createOrganizationWithOwner();
    // 期待を設定しない mock = 1 度でも呼ばれたら fail
    $this->mock(StripeGatewayInterface::class);

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('課金中でブロックされる経路でも決済事業者 API を呼ばない (解約を代行しない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');
    $this->mock(StripeGatewayInterface::class);

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

test('複数組織がブロックしても要約 1 本が届き、/settings で全件の対応が再評価される', function (): void {
    // 課金中の個人組織 + 他メンバーが残る組織の 2 件でブロックする
    [$billingOrg, $owner] = createOrganizationWithOwner('課金組織');
    createFakeSubscription($billingOrg, status: 'active');
    $memberOrg = app(OrganizationProvisioningService::class)->provision($owner, 'メンバー組織');
    attachOrganizationMember($memberOrg, OrganizationRole::Admin);

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->from('/settings')
        ->delete('/settings/account')
        ->assertRedirect('/settings');

    // redirect back 後の GET を **Inertia props まで通して** 固定する
    // (session の MessageBag だけを見ると、Inertia 側の先頭 1 件縮退も props の再評価漏れも
    //  検出できない)。
    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Index')
            // (a) errors.account は単一文字列 (Inertia は field ごとに先頭 1 件しか渡さない)
            // (b) その 1 本に両組織の必要対応が含まれる
            ->where('errors.account', fn (mixed $summary): bool => is_string($summary)
                && str_contains($summary, '「課金組織」サブスクリプションの解約')
                && str_contains($summary, '「メンバー組織」オーナーの移譲'))
            // (c) 組織ごとの詳細は props 側が全件持つ
            ->has('accountDeletionBlockers', 2)
            // (d) 各 blocker の action (現在組織 = 課金組織 → open_billing)
            ->where('accountDeletionBlockers', fn (Collection $blockers): bool => $blockers
                ->pluck('actions')->flatten()->sort()->values()->all() === [
                    'open_billing', 'transfer_ownership',
                ])
            ->etc());
});
