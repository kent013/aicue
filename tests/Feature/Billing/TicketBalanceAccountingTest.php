<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\TicketBalanceDto;
use App\Enums\Billing\TicketCommitResult;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketLedgerEntry;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;

/*
 * P5: per-source 会計 (aigenba TicketService::balance verbatim 移植)。
 * バケット = monthly (source=monthly) / purchased (source=purchased ∪ source IS NULL)。
 * 消費行に grant と同じ expires_at を載せることで「+grant と −consume が同時に落ちる」。
 */

function accountingService(): TicketLedgerService
{
    return app(TicketLedgerService::class);
}

test('期限付き monthly の grant と消費が同時に失効する (全額失効近似の解消)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $expiresAt = CarbonImmutable::now()->addDays(30);
    accountingService()->grantMonthly($organization, 10, $expiresAt, 'monthly:1', '月次付与');

    $reservation = accountingService()->reserve($organization, 3);
    accountingService()->commit($reservation);

    // 消費行は grant と同じ失効境界を持つ
    $consume = TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->firstOrFail();
    expect($consume->source)->toBe(TicketSource::Monthly);
    expect($consume->expires_at?->toIso8601String())->toBe($expiresAt->toIso8601String());

    expect(accountingService()->balance($organization)->monthlyRemaining)->toBe(7);

    // 期限到達で +10 と -3 が同時に合算から落ちる (現行実装なら -3 が残り -3 になる)
    $this->travelTo($expiresAt->addMinute());
    $balance = accountingService()->balance($organization);
    expect($balance->monthlyRemaining)->toBe(0);
    expect($balance->totalAvailable())->toBe(0);
});

test('balance は per-source DTO を返し debt フィールドを持たない', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grant($organization, 5, '手動付与');

    $balance = accountingService()->balance($organization);

    expect($balance)->toBeInstanceOf(TicketBalanceDto::class);
    expect(array_keys($balance->toArray()))->toBe([
        'monthlyRemaining',
        'purchasedRemaining',
        'totalAvailable',
        'activeReservations',
        'nextExpireAt',
    ]);
});

test('per-source clamp: purchased の債務を monthly が肩代わりも打ち消しもしない', function (): void {
    [$organization] = createOrganizationWithOwner();
    // purchased を -2 にする (返金逆仕訳相当の負計上)
    accountingService()->grantPurchased($organization, 3, 'cs_clamp', 'pi_clamp', 3000);
    $reservation = accountingService()->reserve($organization, 3);
    accountingService()->commit($reservation);
    accountingService()->clawbackPurchasedByPaymentIntent('pi_clamp', 3000);

    accountingService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:clamp', '月次付与');

    $balance = accountingService()->balance($organization);
    expect($balance->purchasedRemaining)->toBe(0); // max(-3, 0)
    expect($balance->monthlyRemaining)->toBe(10);
    expect($balance->totalAvailable())->toBe(10);

    // 台帳側では債務が保全されている (clamp は表示・与信のみ)
    $purchasedNetRaw = (int) TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('source', TicketSource::Purchased)
        ->sum('delta');
    expect($purchasedNetRaw)->toBe(-3);
});

test('source IS NULL の台帳行は purchased バケットへ畳まれる (過去消費が帳消しにならない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grantPurchased($organization, 10, 'cs_null', 'pi_null', 10000);

    // P5 以前の消費行相当 (source = null) を append-only で 1 行足す
    $legacyConsume = new TicketLedgerEntry;
    $legacyConsume->organization()->associate($organization);
    $legacyConsume->delta = -4;
    $legacyConsume->kind = TicketLedgerKind::ReserveCommit;
    $legacyConsume->description = 'P5 以前の消費行 (source なし)';
    $legacyConsume->save();

    $balance = accountingService()->balance($organization);
    expect($balance->purchasedRemaining)->toBe(6); // 帳消しにせず purchased へ畳む
    expect($balance->totalAvailable())->toBe(6);
});

test('nextExpireAt は未失効・正 delta の最短 expires_at (ISO8601)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $near = CarbonImmutable::now()->addDays(10);
    $far = CarbonImmutable::now()->addDays(30);
    accountingService()->grantMonthly($organization, 5, $far, 'monthly:far', '遠い期限');
    accountingService()->grantMonthly($organization, 5, $near, 'monthly:near', '近い期限');
    accountingService()->grantPurchased($organization, 5, 'cs_inf', 'pi_inf', 5000); // 無期限は対象外

    expect(accountingService()->balance($organization)->nextExpireAt)
        ->toBe($near->toIso8601String());
});

test('activeReservations は拘束枚数 (SUM(amount))', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grant($organization, 10, '初期付与');

    accountingService()->reserve($organization, 3);
    accountingService()->reserve($organization, 2);

    $balance = accountingService()->balance($organization);
    expect($balance->activeReservations)->toBe(5); // count(2) ではなく枚数
    expect($balance->totalAvailable())->toBe(5);
});

test('無期限 monthly grant のみの組織でも reserve が例外にならず consume_expires_at が null で固定される', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grantMonthly($organization, 100, null, 'monthly:infinite', '無期限月次付与');

    $reservation = accountingService()->reserve($organization, 3);

    expect($reservation->consume_source)->toBe(TicketSource::Monthly);
    expect($reservation->consume_expires_at)->toBeNull();
});

