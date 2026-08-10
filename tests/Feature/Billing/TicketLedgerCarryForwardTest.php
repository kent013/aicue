<?php

declare(strict_types=1);

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\TicketLedgerCarryForwardService;
use App\Services\Billing\TicketLedgerService;
use App\Support\Legal\BillingRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * 保持期間 (7 年) の台帳畳み込み (PR-C2 / C2b) の挙動。
 *
 * ★畳み込みは**会計上の残高を保存する操作**である。1 枚でも増減したら重大な不具合なので、
 *   「畳み込み前後で 7 種の観測値が一致する」ことを本ファイルが機械固定する
 *   (詳細設計 C2b の検証 1〜7)。
 *
 * ★繰越行は「取引記録」ではなく**現在残高のスナップショット**である。原取引の識別子
 *   (説明 / stripe id / payment intent / 予約 id / 冪等キー) は 1 つも引き継がない
 *   — 引き継ぐと「7 年より古い取引の情報が残る」ことになり保持期間の意味が消える。
 */

/**
 * 台帳の残高粒度ごとの合計 (organization_id / source / expires_at)。
 *
 * **合計 0 の group は落とす**。畳み込みは残高に寄与しない行を作らないため、
 * 「0 の group が消えること」は残高の変化ではない。
 *
 * @return array<string, int>
 */
function ledgerBalanceByGroup(): array
{
    $totals = [];
    foreach (TicketLedgerEntry::query()->get() as $entry) {
        $key = implode('|', [
            $entry->organization_id,
            $entry->source?->value ?? 'null',
            $entry->expires_at?->toIso8601String() ?? 'null',
        ]);
        $totals[$key] = ($totals[$key] ?? 0) + $entry->delta;
    }

    ksort($totals);

    return array_filter($totals, static fn (int $total): bool => $total !== 0);
}

/**
 * 組織ごとの表示残高 + 与信残高。
 *
 * @return array<int, array{monthly: int, purchased: int, holds: int, available: int}>
 */
function ledgerBalancesByOrganization(): array
{
    $service = app(TicketLedgerService::class);
    $out = [];
    foreach (Organization::query()->orderBy('id')->get() as $organization) {
        $balance = $service->balance($organization);
        $id = $organization->getKey();
        expect($id)->toBeInt();
        $out[$id] = [
            'monthly' => $balance->monthlyRemaining,
            'purchased' => $balance->purchasedRemaining,
            'holds' => $balance->activeReservations,
            'available' => $service->availableTrueBalance($organization),
        ];
    }

    return $out;
}

/**
 * 3 組織ぶんの「7 年より古い取引 + 新しい取引」を並べる。
 *
 * @return array{Organization, Organization, Organization}
 */
function seedCarryForwardLedger(CarbonImmutable $threshold): array
{
    $old = $threshold->subYearNoOverflow();

    // --- 組織 A: 失効済み monthly の付与 / 消費 + 無期限 purchased + legacy (source null)
    [$a] = createOrganizationWithOwner('組織A');
    $expiredMonthly = $threshold->subMonthsNoOverflow(6);
    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
        ->monthly($expiredMonthly)->delta(100)->create();
    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
        ->monthly($expiredMonthly)->consumed(40, $expiredMonthly)->create();
    // **同じ source で失効時刻だけが違う group** を必ず 2 つ置く。
    // これが無いと「group key から expires_at を落とす」変異が検出できない (実測済み)。
    $otherExpiry = $threshold->subMonthsNoOverflow(3);
    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
        ->monthly($otherExpiry)->delta(70)->create();
    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
        ->purchased()->delta(50)->create();
    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
        ->legacy()->delta(10)->create();
    // 新しい取引 (畳み込みの対象外)
    TicketLedgerEntry::factory()->forOrganization($a)->createdAt(CarbonImmutable::now())
        ->purchased()->delta(5)->create();

    // --- 組織 B: 7 年より古いが**まだ失効していない** monthly (残高に効いている)
    [$b] = createOrganizationWithOwner('組織B');
    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
        ->monthly($liveExpiry)->delta(30)->create();
    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
        ->purchased()->delta(80)->create();
    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
        ->purchased()->consumed(20)->create();

    // --- 組織 C: 新しい取引しか無い (畳み込みが 1 行も触らない対照)
    [$c] = createOrganizationWithOwner('組織C');
    TicketLedgerEntry::factory()->forOrganization($c)->createdAt(CarbonImmutable::now())
        ->purchased()->delta(7)->create();

    return [$a, $b, $c];
}

