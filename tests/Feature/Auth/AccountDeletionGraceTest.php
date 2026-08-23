<?php

declare(strict_types=1);

use App\Enums\Notification\NotificationType;
use App\Enums\OrganizationRole;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Notifications\Account\AccountDeletionRequestedNotification;
use App\Services\Organization\OrganizationMembershipService;
use App\Support\Account\AccountDeletionGrace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * 猶予期間つき退会 (**凍結方式**) の予約 / 取消 / 執行 (T142 / 標準形 v1 の必須 (2))。
 *
 * 凍結中のアクセス制限そのものは tests/Feature/Auth/AccountDeletionFreezeTest.php、
 * 即時削除 (副導線) の既存挙動は tests/Feature/Auth/AccountDeletionTest.php が担当する
 * (**既存 16 本は 1 行も変更していない** = 予約と即時削除は併存する)。
 */

/** 予約中の users 行を DB から素で読む (cast を経由せず列の生値を見る)。 */
function deletionColumns(User $user): object
{
    $row = DB::table('users')
        ->select(['deletion_requested_at', 'deletion_purge_after'])
        ->where('id', $user->id)
        ->first();
    expect($row)->not->toBeNull();

    return (object) $row;
}

// ── B1: 予約列と DB 制約 ─────────────────────────────────────────────────

test('users の予約列は既定で両方 null (未予約が既定状態)', function (): void {
    $user = User::factory()->create();

    $columns = deletionColumns($user);
    expect($columns->deletion_requested_at)->toBeNull();
    expect($columns->deletion_purge_after)->toBeNull();
});

test('UserFactory::pendingDeletion() が両列を猶予日数どおりに埋める', function (): void {
    $requestedAt = CarbonImmutable::parse('2026-06-01 10:00:00');
    $user = User::factory()->pendingDeletion($requestedAt)->create();

    expect($user->deletion_requested_at?->toDateTimeString())->toBe('2026-06-01 10:00:00');
    expect($user->deletion_purge_after?->toDateTimeString())
        ->toBe(AccountDeletionGrace::purgeAfter($requestedAt)->toDateTimeString());
});

test('予約列は mass-assignment で書けない (保護列)', function (): void {
    $user = User::factory()->create();

    // $fillable 外のため fill() は例外になる (silently discard も許さない strict モード)。
    expect(fn () => $user->fill([
        'deletion_requested_at' => CarbonImmutable::now(),
        'deletion_purge_after' => CarbonImmutable::now()->addDays(30),
    ]))->toThrow(MassAssignmentException::class);

    $columns = deletionColumns($user->fresh() ?? $user);
    expect($columns->deletion_requested_at)->toBeNull();
    expect($columns->deletion_purge_after)->toBeNull();
});

test('片列だけの UPDATE を DB が拒否する (アプリ層を迂回しても守られる)', function (): void {
    $user = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $user->id)->update([
        'deletion_requested_at' => CarbonImmutable::now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('users')->where('id', $user->id)->update([
        'deletion_purge_after' => CarbonImmutable::now(),
    ]))->toThrow(QueryException::class);
});

test('deletion_purge_after が deletion_requested_at より前の行を DB が拒否する', function (): void {
    $user = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $user->id)->update([
        'deletion_requested_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
        'deletion_purge_after' => CarbonImmutable::parse('2026-05-31 10:00:00'),
    ]))->toThrow(QueryException::class);
});

// ── B2: Service (予約 / 取消 / 執行) ────────────────────────────────────

