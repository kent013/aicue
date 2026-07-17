<?php

declare(strict_types=1);

use App\Jobs\Billing\SyncBillingCustomerDetails;
use Illuminate\Support\Facades\Queue;

/*
 * 組織 rename 経路 (RenameOrganizationAction 経由) の契約:
 * - 外部挙動 (redirect / DB 結果) は不変
 * - name が実際に変わったときだけ Stripe customer 同期 job を dispatch する
 * - Stripe customer 未作成 (stripe_id === null) の組織は no-op
 */

test('owner は組織名を更新でき、Stripe customer 同期が dispatch される', function (): void {
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_rename_1'])->save();

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->patch("/organizations/{$organization->slug}", ['name' => '新しい組織名'])
        ->assertRedirect("/organizations/{$organization->slug}/settings")
        ->assertSessionHas('success');

    expect($organization->fresh()->name)->toBe('新しい組織名');
    Queue::assertPushed(SyncBillingCustomerDetails::class, 1);
});

test('同名での保存では同期を dispatch しない (isDirty 限定)', function (): void {
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner('元の名前');
    $organization->forceFill(['stripe_id' => 'cus_rename_2'])->save();

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->patch("/organizations/{$organization->slug}", ['name' => '元の名前'])
        ->assertSessionHas('success');

    Queue::assertNotPushed(SyncBillingCustomerDetails::class);
});

test('Stripe customer 未作成の組織では rename しても同期を dispatch しない', function (): void {
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    expect($organization->stripe_id)->toBeNull();

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->patch("/organizations/{$organization->slug}", ['name' => '名前だけ変更'])
        ->assertSessionHas('success');

    expect($organization->fresh()->name)->toBe('名前だけ変更');
    Queue::assertNotPushed(SyncBillingCustomerDetails::class);
});

test('一般メンバーは組織名を更新できない (認可境界は不変)', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner('元の名前');
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)
        ->from("/organizations/{$organization->slug}/settings")
        ->patch("/organizations/{$organization->slug}", ['name' => '乗っ取り'])
        ->assertForbidden();

    expect($organization->fresh()->name)->toBe('元の名前');
    Queue::assertNotPushed(SyncBillingCustomerDetails::class);
});
