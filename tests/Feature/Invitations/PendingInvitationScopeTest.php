<?php

declare(strict_types=1);

use App\Models\OrganizationInvitation;

/*
 * 受信者視点の単一解決口 scopeActivePendingForEmail の契約。
 *
 * 3 条件 (active / blind index 完全一致 / 組織実在) が**すべて同じ 0 件へ collapse する**
 * ことが、呼び出し側で理由を出し分けずに一律 404 へ畳める根拠である。
 */

test('自分宛の active な招待だけを返す', function (): void {
    [$organization] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'me@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'other@example.com']);

    $found = OrganizationInvitation::query()->activePendingForEmail('me@example.com')->get();

    expect($found)->toHaveCount(1);
    expect($found->sole()->email)->toBe('me@example.com');
});

test('期限切れ・取消済・受諾済は返さない', function (string $state): void {
    [$organization] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->{$state}()
        ->create(['email' => 'me@example.com']);

    expect(OrganizationInvitation::query()->activePendingForEmail('me@example.com')->count())->toBe(0);
})->with(['expired', 'revoked', 'accepted']);

test('削除済み (soft-deleted) 組織宛は返さない', function (): void {
    [$organization] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'me@example.com']);

    expect(OrganizationInvitation::query()->activePendingForEmail('me@example.com')->count())->toBe(1);

    $organization->delete();

    expect(OrganizationInvitation::query()->activePendingForEmail('me@example.com')->count())->toBe(0);
});

test('email の大小差は一致しない (blind index の完全一致契約)', function (): void {
    [$organization] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'Foo@example.com']);

    // fail-secure: 大小差は 0 件 = 呼び出し側では 404 に倒れる
    expect(OrganizationInvitation::query()->activePendingForEmail('foo@example.com')->count())->toBe(0);
    expect(OrganizationInvitation::query()->activePendingForEmail('Foo@example.com')->count())->toBe(1);
});