test('予約すると両列が入り SecurityEvent account_deletion_requested が 1 件記録される', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $state = app(OrganizationMembershipService::class)->requestAccountDeletion($owner);

    expect($state->isPending())->toBeTrue();
    expect($state->graceDays())->toBe(AccountDeletionGrace::days());
    expect(SecurityAuditEvent::query()->where('event_type', 'account_deletion_requested')->count())->toBe(1);
    // users 行は生きている (凍結方式 = 生死を変えない)
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

test('二重予約しても purge_after が延びない (冪等 no-op)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $membership = app(OrganizationMembershipService::class);

    $first = $membership->requestAccountDeletion($owner);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(3));
    $second = $membership->requestAccountDeletion($owner);
    CarbonImmutable::setTestNow();

    expect($second->purgeAfter?->toDateTimeString())->toBe($first->purgeAfter?->toDateTimeString());
    expect($second->requestedAt?->toDateTimeString())->toBe($first->requestedAt?->toDateTimeString());
    // 監査イベントも 1 件のまま (no-op は記録しない)
    expect(SecurityAuditEvent::query()->where('event_type', 'account_deletion_requested')->count())->toBe(1);
});

test('取消で両列が null になり SecurityEvent account_deletion_cancelled が記録される', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $membership = app(OrganizationMembershipService::class);
    $membership->requestAccountDeletion($owner);

    $state = $membership->cancelAccountDeletion($owner);

    expect($state->isPending())->toBeFalse();
    $columns = deletionColumns($owner);
    expect($columns->deletion_requested_at)->toBeNull();
    expect($columns->deletion_purge_after)->toBeNull();
    expect(SecurityAuditEvent::query()->where('event_type', 'account_deletion_cancelled')->count())->toBe(1);
});

test('取消は冪等 (未予約でも例外にならず no-op)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $state = app(OrganizationMembershipService::class)->cancelAccountDeletion($owner);

    expect($state->isPending())->toBeFalse();
    expect(SecurityAuditEvent::query()->where('event_type', 'account_deletion_cancelled')->count())->toBe(0);
});

test('退会ブロッカーがあっても予約できる (予約時にブロッカーを評価しない契約)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin); // 孤児化する残存メンバー

    // ★ここで例外になると「解約待ちの間は退会予約もできない」詰みになる
    $state = app(OrganizationMembershipService::class)->requestAccountDeletion($owner);

    expect($state->isPending())->toBeTrue();
});

test('退会ブロッカーがあっても取消できる (救済経路に条件を付けない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $membership = app(OrganizationMembershipService::class);
    $membership->requestAccountDeletion($owner);
    attachOrganizationMember($organization, OrganizationRole::Admin);

    expect($membership->cancelAccountDeletion($owner)->isPending())->toBeFalse();
});

test('執行は期限到来なら削除し、未到来なら false で何も変えない', function (): void {
    // 1 秒境界を測るので時計を固定する (実行時間が 1 秒を超えると未到来が到来に化けるため)。
    // Laravel の TestCase::tearDown が setTestNow をリセットする。
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
    $membership = app(OrganizationMembershipService::class);

    $due = User::factory()->pendingDeletion(
        CarbonImmutable::now()->subDays(31),
        CarbonImmutable::now()->subSecond(),
    )->create();
    $notDue = User::factory()->pendingDeletion(
        CarbonImmutable::now()->subDays(29),
        CarbonImmutable::now()->addSecond(),
    )->create();

    expect($membership->executeAccountDeletionRequest($due))->toBeTrue();
    expect(User::query()->whereKey($due->id)->exists())->toBeFalse();

    expect($membership->executeAccountDeletionRequest($notDue))->toBeFalse();
    expect(User::query()->whereKey($notDue->id)->exists())->toBeTrue();
    expect(deletionColumns($notDue)->deletion_purge_after)->not->toBeNull();
});