test('検証 1〜4・7: 畳み込み前後で残高が 1 枚も変わらない (組織 / source / 失効時刻の粒度)', function (): void {
    $threshold = BillingRetention::threshold();
    seedCarryForwardLedger($threshold);

    $groupsBefore = ledgerBalanceByGroup();
    $balancesBefore = ledgerBalancesByOrganization();
    $rowsBefore = TicketLedgerEntry::query()->count();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    // 空振り検知: 実際に畳み込まれた (0 件で green になっていない)
    expect($result->candidates)->toBeGreaterThan(0);
    expect($result->processed)->toBe($result->candidates);
    expect($result->unexpectedFailures)->toBe(0);
    expect($result->expiredRemaining)->toBe(0);
    expect($result->failClosed)->toBe(0);

    expect(ledgerBalanceByGroup())->toBe($groupsBefore);
    expect(ledgerBalancesByOrganization())->toBe($balancesBefore);

    // 行数は必ず減る (畳み込みが実際に起きた証拠)
    expect(TicketLedgerEntry::query()->count())->toBeLessThan($rowsBefore);
});

test('検証 5: 畳み込み後も消費の出所と失効境界の選択が変わらない', function (): void {
    $threshold = BillingRetention::threshold();
    [, $b] = seedCarryForwardLedger($threshold);

    $service = app(TicketLedgerService::class);

    // 畳み込み前の選択を観測する (monthly が生きているので monthly から消費する)
    $before = $service->reserve($b, 1);
    $beforeSource = $before->consume_source;
    $beforeExpiry = $before->consume_expires_at?->toIso8601String();
    $service->release($before);

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    $after = $service->reserve($b, 1);

    expect($after->consume_source)->toBe($beforeSource);
    expect($after->consume_expires_at?->toIso8601String())->toBe($beforeExpiry);
    expect($beforeSource)->toBe(TicketSource::Monthly); // 空振り検知
});

test('繰越行は残高の粒度 3 つだけを引き継ぎ、取引追跡情報を 1 つも残さない', function (): void {
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(40)->idempotencyKey('purchase:cs_test_secret')
        ->create(['description' => 'チケット購入 (checkout session: cs_test_secret)']);

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    $entries = TicketLedgerEntry::query()->get();
    expect($entries)->toHaveCount(1);

    $carry = $entries->firstOrFail();
    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
    expect($carry->delta)->toBe(40);
    expect($carry->source)->toBe(TicketSource::Purchased);
    expect($carry->expires_at)->toBeNull();
    expect($carry->carried_forward_through?->toDateTimeString())
        ->toBe($threshold->toDateTimeString());

    // 取引追跡情報は 1 つも残っていない (原取引が復元不能である)
    expect($carry->reservation_id)->toBeNull();
    expect($carry->granted_at)->toBeNull();
    expect($carry->stripe_checkout_session_id)->toBeNull();
    expect($carry->payment_intent_id)->toBeNull();
    expect($carry->purchase_amount)->toBeNull();
    expect($carry->stripe_invoice_id)->toBeNull();
    expect($carry->description)->not->toContain('cs_test_secret');
    expect($carry->idempotency_key)->not->toContain('cs_test_secret');
    expect($carry->created_at->greaterThan($threshold))->toBeTrue();
});

test('group key は (organization_id, source, expires_at) の 3 つで、組織を跨いで合算しない', function (): void {
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$first] = createOrganizationWithOwner('第一組織');
    [$second] = createOrganizationWithOwner('第二組織');

    TicketLedgerEntry::factory()->forOrganization($first)->createdAt($old)->purchased()->delta(11)->create();
    TicketLedgerEntry::factory()->forOrganization($second)->createdAt($old)->purchased()->delta(22)->create();

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect(TicketLedgerEntry::query()->count())->toBe(2);
    expect((int) TicketLedgerEntry::query()->where('organization_id', $first->getKey())->sum('delta'))->toBe(11);
    expect((int) TicketLedgerEntry::query()->where('organization_id', $second->getKey())->sum('delta'))->toBe(22);
});

test('source が null の legacy 行は独立した group として畳み込まれる (purchased へ寄せない)', function (): void {
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(9)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->legacy()->delta(4)->create();

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    $entries = TicketLedgerEntry::query()->orderBy('id')->get();
    expect($entries)->toHaveCount(2);
    expect($entries->firstWhere('source', TicketSource::Purchased)?->delta)->toBe(9);
    expect($entries->first(fn (TicketLedgerEntry $e): bool => $e->source === null)?->delta)->toBe(4);
});

test('合計 0 の group は繰越行を作らない (残高に寄与しない行を増やさない)', function (): void {
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(12)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->consumed(12)->create();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($result->processed)->toBe(2);
    expect(TicketLedgerEntry::query()->count())->toBe(0);
});

