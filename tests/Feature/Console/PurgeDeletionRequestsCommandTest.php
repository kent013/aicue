<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Notifications\Account\AccountDeletionRequestedNotification;
use App\Services\Billing\AccountDeletionBillingGuard;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Notification\NotificationCenterService;
use App\Services\Organization\OrganizationMembershipService;
use App\Services\Project\DefaultProjectResolver;
use App\Services\Security\SecurityEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/*
 * 退会予約の日次執行バッチ (`account:purge-deletion-requests`)。
 *
 * 終了コードの契約 (2 分類):
 *   - 退会ブロッカー (ValidationException) = **業務上の保留**。予約は維持し SUCCESS のまま次へ
 *   - インフラ障害 / 不変条件違反 = **想定外**。走査は続けるが FAILURE で終わる
 */

/** 期限到来済みの予約ユーザー。 */
function dueUser(): User
{
    return User::factory()->pendingDeletion(
        CarbonImmutable::now()->subDays(31),
        CarbonImmutable::now()->subSecond(),
    )->create();
}

test('dry-run は 1 人も削除しない', function (): void {
    $user = dueUser();

    $this->artisan('account:purge-deletion-requests')
        ->expectsOutputToContain('due=1 deleted=0')
        ->assertExitCode(SymfonyCommand::SUCCESS);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('--apply で期限到来ユーザーが削除され、未到来は残る (境界: 1 秒前 / 1 秒後)', function (): void {
    // 1 秒境界を測るので時計を固定する (実行時間が 1 秒を超えると未到来が到来に化けるため)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
    $due = dueUser();
    $notDue = User::factory()->pendingDeletion(
        CarbonImmutable::now()->subDays(29),
        CarbonImmutable::now()->addSecond(),
    )->create();

    $this->artisan('account:purge-deletion-requests --apply')
        ->assertExitCode(SymfonyCommand::SUCCESS);

    expect(User::query()->whereKey($due->id)->exists())->toBeFalse();
    expect(User::query()->whereKey($notDue->id)->exists())->toBeTrue();
});

test('抽出後に取り消されたユーザーは削除されない', function (): void {
    $user = dueUser();
    // 抽出とロック取得の間に取消された状況の代理として、コマンド実行前に列を消す
    DB::table('users')->where('id', $user->id)->update([
        'deletion_requested_at' => null,
        'deletion_purge_after' => null,
    ]);

    $this->artisan('account:purge-deletion-requests --apply')
        ->expectsOutputToContain('due=0')
        ->assertExitCode(SymfonyCommand::SUCCESS);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('同日 2 回実行しても二重削除・二重通知が起きない', function (): void {
    Notification::fake();
    $user = dueUser();

    $this->artisan('account:purge-deletion-requests --apply')->assertExitCode(SymfonyCommand::SUCCESS);
    $this->artisan('account:purge-deletion-requests --apply')
        ->expectsOutputToContain('due=0 deleted=0')
        ->assertExitCode(SymfonyCommand::SUCCESS);

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
    Notification::assertNothingSentTo($user);
    Notification::assertNotSentTo([$user], AccountDeletionRequestedNotification::class);
});

test('1 人目でブロッカー例外が出ても 2 人目は削除される (失敗分離・SUCCESS)', function (): void {
    Exceptions::fake();
    // ブロッカー付き (唯一 Owner + 他メンバーが残る) の予約ユーザーを先に作る
    [$organization, $blockedOwner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin);
    DB::table('users')->where('id', $blockedOwner->id)->update([
        'deletion_requested_at' => CarbonImmutable::now()->subDays(31),
        'deletion_purge_after' => CarbonImmutable::now()->subSecond(),
    ]);
    $deletable = dueUser();

    $this->artisan('account:purge-deletion-requests --apply')
        ->expectsOutputToContain('due=2 deleted=1 blocked=1 unexpected=0')
        // ★ブロッカーだけなら終了コードは SUCCESS (業務上の保留であって障害ではない)
        ->assertExitCode(SymfonyCommand::SUCCESS);

    expect(User::query()->whereKey($blockedOwner->id)->exists())->toBeTrue();
    expect(User::query()->whereKey($deletable->id)->exists())->toBeFalse();
    // ブロックされたユーザーの予約は維持される (翌日また試す)
    expect(User::query()->whereKey($blockedOwner->id)->first()?->deletion_purge_after)->not->toBeNull();
    // 保留も report される (SUCCESS だが運用者が気づけること = 監視契約の一部)。
    // ★ValidationException を素で report しても Laravel の既定 dontReport が握り潰す (実測) ため、
    //   件数を載せた RuntimeException に集約している。ここが緑であることがその実装の証拠になる。
    Exceptions::assertReported(
        fn (RuntimeException $reported): bool => $reported->getMessage() === '退会予約の執行を保留 (退会ブロッカーあり): count=1',
    );
});

test('想定外例外が 1 件でもあれば report + FAILURE になり、走査は最後まで続く', function (): void {
    Exceptions::fake();
    dueUser();
    dueUser();

    $this->instance(OrganizationMembershipService::class, new class(app(SecurityEventRecorder::class), app(DefaultProjectResolver::class), app(NotificationCenterService::class), app(AccountDeletionBillingGuard::class)) extends OrganizationMembershipService
    {
        public function executeAccountDeletionRequest(User $user): bool
        {
            throw new RuntimeException('インフラ障害の代理');
        }
    });

    // ★終了コードを常に SUCCESS にすると赤くなる (M7)
    $this->artisan('account:purge-deletion-requests --apply')
        ->expectsOutputToContain('due=2 deleted=0 blocked=0 unexpected=2')
        ->assertExitCode(SymfonyCommand::FAILURE);

    // 監視契約は「終了コード + report()」の 2 本立てなので report() 側も固定する
    // (終了コードだけを見ていると report を消しても緑のままになる)。
    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(
        fn (RuntimeException $reported): bool => $reported->getMessage() === 'インフラ障害の代理',
    );
});

test('片列だけの非正規行があれば report + FAILURE になり、その行は削除もされない', function (): void {
    Exceptions::fake();
    $user = User::factory()->create();
    // CHECK 制約が無効化された / DB が壊れた状況の再現 (defense-in-depth の検証)
    DB::statement('ALTER TABLE users DROP CONSTRAINT users_deletion_request_pair_check');
    DB::table('users')->where('id', $user->id)->update([
        'deletion_purge_after' => CarbonImmutable::now()->subDay(),
    ]);

    // ★抽出条件から whereNotNull('deletion_requested_at') を外すと due=1 になり赤くなる (M20)
    $this->artisan('account:purge-deletion-requests --apply')
        ->expectsOutputToContain('due=0 deleted=0 blocked=0 unexpected=1')
        ->assertExitCode(SymfonyCommand::FAILURE);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
    Exceptions::assertReported(
        fn (RuntimeException $reported): bool => str_contains($reported->getMessage(), '退会予約列が非正規な行を検出: count=1'),
    );
});

test('期限 < 予約時刻の非正規行は削除されず report + FAILURE になる (fail-closed)', function (): void {
    Exceptions::fake();
    // CHECK 制約 (順序) が無効化された状況の再現。両列とも埋まっており期限は過去なので、
    // 順序の検査が無いと **猶予が経過していないのに物理削除される** (fail-open)。
    $user = User::factory()->create();
    DB::statement('ALTER TABLE users DROP CONSTRAINT users_deletion_purge_after_order_check');
    DB::table('users')->where('id', $user->id)->update([
        'deletion_requested_at' => CarbonImmutable::now()->addDays(10),
        'deletion_purge_after' => CarbonImmutable::now()->subDay(),
    ]);

    $this->artisan('account:purge-deletion-requests --apply')
        ->expectsOutputToContain('due=0 deleted=0 blocked=0 unexpected=1')
        ->assertExitCode(SymfonyCommand::FAILURE);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
    Exceptions::assertReported(
        fn (RuntimeException $reported): bool => str_contains($reported->getMessage(), '退会予約列が非正規な行を検出: count=1'),
    );
});

test('決済事業者 API を 1 回も呼ばない (解約を代行しない)', function (): void {
    // 課金責務のある組織 (ブロッカー) と、素直に消えるユーザーの両方を通す
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');
    DB::table('users')->where('id', $owner->id)->update([
        'deletion_requested_at' => CarbonImmutable::now()->subDays(31),
        'deletion_purge_after' => CarbonImmutable::now()->subSecond(),
    ]);
    dueUser();
    // mock は「1 度も呼ばれない」ことを期待する (呼ばれたら Mockery が fail させる)。
    // 外向き HTTP 自体はレーン既定の StrayHttpRequestGuard が拒否する。
    $this->mock(StripeGatewayInterface::class);

    $this->artisan('account:purge-deletion-requests --apply')
        ->assertExitCode(SymfonyCommand::SUCCESS);
});

test('出力に user id / email が出ない (件数のみ)', function (): void {
    $user = dueUser();
    $email = $user->email;

    $this->artisan('account:purge-deletion-requests --apply')
        ->doesntExpectOutputToContain((string) $email)
        ->doesntExpectOutputToContain('id='.$user->id)
        ->assertExitCode(SymfonyCommand::SUCCESS);
});

test('日次スケジュールに --apply つきで登録されている', function (): void {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'account:purge-deletion-requests'))
        ->values();

    expect($commands)->toHaveCount(1);
    expect($commands->first())->toContain('--apply');
});
