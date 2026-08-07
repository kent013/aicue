<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Notifications\InApp\InvitationReceivedNotification;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * 組織招待のアプリ内通知配線 (施策5):
 * - 既存ユーザーの email へ招待 → その User に 1 件 (whereBlind 一致。payload に token を含まない)
 * - 未登録 email → 通知 0 (メールのみ)
 * - 既存の招待メール (OrganizationInvitationNotification) は従来どおり送信される
 */

test('既存ユーザーの email へ招待 → その User に 1 件 (org 名スナップショット・token 非含有)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('招待テスト組織');
    $existing = User::factory()->create(['email' => 'invited@example.com']);

    app(OrganizationMembershipService::class)->inviteMember(
        $organization, $owner, 'invited@example.com', OrganizationRole::Admin,
    );

    $rows = DB::table('notifications')->where('notifiable_id', $existing->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->type)->toBe('invitation_received');
    expect((int) $rows[0]->organization_id)->toBe($organization->id);

    $data = json_decode((string) $rows[0]->data, true);
    expect($data)->toBe(['organization_name' => '招待テスト組織']);
    // 平文 token を payload に含めない (token 平文非保存の不変条件)
    expect((string) $rows[0]->data)->not->toContain('token');
});

test('未登録 email へ招待 → アプリ内通知 0 (メールのみ)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    app(OrganizationMembershipService::class)->inviteMember(
        $organization, $owner, 'nobody@example.com', OrganizationRole::Admin,
    );

    expect(DB::table('notifications')->count())->toBe(0);
});

test('招待メールは従来どおり送信され、既存ユーザーにはアプリ内通知も送られる', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $existing = User::factory()->create(['email' => 'both@example.com']);

    app(OrganizationMembershipService::class)->inviteMember(
        $organization, $owner, 'both@example.com', OrganizationRole::Admin,
    );

    // メール (on-demand route) は従来どおり
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
    // アプリ内通知 (database channel) も既存ユーザー宛に送られる
    Notification::assertSentTo($existing, InvitationReceivedNotification::class);
});