test('冪等キーは group と閾値で決まり、再実行で同じ値になる (null は明示トークン / 日時は UTC)', function (): void {
    $through = CarbonImmutable::parse('2019-03-04 05:06:07', 'Asia/Tokyo');
    $expiresAt = CarbonImmutable::parse('2018-12-31 15:00:00', 'UTC');

    $withValues = TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through);
    $withNulls = TicketLedgerCarryForwardService::idempotencyKeyFor(42, null, null, $through);

    expect($withValues)->toBe('carry_forward:42:monthly:2018-12-31T15:00:00Z:2019-03-03T20:06:07Z');
    expect($withNulls)->toBe('carry_forward:42:null:null:2019-03-03T20:06:07Z');

    // 再実行で同じ値になる (同一入力 → 同一キー)
    expect(TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through))
        ->toBe($withValues);

    // 既存の signup_grant 部分 UNIQUE index の述語 (LIKE 'signup_grant:%') と衝突しない
    expect($withValues)->not->toStartWith('signup_grant:');
});

test('繰越行はさらに畳み込める (carried_forward_through が単調に進む)', function (): void {
    $threshold = BillingRetention::threshold();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)
        ->createdAt($threshold->subYearsNoOverflow(2))->purchased()->delta(15)->create();

    // 1 回目: 2 年前の閾値で畳み込む (繰越行の created_at はその時点)
    $firstThreshold = $threshold->subYearNoOverflow();
    app(TicketLedgerCarryForwardService::class)->carryForward($firstThreshold);

    $first = TicketLedgerEntry::query()->sole();
    expect($first->kind)->toBe(TicketLedgerKind::CarryForward);
    $firstThrough = $first->carried_forward_through;
    expect($firstThrough)->not->toBeNull();

    // 繰越行を「古い行」に見せるため created_at だけを過去へずらす (append-only guard を迂回する
    // Query Builder 直書き。fixture の都合であり本番経路には無い操作である)
    DB::table('ticket_ledger_entries')
        ->where('organization_id', $organization->getKey())
        ->update(['created_at' => $threshold->subMonthNoOverflow()]);

    TicketLedgerEntry::factory()->forOrganization($organization)
        ->createdAt($threshold->subMonthsNoOverflow(2))->purchased()->delta(5)->create();

    // 2 回目: 現在の閾値で再畳み込み
    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($result->processed)->toBe(2);

    $second = TicketLedgerEntry::query()->sole();
    expect($second->delta)->toBe(20);
    expect($second->carried_forward_through?->greaterThan($firstThrough))->toBeTrue();
});

test('畳み込み済み group に古い行が後から入ったら fail-closed (残高を失わない)', function (): void {
    // 冪等キーは (group, 閾値) で決まるので、同じ閾値で 2 度目の繰越行は insert されない。
    // そこで原取引だけ消すと**繰越行 1 行ぶんの残高が消える**ため、丸ごと巻き戻して報告する。
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(30)->create();

    $service = app(TicketLedgerCarryForwardService::class);
    $service->carryForward($threshold);
    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(30);

    // 同じ group へ「閾値より古い」行が後から入る (取り込み遅延 / 手動投入)
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(7)->create();

    $result = $service->carryForward($threshold);

    expect($result->unexpectedFailures)->toBe(1);
    expect($result->processed)->toBe(0);
    expect($result->expiredRemaining)->toBe(1);
    // 残高は 1 枚も失われていない (30 + 7)
    expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(37);
});

test('集計の後に古い行が割り込んだら fail-closed (削除が合計に無い行を巻き込まない)', function (): void {
    // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
    // ロックを取らない冪等 insert)。集計と削除の間に `created_at <= 閾値` の行が入ると、
    // **合計に入っていない行を削除が巻き込む** = その枚数ぶん残高が消える。
    // ここでは繰越行の INSERT を観測した瞬間に割り込み行を差し込んで、その窓を再現する。
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(30)->create();

    $injected = false;
    DB::listen(function (QueryExecuted $query) use ($organization, $old, &$injected): void {
        if ($injected || ! str_contains($query->sql, 'insert into "ticket_ledger_entries"')) {
            return;
        }
        $injected = true;
        DB::table('ticket_ledger_entries')->insert([
            'organization_id' => $organization->getKey(),
            'delta' => 9,
            'kind' => TicketLedgerKind::Grant->value,
            'source' => TicketSource::Purchased->value,
            'description' => '割り込みで入った古い取引',
            'expires_at' => null,
            'created_at' => $old->toDateTimeString(),
        ]);
    });

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($injected)->toBeTrue(); // 空振り検知: 割り込みが実際に起きた
    expect($result->unexpectedFailures)->toBe(1);
    expect($result->processed)->toBe(0);

    // **元の 30 枚は 1 枚も失われていない** (削除が巻き戻った)。
    // 割り込み行の 9 枚が残っていないのは、テストが**同一トランザクション内**に差し込んで
    // いるためで、実運用の割り込み (別トランザクションの commit) なら残る。
    // ここで固定したいのは「合計に入っていない行を削除が巻き込まない」ことである。
    expect(TicketLedgerEntry::query()->count())->toBe(1);
    expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(30);
});

