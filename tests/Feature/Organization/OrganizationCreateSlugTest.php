<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;
use App\Support\Organization\OrganizationSlug;

/*
 * 組織作成時の識別名の決まり方 (家系裁定 AG-039)。
 *
 * | 経路 | 識別名 |
 * |---|---|
 * | 登録時の初期組織 (`{name} の組織` = 日本語) | 導出できないので `org-{乱数}` へ倒す |
 * | 組織作成画面 (省略) | 導出 → 失敗ならフォールバック |
 * | 組織作成画面 (明示) | そのまま。予約語・使用済みは 422 |
 */

test('日本語の組織名でも作成できる (フォールバックが効く)', function (): void {
    $user = User::factory()->create();

    $organization = app(OrganizationProvisioningService::class)->provision($user, 'テスト組織');

    expect($organization->slug)->toStartWith('org-');
    expect(preg_match(OrganizationSlug::PATTERN, $organization->slug))->toBe(1);
});

test('英字の組織名は名前から導出される', function (): void {
    $user = User::factory()->create();

    $organization = app(OrganizationProvisioningService::class)->provision($user, 'Acme Corp');

    expect($organization->slug)->toBe('acme-corp');
});

test('画面から識別名を明示できる', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/organizations', ['name' => 'テスト組織', 'slug' => 'my-genba'])
        ->assertRedirect('/organizations/my-genba/settings');

    expect(Organization::query()->where('slug', 'my-genba')->exists())->toBeTrue();
});

test('識別名を省略しても作成できる (サーバが決める)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/organizations', ['name' => 'テスト組織']);

    $response->assertSessionHasNoErrors();
    $organization = Organization::query()->latest('id')->firstOrFail();
    expect($organization->slug)->toStartWith('org-');
});

test('利用者が明示した予約語は 422 (黙って代替を作らない)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/organizations', ['name' => 'テスト組織', 'slug' => 'admin'])
        ->assertSessionHasErrors('slug');

    expect(Organization::query()->where('slug', 'admin')->exists())->toBeFalse();
});

test('利用者が明示した構文違反は 422 (500 にならない)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/organizations', ['name' => 'テスト組織', 'slug' => 'Acme Corp!'])
        ->assertSessionHasErrors('slug');
});

test('利用者が明示した識別名が使用済みなら 422 (黙って代替を作らない)', function (): void {
    [$existing] = createOrganizationWithOwner('先着');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/organizations', ['name' => 'テスト組織', 'slug' => $existing->slug])
        ->assertSessionHasErrors('slug');

    expect($user->organizations()->count())->toBe(0);
});
