<?php

declare(strict_types=1);

use App\DataTransferObjects\Notification\InvitationReceivedPayload;
use App\DataTransferObjects\Notification\ManualJobPayload;
use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Notifications\InApp\InvitationReceivedNotification;
use App\Notifications\InApp\ManualAnalyzedNotification;
use App\Notifications\InApp\TicketBalanceLowNotification;
use App\Services\Notification\NotificationCenterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 通知センター route (施策6): index / open / read / read-all の全分岐。
 * - 自分宛のみ表示 (全 org 横断)・ページネーション
 * - cross-user は 404 (403 でない = 存在秘匿)・GET open は 405
 * - open の遷移解決 (manual 現存 / 削除済み / org 不一致 / 残高 / 招待 / 未知 type)
 * - 未認証は login へ / unverified は verified ガード
 */

/**
 * 通知一覧テスト用: owner の org + project + manual (creator=owner)。
 *
 * @return array{Organization, User, Project, VideoManual}
 */
function notificationCenterContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->createdBy($owner)->create([
        'title' => '通知対象マニュアル',
    ]);

    return [$organization, $owner, $project, $manual];
}

/** manual 系通知を owner へ発火し、その通知 uuid を返す */
function notifyManualAnalyzed(Organization $organization, User $user, Project $project, VideoManual $manual): string
{
    $user->notify(new ManualAnalyzedNotification($organization->id, new ManualJobPayload(
        projectId: $project->id,
        manualId: $manual->id,
        manualTitle: $manual->title,
        organizationName: $organization->name,
        succeeded: true,
        error: null,
    )));

    $id = $user->notifications()->latest()->firstOrFail()->getKey();
    assert(is_string($id));

    return $id;
}

test('index: 自分宛のみ表示 (他人の通知が混ざらない)・未読/既読・全 org 横断', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    $other = attachOrganizationMember($organization);
    notifyManualAnalyzed($organization, $owner, $project, $manual);
    notifyManualAnalyzed($organization, $other, $project, $manual);

    // 別 org の通知も自分宛なら見える (全 org 横断)
    [$organization2] = createOrganizationWithOwner('第二組織');
    $organization2->users()->attach($owner);
    $owner->notify(new TicketBalanceLowNotification(
        $organization2->id, new TicketBalanceLowPayload('第二組織', 3, 5),
    ));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/notifications")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->has('notifications', 2)
            ->where('meta.total', 2));
});

test('index: ページネーション (20 件/頁)', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    for ($i = 0; $i < 25; $i++) {
        notifyManualAnalyzed($organization, $owner, $project, $manual);
    }

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/notifications")
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications', 20)
            ->where('meta.last_page', 2)
            ->where('meta.total', 25));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/notifications?page=2")
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications', 5)
            ->where('meta.current_page', 2));
});

test('index: 未読数を unreadCount prop で渡す (自分宛のみ・既読を除外)', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();

    // 自分宛の未読を 3 件発火
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = notifyManualAnalyzed($organization, $owner, $project, $manual);
    }

    // 別ユーザー宛の通知は自分の未読数に含めない (自分宛のみカウントを検証)
    $other = attachOrganizationMember($organization);
    notifyManualAnalyzed($organization, $other, $project, $manual);

    // 1 件を既読化 → 未読は 2 件 (既読を除外することを検証)
    $owner->notifications()->whereKey($ids[0])->firstOrFail()->markAsRead();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/notifications")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->where('unreadCount', 2));
});

test('index: unreadCount は全 org 横断で自分宛未読を数える', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    notifyManualAnalyzed($organization, $owner, $project, $manual);

    // 別組織に owner を所属させ、その org 由来の自分宛通知も未読数に含める (全 org 横断契約)
    [$organization2] = createOrganizationWithOwner('第二組織');
    $organization2->users()->attach($owner);
    $owner->notify(new TicketBalanceLowNotification(
        $organization2->id, new TicketBalanceLowPayload('第二組織', 3, 5),
    ));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/notifications")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->where('unreadCount', 2));
});

test('index: 全既読なら unreadCount=0', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    notifyManualAnalyzed($organization, $owner, $project, $manual);
    notifyManualAnalyzed($organization, $owner, $project, $manual);

    app(NotificationCenterService::class)->markAllRead($owner);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/notifications")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->where('unreadCount', 0));
});

test('read: 自分の通知は既読化され back で戻る', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);

    $this->actingAs($owner)->from("/organizations/{$organization->slug}/notifications")
        ->post("/organizations/{$organization->slug}/notifications/{$id}/read")
        ->assertRedirect("/organizations/{$organization->slug}/notifications");

    expect($owner->unreadNotifications()->count())->toBe(0);
});

test('read/open: 他人の通知 uuid は 404 (403 でない = 存在秘匿)。存在しない uuid も 404', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    $other = attachOrganizationMember($organization);
    $othersId = notifyManualAnalyzed($organization, $other, $project, $manual);

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/{$othersId}/read")->assertNotFound();
    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/{$othersId}/open")->assertNotFound();
    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/".Str::uuid()->toString().'/read')->assertNotFound();

    // 他人の通知は未読のまま (影響しない)
    expect($other->unreadNotifications()->count())->toBe(1);
});