test('閾値が過去へ戻っても carried_forward_through は後退しない (単調性)', function (): void {
    // 保持年数を延ばす (7 年 → もっと長く) と閾値は過去へ動く。既に「ここまで畳み込んだ」と
    // 記録した終端を、後から短い値で上書きすると**集約済みの範囲を過小申告する**ことになる。
    [$organization] = createOrganizationWithOwner();
    $now = CarbonImmutable::now();

    TicketLedgerEntry::factory()->forOrganization($organization)
        ->createdAt($now->subYearsNoOverflow(12))->purchased()->delta(15)->create();

    // 1 回目: 新しい方の閾値 (now - 5 年) で畳み込む
    $laterThreshold = $now->subYearsNoOverflow(5);
    app(TicketLedgerCarryForwardService::class)->carryForward($laterThreshold);
    expect(TicketLedgerEntry::query()->sole()->carried_forward_through?->toDateTimeString())
        ->toBe($laterThreshold->toDateTimeString());

    // 繰越行を「古い行」に見せる (fixture の都合。append-only guard を迂回する直書き)
    DB::table('ticket_ledger_entries')
        ->where('organization_id', $organization->getKey())
        ->update(['created_at' => $now->subYearsNoOverflow(10)]);

    TicketLedgerEntry::factory()->forOrganization($organization)
        ->createdAt($now->subYearsNoOverflow(11))->purchased()->delta(5)->create();

    // 2 回目: **過去へ戻った**閾値 (now - 9 年) で再畳み込み
    $earlierThreshold = $now->subYearsNoOverflow(9);
    app(TicketLedgerCarryForwardService::class)->carryForward($earlierThreshold);

    $carry = TicketLedgerEntry::query()->sole();
    expect($carry->delta)->toBe(20);
    expect($carry->carried_forward_through?->toDateTimeString())
        ->toBe($laterThreshold->toDateTimeString()); // 後退していない
});

test('新しい取引 (閾値より後) は 1 行も畳み込まれない', function (): void {
    $threshold = BillingRetention::threshold();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)
        ->createdAt($threshold->addSecond())->purchased()->delta(3)->create();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($result->candidates)->toBe(0);
    expect($result->processed)->toBe(0);
    expect(TicketLedgerEntry::query()->count())->toBe(1);
    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::Grant);
});

test('境界: created_at が閾値ちょうどの行は畳み込まれる (<= で判定する)', function (): void {
    $threshold = BillingRetention::threshold();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)
        ->createdAt($threshold)->purchased()->delta(3)->create();

    $service = app(TicketLedgerCarryForwardService::class);
    expect($service->countExpired($threshold))->toBe(1);

    $service->carryForward($threshold);

    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
});

test('検証 6: 畳み込み後も signup grant の org 生涯 1 回は marker が守る', function (): void {
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['signup_tickets_granted_at' => $old])->save();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($threshold->subMonthsNoOverflow(3))->delta(20)
        ->idempotencyKey('signup_grant:org:'.$organization->getKey())->create();

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    // 畳み込みで signup_grant 行 (= 部分 UNIQUE index が守っていた行) は消える。
    // 「org 生涯 1 回」の**正本は organizations.signup_tickets_granted_at の条件付き UPDATE** であり、
    // それは畳み込みの対象ではないので残る (index は保険であって正本ではない)。
    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(0);
    expect($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
});

test('[既知窓] 合計 0 の未失効 monthly group は畳み込みで失効境界の情報を失う', function (): void {
    // 7 年より古い付与と消費が相殺し、かつ失効時刻が**まだ未来**という組み合わせでのみ起きる。
    // 残高は変わらない (0 のまま) が、消費境界の探索 (nearestMonthlyExpiry) が見る
    // 「delta>0 の未失効 monthly 行」が消えるため、次の予約の consume_expires_at が変わる。
    // 残高保存を優先し、この窓は受容する (詳細設計 C2b「合計 0 の繰越行を作らない」)。
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($liveExpiry)->delta(25)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($liveExpiry)->consumed(25, $liveExpiry)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(10)->create();

    $service = app(TicketLedgerService::class);
    $balanceBefore = $service->availableTrueBalance($organization);

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    // 残高は保存される (これが最優先の不変条件)
    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);

    // 一方で「未失効 monthly の失効境界」は消えている (既知窓)
    expect(TicketLedgerEntry::query()
        ->where('source', TicketSource::Monthly)
        ->where('delta', '>', 0)
        ->whereNotNull('expires_at')
        ->count())->toBe(0);
});