test('期限 < 予約時刻の非正規な組は執行されない (fail-closed)', function (): void {
    // CHECK 制約が壊れた状況の再現。isDue が isPending だけを見ていると
    // 「猶予が経過していないのに物理削除する」向きに倒れる (fail-open)。
    $user = User::factory()->create();
    DB::statement('ALTER TABLE users DROP CONSTRAINT users_deletion_purge_after_order_check');
    DB::table('users')->where('id', $user->id)->update([
        'deletion_requested_at' => CarbonImmutable::now()->addDays(10),
        'deletion_purge_after' => CarbonImmutable::now()->subDay(),
    ]);

    expect(app(OrganizationMembershipService::class)->executeAccountDeletionRequest($user->refresh()))
        ->toBeFalse();
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('抽出後に取消されたユーザーは執行されない (ロック下の再確認)', function (): void {
    $membership = app(OrganizationMembershipService::class);
    $user = User::factory()->pendingDeletion(
        CarbonImmutable::now()->subDays(31),
        CarbonImmutable::now()->subSecond(),
    )->create();

    // バッチが抽出した後に本人が取り消した状況を作る (in-memory の $user は古いまま)
    DB::table('users')->where('id', $user->id)->update([
        'deletion_requested_at' => null,
        'deletion_purge_after' => null,
    ]);

    expect($membership->executeAccountDeletionRequest($user))->toBeFalse();
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('抽出後に取消されたユーザーはブロッカーがあっても例外にならない (保留と誤分類しない)', function (): void {
    // ★precondition をブロッカー判定の**後**へ動かすとこのテストが赤くなる (M8)。
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin); // ブロッカーを立てる
    $membership = app(OrganizationMembershipService::class);
    $membership->requestAccountDeletion($owner);
    DB::table('users')->where('id', $owner->id)->update([
        'deletion_requested_at' => null,
        'deletion_purge_after' => null,
    ]);

    expect($membership->executeAccountDeletionRequest($owner))->toBeFalse();
});

// ── B3: HTTP (予約 / 取消) ──────────────────────────────────────────────

test('step-up なしでは予約できない', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/settings/account/deletion-request');

    $response->assertRedirect();
    expect(deletionColumns($user)->deletion_requested_at)->toBeNull();
});

test('step-up 済みなら予約でき flash が出る', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->from('/settings')
        ->post('/settings/account/deletion-request');

    $response->assertRedirect('/settings');
    $response->assertSessionHas('success');
    expect(deletionColumns($owner)->deletion_purge_after)->not->toBeNull();
});

test('取消は step-up 無しでもできる (誤操作救済の本体)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);

    $response = $this->actingAs($owner)
        ->from('/settings')
        ->delete('/settings/account/deletion-request');

    $response->assertRedirect('/settings');
    $response->assertSessionHas('success', '退会の予約を取り消しました。');
    expect(deletionColumns($owner)->deletion_requested_at)->toBeNull();
});

test('未認証は予約 / 取消のどちらもログインへ倒れる', function (): void {
    $this->post('/settings/account/deletion-request')->assertRedirect('/login');
    $this->delete('/settings/account/deletion-request')->assertRedirect('/login');
});

test('予約 / 取消 route は他者を指せる parameter を持たない (構造的な自己スコープ)', function (): void {
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    foreach ([
        'settings.account.deletion-request.store',
        'settings.account.deletion-request.destroy',
    ] as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull();
        expect($route?->parameterNames())->toBe([]);
    }
});

// ── B7: 画面 props ─────────────────────────────────────────────────────

test('/settings が退会予約の状態と猶予日数を props で返す', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn ($page) => $page
            ->where('accountDeletionState.requestedAt', null)
            ->where('accountDeletionState.purgeAfter', null)
            ->where('accountDeletionGraceDays', AccountDeletionGrace::days()));

    $state = app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
    // actingAs は in-memory の User インスタンスをそのまま認証主体にするため、
    // Service 内の $fresh への書き込みを反映させてから叩く (実リクエストは毎回 DB から読む)。
    $owner->refresh();

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn ($page) => $page
            ->where('accountDeletionState.purgeAfter', $state->purgeAfter?->toIso8601String())
            ->where('accountDeletionState.graceDays', AccountDeletionGrace::days()));
});

// ── B6: 通知・監査 ─────────────────────────────────────────────────────

test('予約でメール通知が 1 通だけキューされる', function (): void {
    Notification::fake();
    [, $owner] = createOrganizationWithOwner();

    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);

    Notification::assertSentToTimes($owner, AccountDeletionRequestedNotification::class, 1);
});

