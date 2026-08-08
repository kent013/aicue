<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Support\Facades\DB;

/*
 * チケット残高低下通知 (施策5)。クロス判定は reserve に置く:
 * 通知が示す残高は Reserved 拘束を含む「実効残高」(= balance())。
 * - 実効残高が閾値を跨ぐ reserve → owner/admin に各 1 件、member には作られない
 * - 既に閾値未満でさらに reserve → 通知されない (クロスのみ)
 * - 複数 pending 予約: 跨いだ 2 件目の reserve でのみ発火。その後の commit (順序不問) は追加なし
 * - release で閾値以上へ回復 → 再度跨ぐ reserve で再通知される
 * - rollback される外側 tx 内の reserve → 通知されない (通知行が外側 tx に乗るため)
 */

beforeEach(function (): void {
    config()->set('billing.ticket_low_balance_threshold', 5);
});

/**
 * owner + admin + member の組織 (台帳 $tickets 枚)。
 *
 * @return array{Organization, User, User, User}
 */
function balanceLowContext(int $tickets = 10): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    if ($tickets > 0) {
        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
    }

    return [$organization, $owner, $admin, $member];
}

function lowBalanceNotificationCountFor(User $user): int
{
    return DB::table('notifications')
        ->where('notifiable_id', $user->id)
        ->where('type', 'ticket_balance_low')
        ->count();
}

test('実効残高が閾値を跨ぐ reserve → owner/admin に各 1 件・member には作られない (payload は実効残高)', function (): void {
    [$organization, $owner, $admin, $member] = balanceLowContext(tickets: 10);

    app(TicketLedgerService::class)->reserve($organization, 6); // 実効残高 10 → 4 (閾値 5 を跨ぐ)

    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
    expect(lowBalanceNotificationCountFor($admin))->toBe(1);
    expect(lowBalanceNotificationCountFor($member))->toBe(0);

    $row = DB::table('notifications')->where('notifiable_id', $owner->id)->firstOrFail();
    expect((int) $row->organization_id)->toBe($organization->id);
    $data = json_decode((string) $row->data, true);
    expect($data['balance'])->toBe(4);      // Reserved 拘束を含む実効残高
    expect($data['threshold'])->toBe(5);
});

test('既に閾値未満の状態でさらに reserve → 通知されない (クロスのみ)', function (): void {
    [$organization, $owner] = balanceLowContext(tickets: 10);

    app(TicketLedgerService::class)->reserve($organization, 6); // 跨ぐ → 1 件
    app(TicketLedgerService::class)->reserve($organization, 2); // 4 → 2 (跨がない)

    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
});

test('複数 pending 予約: 跨いだ 2 件目の reserve でのみ発火。commit は順序を入れ替えても追加なし', function (): void {
    [$organization, $owner] = balanceLowContext(tickets: 10);
    $ledger = app(TicketLedgerService::class);

    $first = $ledger->reserve($organization, 4);  // 10 → 6 (跨がない)
    expect(lowBalanceNotificationCountFor($owner))->toBe(0);
    $second = $ledger->reserve($organization, 4); // 6 → 2 (跨ぐ)
    expect(lowBalanceNotificationCountFor($owner))->toBe(1);

    // commit は拘束と台帳が相殺し balance() 不変 → クロスを発生させない (順序を入れ替えて確認)
    $ledger->commit($second);
    $ledger->commit($first);
    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
    expect($ledger->balance($organization)->totalAvailable())->toBe(2);
});

test('release で閾値以上へ回復 → 再度跨ぐ reserve で再通知される', function (): void {
    [$organization, $owner] = balanceLowContext(tickets: 10);
    $ledger = app(TicketLedgerService::class);

    $ledger->reserve($organization, 4);
    $crossing = $ledger->reserve($organization, 4); // 6 → 2 (跨ぐ) → 1 件
    expect(lowBalanceNotificationCountFor($owner))->toBe(1);

    $ledger->release($crossing); // 実効残高 2 → 6 (回復)
    $ledger->reserve($organization, 4); // 6 → 2 (再度跨ぐ) → 再通知
    expect(lowBalanceNotificationCountFor($owner))->toBe(2);
});

/**
 * 【契約の反転 (AG-114 確定 1 / T137) — 本体は変わらず緑のままだが根拠が変わった】
 * - 旧主張: 通知は DB::afterCommit へ退避され、外側 tx の rollback では発火しない
 * - 旧目的: reserve が pipeline の startJob tx 内から savepoint で呼ばれ得るため、
 *   最外層 commit 成立後にのみ通知する
 * - 新主張: 通知は tx を抜けた最後に同期実行する。外側 tx の内側なら通知行も一緒に巻き戻る
 * - 新前提: TicketBalanceLowNotification は ShouldQueue ではない同期 DB 書き込みであり、
 *   通知行そのものが外側 tx に乗る
 * - 前提を守る機構: QueueDispatchAtomicityInventoryTest (D2 = DB::afterCommit の 0 件 pin)
 * - 反転根拠: DB::afterCommit は「commit したのに未投入」の窓を作る機構であり、AG-127 の
 *   付随的副作用は「tx の外へ出す」であって「afterCommit で温存する」ではない
 */
test('rollback される外側 tx 内の reserve は通知されない (通知行が外側 tx に乗る)', function (): void {
    [$organization, $owner] = balanceLowContext(tickets: 10);

    try {
        DB::transaction(function () use ($organization): void {
            app(TicketLedgerService::class)->reserve($organization, 6); // savepoint 内で跨ぐ
            throw new RuntimeException('外側 tx を rollback させる');
        });
    } catch (RuntimeException) {
        // 想定どおり
    }

    expect(lowBalanceNotificationCountFor($owner))->toBe(0);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(10); // reserve ごと巻き戻る
});

test('grant で回復して再度跨ぐ場合も再通知される', function (): void {
    [$organization, $owner] = balanceLowContext(tickets: 6);
    $ledger = app(TicketLedgerService::class);

    $ledger->reserve($organization, 2); // 6 → 4 (跨ぐ) → 1 件
    expect(lowBalanceNotificationCountFor($owner))->toBe(1);

    $ledger->grant($organization, 5, '追加購入'); // 実効残高 4 → 9 (回復)
    $ledger->reserve($organization, 5); // 9 → 4 (再度跨ぐ)
    expect(lowBalanceNotificationCountFor($owner))->toBe(2);
});
