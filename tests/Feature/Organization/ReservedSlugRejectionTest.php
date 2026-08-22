<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;

/*
 * 予約語は**保存できない** (家系裁定 AG-039 / 不変条件 I11)。作成・改名の両経路で拒否する。
 */

test('作成: 利用者入力の予約語は 422', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/organizations', ['name' => 'テスト組織', 'slug' => 'support'])
        ->assertSessionHasErrors('slug');
});

test('作成: 組織名からの導出結果が予約語ならフォールバックへ倒す (エラーにしない)', function (): void {
    $user = User::factory()->create();

    // 'Admin' は導出すると 'admin' = 予約語。黙って fallback へ倒れる
    $organization = app(OrganizationProvisioningService::class)->provision($user, 'Admin');

    expect($organization->slug)->not->toBe('admin');
    expect($organization->slug)->toStartWith('org-');
});

test('改名: 予約語は 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'create'])
        ->assertSessionHasErrors('slug');

    expect(Organization::query()->where('slug', 'create')->exists())->toBeFalse();
});
