<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationSlugRename;
use App\Services\Organization\OrganizationSlugRenameLimiter;
use Carbon\CarbonImmutable;

/*
 * 改名は 30 日あたり 5 回まで (家系裁定 AG-046 / 不変条件 I12)。
 *
 * ★窓は**ローリング**で、境界は `renamed_at > now - 30 日` (**境界を含まない**)。
 *   包含にすると「最古 + 30 日」ちょうどで画面の案内 (nextAvailableAt) に到達しても
 *   改名できず、案内と挙動が食い違う。
 * ★最終権威は**組織行を行ロックした後の再判定**である。
 */

/** 窓内に $count 件の履歴を積む */
function seedRenames(Organization $organization, int $count, ?CarbonImmutable $at = null): void
{
    $at ??= CarbonImmutable::now()->subDay();
    for ($i = 0; $i < $count; $i++) {
        OrganizationSlugRename::factory()
            ->for($organization)
            ->renamedAt($at->addMinutes($i))
            ->create();
    }
}

test('30 日 5 回で 6 回目が 422 になる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    seedRenames($organization, OrganizationSlugRenameLimiter::LIMIT);

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'sixth-attempt'])
        ->assertSessionHasErrors('slug');

    expect($organization->fresh()?->slug)->not->toBe('sixth-attempt');
});

test('境界: ちょうど 30 日前の履歴は窓に含まれない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $boundary = CarbonImmutable::now()->subDays(OrganizationSlugRenameLimiter::WINDOW_DAYS);
    seedRenames($organization, OrganizationSlugRenameLimiter::LIMIT, $boundary);

    // 5 件すべてが「ちょうど 30 日前かそれより後」だが、最古の 1 件は境界ちょうどなので窓外
    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'boundary-ok'])
        ->assertRedirect('/organizations/boundary-ok/settings');
});

test('案内した nextAvailableAt ちょうどの時刻で実際に改名できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $oldest = CarbonImmutable::now();
    seedRenames($organization, OrganizationSlugRenameLimiter::LIMIT, $oldest);

    $quota = app(OrganizationSlugRenameLimiter::class)->quotaFor($organization);
    expect($quota->remaining)->toBe(0);
    expect($quota->nextAvailableAt)->not->toBeNull();

    CarbonImmutable::setTestNow($quota->nextAvailableAt);

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'exactly-next'])
        ->assertRedirect('/organizations/exactly-next/settings');

    CarbonImmutable::setTestNow();
});

test('残り回数は画面 props に載る (権威ではなく表示のための早期情報)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    seedRenames($organization, 2);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/settings")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('slugRename.remaining', OrganizationSlugRenameLimiter::LIMIT - 2)
            ->where('slugRename.nextAvailableAt', null)
            ->etc());
});

test('事前判定を通っても行ロック後の再判定で落ちる (最終権威はロック後)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    // 事前判定 (画面) では残り 5 回。ここで別経路が窓を埋めた状況を作る
    expect(app(OrganizationSlugRenameLimiter::class)->quotaFor($organization)->remaining)
        ->toBe(OrganizationSlugRenameLimiter::LIMIT);

    seedRenames($organization, OrganizationSlugRenameLimiter::LIMIT);

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'after-lock-reject'])
        ->assertSessionHasErrors('slug');
});
