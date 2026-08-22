<?php

declare(strict_types=1);

use App\Models\OrganizationSlugRename;
use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;

/*
 * 改名 (家系裁定 AG-046 / 不変条件 I12・I13)。
 */

test('改名すると新しい識別名の URL へ遷移する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'new-genba'])
        ->assertRedirect('/organizations/new-genba/settings')
        ->assertSessionHas('success');

    expect($organization->fresh()?->slug)->toBe('new-genba');
});

test('改名後は旧 URL が 404 になる (転送は置かない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $old = $organization->slug;

    $this->actingAs($owner)->patch("/organizations/{$old}/slug", ['slug' => 'new-genba']);

    $this->actingAs($owner)->get("/organizations/{$old}/settings")->assertNotFound();
    $this->actingAs($owner)->get('/organizations/new-genba/settings')->assertOk();
});

test('旧識別名は解放され、他の組織が取れる (履歴に一意制約を張らない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('先着');
    $old = $organization->slug;

    $this->actingAs($owner)->patch("/organizations/{$old}/slug", ['slug' => 'renamed-genba']);

    $other = User::factory()->create();
    $reused = app(OrganizationProvisioningService::class)->provision($other, '後着', $old);

    expect($reused->slug)->toBe($old);
});

test('同じ識別名への改名は 422 (回数を消費させない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => $organization->slug])
        ->assertSessionHasErrors('slug');

    expect(OrganizationSlugRename::query()->count())->toBe(0);
});

test('改名は履歴に残る (from / to / 実行者)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $old = $organization->slug;

    $this->actingAs($owner)->patch("/organizations/{$old}/slug", ['slug' => 'kept-history']);

    $rename = OrganizationSlugRename::query()->sole();
    expect($rename->from_slug)->toBe($old);
    expect($rename->to_slug)->toBe('kept-history');
    expect($rename->organization_id)->toBe($organization->id);
    expect($rename->renamed_by_user_id)->toBe($owner->id);
});

test('大文字で入れた識別名は正規化されて保存される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'New-Genba'])
        ->assertRedirect('/organizations/new-genba/settings');

    expect($organization->fresh()?->slug)->toBe('new-genba');
});