test('退会予約ではアプリ内通知を作らない (org 文脈を捏造しない。メールは従来どおり届く)', function (): void {
    $user = User::factory()->create();

    app(OrganizationMembershipService::class)->requestAccountDeletion($user);

    expect(DB::table('notifications')
        ->where('type', NotificationType::AccountDeletionRequested->value)
        ->count())->toBe(0);
    // 予約そのものは成立している (通知の有無が予約を左右しない)
    expect(deletionColumns($user)->deletion_purge_after)->not->toBeNull();
});

test('予約 POST を 2 回叩いてもメール通知は 1 通 (一回性は永続状態遷移が担う)', function (): void {
    Notification::fake();
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->withSession(freshRecentAuthSession())
        ->post('/settings/account/deletion-request');
    $this->actingAs($owner)->withSession(freshRecentAuthSession())
        ->post('/settings/account/deletion-request');

    Notification::assertSentToTimes($owner, AccountDeletionRequestedNotification::class, 1);
});

test('予約 → 即取消 のあとに通知 job を実行してもメールは送られない (via の再確認)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $membership = app(OrganizationMembershipService::class);
    $state = $membership->requestAccountDeletion($owner);
    $requestedAt = $state->requestedAt;
    $purgeAfter = $state->purgeAfter;
    expect($requestedAt)->not->toBeNull();
    expect($purgeAfter)->not->toBeNull();

    $membership->cancelAccountDeletion($owner);

    $notification = new AccountDeletionRequestedNotification($requestedAt, $purgeAfter);
    expect($notification->via($owner->fresh() ?? $owner))->toBe([]);
});

test('値が変わった再予約では古い通知 job が送られない (同一秒内の再予約は区別しない)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $membership = app(OrganizationMembershipService::class);
    $old = $membership->requestAccountDeletion($owner);
    expect($old->requestedAt)->not->toBeNull();
    expect($old->purgeAfter)->not->toBeNull();

    $membership->cancelAccountDeletion($owner);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    $membership->requestAccountDeletion($owner);
    CarbonImmutable::setTestNow();

    $stale = new AccountDeletionRequestedNotification($old->requestedAt, $old->purgeAfter);
    expect($stale->via($owner->fresh() ?? $owner))->toBe([]);

    // ★保証範囲を誇張しない: 同一秒内に取消 → 再予約すると組が一致するため区別できない。
    //   ただしその場合の期日は新旧で同一なので、誤った期日が届くことはない。
    $membership->cancelAccountDeletion($owner);
    $again = $membership->requestAccountDeletion($owner);
    expect($again->requestedAt)->not->toBeNull();
    expect($again->purgeAfter)->not->toBeNull();
    $sameSecond = new AccountDeletionRequestedNotification($again->requestedAt, $again->purgeAfter);
    expect($sameSecond->via($owner->fresh() ?? $owner))->toBe(['mail']);
});

test('執行済み (user 削除済み) の通知 job は送られない (fresh() が null)', function (): void {
    $user = User::factory()->pendingDeletion()->create();
    $requestedAt = $user->deletion_requested_at;
    $purgeAfter = $user->deletion_purge_after;
    expect($requestedAt)->not->toBeNull();
    expect($purgeAfter)->not->toBeNull();

    $notification = new AccountDeletionRequestedNotification($requestedAt, $purgeAfter);
    expect($notification->via($user))->toBe(['mail']);

    $user->delete();

    // ★`fresh() ?? $notifiable` のフォールバックへ戻すとここが赤くなる (M23)
    expect($notification->via($user))->toBe([]);
});

test('予約中のユーザーへの通知 job は送られる (正のコントロール)', function (): void {
    $user = User::factory()->pendingDeletion()->create();
    $requestedAt = $user->deletion_requested_at;
    $purgeAfter = $user->deletion_purge_after;
    expect($requestedAt)->not->toBeNull();
    expect($purgeAfter)->not->toBeNull();

    $notification = new AccountDeletionRequestedNotification($requestedAt, $purgeAfter);
    expect($notification->via($user->fresh() ?? $user))->toBe(['mail']);
});