test('read/open: 不正形式 (非UUID) の id は route 不一致で 404 (pgsql uuid 比較の 22P02 = 500 を出さない)', function (): void {
    [$organization, $owner] = notificationCenterContext();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/not-a-uuid/read")->assertNotFound();
    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/not-a-uuid/open")->assertNotFound();
});

test('findOwnOrFail: 非UUID id は service 層でも ModelNotFoundException (route 制約を通らない将来経路の防護)', function (): void {
    [, $owner] = notificationCenterContext();

    app(NotificationCenterService::class)
        ->findOwnOrFail($owner, 'not-a-uuid');
})->throws(ModelNotFoundException::class);

test('read-all: 自分の未読のみ全既読 (他人の行に影響しない)', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    $other = attachOrganizationMember($organization);
    notifyManualAnalyzed($organization, $owner, $project, $manual);
    notifyManualAnalyzed($organization, $owner, $project, $manual);
    notifyManualAnalyzed($organization, $other, $project, $manual);

    $this->actingAs($owner)->from("/organizations/{$organization->slug}/notifications")
        ->post("/organizations/{$organization->slug}/notifications/read-all")
        ->assertRedirect("/organizations/{$organization->slug}/notifications")
        ->assertSessionHas('success');

    expect($owner->unreadNotifications()->count())->toBe(0);
    expect($other->unreadNotifications()->count())->toBe(1);
});

test('open: manual 現存 + 同一 org → manuals.show へ 303 + 既読化', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/{$id}/open");

    $response->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}");
    expect($owner->unreadNotifications()->count())->toBe(0);
});

test('open: manual 削除済み → 一覧へ 303 + info (既読化はされる)', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);
    $manual->delete();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/{$id}/open")
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/notifications")
        ->assertSessionHas('info', '対象の動画マニュアルは削除されています。');
    expect($owner->unreadNotifications()->count())->toBe(0);
});

test('open: 通知 org ≠ URL 上の組織 → 一覧へ 303 + その組織から開く案内 (自動で組織を変えない)', function (): void {
    // 組織文脈は URL だけで決まる (家系裁定 AG-037)。別組織の URL で開こうとしたときに
    // **URL の組織を勝手に読み替えない**ことを固定する (読み替えは切替の再発明である)。
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);

    [$organization2] = createOrganizationWithOwner('別組織');
    $organization2->users()->attach($owner);
    $owner->addRole(OrganizationRole::Member->value, $organization2->laratrust_team_id);

    $this->actingAs($owner)->post("/organizations/{$organization2->slug}/notifications/{$id}/open")
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization2->slug}/notifications")
        ->assertSessionHas('info', 'この通知は別の組織のものです。その組織の画面から開いてください。');
});

test('open: ticket_balance_low → billing.tickets.show へ 303', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $owner->notify(new TicketBalanceLowNotification(
        $organization->id, new TicketBalanceLowPayload($organization->name, 3, 5),
    ));
    $id = $owner->notifications()->firstOrFail()->getKey();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/{$id}/open")
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/billing/purchase-tickets");
});

test('open: invitation_received → 受諾可能な招待があるときは info を出さず一覧へ 303', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $owner->notify(new InvitationReceivedNotification(
        $organization->id, new InvitationReceivedPayload($organization->name),
    ));
    $id = $owner->notifications()->firstOrFail()->getKey();
    // 一覧に「届いている招待」が出る状態 (受諾の解決と同一 scope で件数を算出する)
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => $owner->email]);

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/{$id}/open")
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/notifications")
        ->assertSessionMissing('info');
});

test('open: invitation_received → 受諾可能な招待が無いときは説明 info を出す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $owner->notify(new InvitationReceivedNotification(
        $organization->id, new InvitationReceivedPayload($organization->name),
    ));
    $id = $owner->notifications()->firstOrFail()->getKey();
    // 取り消し済み = 受諾できない (件数 0 に collapse する)
    OrganizationInvitation::factory()->forOrganization($organization)->revoked()
        ->create(['email' => $owner->email]);

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/{$id}/open")
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/notifications")
        ->assertSessionHas('info', '現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。');
});

test('open: 未知 type → 一覧へ 303 + 汎用 info (招待文言と混同しない)・既読化のみ', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $owner->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => 'legacy_unknown_type', // enum⇔DB ドリフトの防御分岐
        'data' => [],
    ]);
    $id = $owner->notifications()->firstOrFail()->getKey();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/notifications/{$id}/open")
        ->assertStatus(303)
        ->assertRedirect("/organizations/{$organization->slug}/notifications")
        ->assertSessionHas('info', 'この通知には開ける対象がありません。');
    expect($owner->unreadNotifications()->count())->toBe(0);
});

test('GET /organizations/{slug}/notifications/{id}/open は 405 (POST 限定 = prefetch 既読化防止)', function (): void {
    [$organization, $owner, $project, $manual] = notificationCenterContext();
    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/notifications/{$id}/open")->assertStatus(405);
    expect($owner->unreadNotifications()->count())->toBe(1); // 既読化されない
});

test('未認証は login へ redirect / unverified は verified ガード', function (): void {
    [$organization] = createOrganizationWithOwner();
    $this->get("/organizations/{$organization->slug}/notifications")->assertRedirect('/login');

    $unverified = User::factory()->unverified()->create();
    $organization->users()->attach($unverified);
    $this->actingAs($unverified)->get("/organizations/{$organization->slug}/notifications")->assertRedirect('/email/verify');
});
