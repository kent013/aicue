<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;

/*
 * 退会予約のアプリ内通知は**作らない** (家系裁定 AG-037 の帰結)。
 *
 * ★アプリ内通知は org 文脈を必須とする (`AppNotification::organizationId()` が non-nullable)。
 *   退会予約は**個人設定の面**からの操作であり、URL に組織を持たない。
 *   すなわち信頼できる org 文脈をこの経路から導出できないので、
 *   当該 feature 自身の規則「org 文脈を捏造しない」に従って作らない。
 * ★**メールは従来どおり届く** (利用者が退会予約に気付けなくなるわけではない)。
 */

test('組織に所属していても退会予約でアプリ内通知は作られない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create();
    $organization->users()->attach($user);

    app(OrganizationMembershipService::class)->requestAccountDeletion($user);

    expect($user->notifications()->count())->toBe(0);
});

test('通知センターの未読件数が増えない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create();
    $organization->users()->attach($user);

    app(OrganizationMembershipService::class)->requestAccountDeletion($user);

    expect($user->unreadNotifications()->count())->toBe(0);
});
