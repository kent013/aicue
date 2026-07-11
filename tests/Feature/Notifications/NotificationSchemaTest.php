<?php

declare(strict_types=1);

use App\DataTransferObjects\Notification\InvitationReceivedPayload;
use App\DataTransferObjects\Notification\ManualJobPayload;
use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
use App\Notifications\InApp\InvitationReceivedNotification;
use App\Notifications\InApp\ManualAnalyzedNotification;
use App\Notifications\InApp\ManualRenderedNotification;
use App\Notifications\InApp\TicketBalanceLowNotification;
use Illuminate\Support\Facades\DB;

/*
 * notifications スキーマ + OrganizationScopedDatabaseChannel の round-trip (施策1/2):
 * - notify() 実発火 → type = NotificationType enum 値 (クラス名でない)・organization_id 列・data 形状
 * - v1 の全通知種別で organization_id が非 null で書き込まれる (DB 列は nullable だが
 *   null を書く種別は存在しない、の固定)
 * - 未読 count クエリ (複合 index 前提の機能面)
 * - payload DTO の tryFromArray 検証復元 (不正形状 → null)
 */

function manualJobPayloadFixture(bool $succeeded = true): ManualJobPayload
{
    return new ManualJobPayload(
        projectId: 1,
        manualId: 2,
        manualTitle: 'ネジ締め手順',
        organizationName: 'テスト組織',
        succeeded: $succeeded,
        error: $succeeded ? null : '解析に失敗しました。',
    );
}

test('notify 実発火で type=enum 値・organization_id 列・data 形状が書き込まれる (channel round-trip)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $owner->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));

    $row = DB::table('notifications')->first();
    expect($row)->not->toBeNull();
    expect($row->type)->toBe('manual_analyzed'); // クラス名を DB に置かない
    expect($row->notifiable_type)->toBe($owner->getMorphClass());
    expect((int) $row->notifiable_id)->toBe($owner->id);
    expect((int) $row->organization_id)->toBe($organization->id);

    $data = json_decode((string) $row->data, true);
    // pgsql jsonb はキー順を保存しないため順序非依存で比較する
    expect($data)->toEqual([
        'project_id' => 1,
        'manual_id' => 2,
        'manual_title' => 'ネジ締め手順',
        'organization_name' => 'テスト組織',
        'succeeded' => true,
        'error' => null,
    ]);
    expect($row->read_at)->toBeNull();
});

test('v1 の全通知種別で organization_id が非 null で書き込まれる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $owner->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));
    $owner->notify(new ManualRenderedNotification($organization->id, manualJobPayloadFixture(succeeded: false)));
    $owner->notify(new InvitationReceivedNotification($organization->id, new InvitationReceivedPayload('テスト組織')));
    $owner->notify(new TicketBalanceLowNotification($organization->id, new TicketBalanceLowPayload('テスト組織', 3, 5)));

    expect(DB::table('notifications')->count())->toBe(4);
    expect(DB::table('notifications')->whereNull('organization_id')->count())->toBe(0);
    expect(DB::table('notifications')->pluck('type')->sort()->values()->all())->toBe([
        'invitation_received',
        'manual_analyzed',
        'manual_rendered',
        'ticket_balance_low',
    ]);
});

test('未読 count は自分宛の未読のみを数える (既読化で減る)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);

    $owner->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));
    $owner->notify(new ManualRenderedNotification($organization->id, manualJobPayloadFixture()));
    $other->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));

    expect($owner->unreadNotifications()->count())->toBe(2);
    expect($other->unreadNotifications()->count())->toBe(1);

    $first = $owner->notifications()->firstOrFail();
    $first->markAsRead();
    expect($owner->unreadNotifications()->count())->toBe(1);
});

test('org 物理削除で通知は cascade 削除される (organization_id FK)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $owner->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));
    expect(DB::table('notifications')->count())->toBe(1);

    // Organization は SoftDeletes のため FK cascade は物理削除 (forceDelete) で発火する
    $organization->forceDelete();

    expect(DB::table('notifications')->count())->toBe(0);
});

test('ManualJobPayload::tryFromArray は不正形状で null を返す', function (): void {
    $valid = manualJobPayloadFixture()->toArray();
    expect(ManualJobPayload::tryFromArray($valid))->not->toBeNull();

    expect(ManualJobPayload::tryFromArray([]))->toBeNull();
    expect(ManualJobPayload::tryFromArray([...$valid, 'project_id' => '1']))->toBeNull();
    expect(ManualJobPayload::tryFromArray([...$valid, 'succeeded' => 'yes']))->toBeNull();
    expect(ManualJobPayload::tryFromArray([...$valid, 'error' => 123]))->toBeNull();
});

test('InvitationReceivedPayload / TicketBalanceLowPayload の tryFromArray も不正形状で null', function (): void {
    expect(InvitationReceivedPayload::tryFromArray(['organization_name' => 'X']))->not->toBeNull();
    expect(InvitationReceivedPayload::tryFromArray(['organization_name' => 1]))->toBeNull();
    expect(InvitationReceivedPayload::tryFromArray([]))->toBeNull();

    expect(TicketBalanceLowPayload::tryFromArray([
        'organization_name' => 'X', 'balance' => 3, 'threshold' => 5,
    ]))->not->toBeNull();
    expect(TicketBalanceLowPayload::tryFromArray([
        'organization_name' => 'X', 'balance' => '3', 'threshold' => 5,
    ]))->toBeNull();
    expect(TicketBalanceLowPayload::tryFromArray([]))->toBeNull();
});