test('availableTrueBalance は per-source clamp 後の合算で常に 0 以上', function (): void {
    [$organization] = createOrganizationWithOwner();
    accountingService()->grantPurchased($organization, 3, 'cs_true', 'pi_true', 3000);
    $reservation = accountingService()->reserve($organization, 3);
    accountingService()->commit($reservation);
    accountingService()->clawbackPurchasedByPaymentIntent('pi_true', 3000);
    accountingService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:true', '月次付与');

    expect(accountingService()->availableTrueBalance($organization))->toBe(10);
});

/*
 * ── 既知窓 (aigenba verbatim を維持した結果の設計上の残余リスク) ───────────────────
 *
 * reserve は消費境界に `nearestMonthlyExpiry` (= 生きた monthly grant の最短期限) を 1 値で固定する
 * (設計書 P5「reserve」節。分割配賦 consume_monthly_amount は v1 の発明として撤回済み)。
 * このため **生きた有限期限 monthly grant が 2 本以上あり期限が異なる**とき、消費行の expires_at が
 * 実際の供給元と一致せず、
 *   (a) 最短期限の到達時に消費行が grant より多く落ちて over-grant が残る
 *   (b) 最短期限を跨ぐ長時間ジョブの commit が ReleasedExpired になり、残高が潤沢でも no-charge になる
 * が起きる。aigenba は amount=1 固定のためズレが最大 1 枚だったが、AI-CUE の amount 一般化で
 * 最大 `amount - (最短期限バケットの残高)` 枚まで増幅する。
 *
 * **現行の AI-CUE では構造的に到達不能**: D28 により全 tier の monthly_ticket_grant は 0
 * (PlanSeederPriceInvariantTest が pin) で、有限期限の monthly は org 生涯 1 回の signup grant のみ。
 * BughuntBillingSeeder の 100 枚は無期限 (expires_at IS NULL) で nearestMonthlyExpiry の対象外。
 * よって「生きた有限期限 monthly が 2 本」は Filament PlanResource で monthly_ticket_grant を
 * 戻したときにだけ成立する。
 *
 * 以下 2 本は**現行挙動を機械的に固定して窓を可視化する**ための pin テストであり、
 * 「正しい会計」を主張するものではない (正値はコメントに併記する)。窓を閉じるには expiry 粒度の
 * 配賦が要り、それは設計の撤回済み案の復活 = 設計改訂事項なので本タスクでは変更しない。
 */

test('[既知窓] 生きた monthly 期限が複数あると消費行が最短期限に固定され失効時に over-grant が残る', function (): void {
    [$organization] = createOrganizationWithOwner();
    $nearExpiry = CarbonImmutable::now()->addDays(10);
    $farExpiry = CarbonImmutable::now()->addDays(30);
    // 最短期限バケットは 1 枚しか供給できないが、reserve(3) はこの期限を消費境界に採る
    accountingService()->grantMonthly($organization, 1, $nearExpiry, 'monthly:window-near', '月次付与 (近い期限)');
    accountingService()->grantMonthly($organization, 100, $farExpiry, 'monthly:window-far', '月次付与 (遠い期限)');

    $reservation = accountingService()->reserve($organization, 3);
    expect($reservation->consume_expires_at?->toIso8601String())->toBe($nearExpiry->toIso8601String());
    accountingService()->commit($reservation);

    // 失効前は正しい (1 + 100 - 3)
    expect(accountingService()->balance($organization)->monthlyRemaining)->toBe(98);

    // 最短期限の到達で +1 と -3 が同時に落ちる。会計上の正値は 98 (遠い期限バケットから 2 枚消費済) だが、
    // 単一 consume_expires_at では 100 になる = 2 枚の over-grant が残る (aigenba verbatim の既知窓)
    $this->travelTo($nearExpiry->addMinute());
    expect(accountingService()->balance($organization)->monthlyRemaining)->toBe(100);
});

test('[既知窓] 最短 monthly 期限を跨ぐ commit は残高が潤沢でも ReleasedExpired で no-charge になる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $nearExpiry = CarbonImmutable::now()->addMinutes(10);
    $farExpiry = CarbonImmutable::now()->addDays(30);
    accountingService()->grantMonthly($organization, 1, $nearExpiry, 'monthly:cross-near', '月次付与 (近い期限)');
    accountingService()->grantMonthly($organization, 100, $farExpiry, 'monthly:cross-far', '月次付与 (遠い期限)');

    $reservation = accountingService()->reserve($organization, 3);
    $this->travelTo($nearExpiry->addMinute());

    // 遠い期限バケットに 100 枚生きているが、hold の境界は最短期限に固定済のため決定的 no-charge になる
    expect(accountingService()->commit($reservation))->toBe(TicketCommitResult::ReleasedExpired);
    expect(TicketLedgerEntry::query()
        ->where('organization_id', $organization->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->count())->toBe(0);
    expect(accountingService()->balance($organization)->monthlyRemaining)->toBe(100);
});
