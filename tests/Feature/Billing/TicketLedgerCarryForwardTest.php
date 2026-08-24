<?php

declare(strict_types=1);

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\Retention\TicketLedgerCarryForwardService;
use App\Services\Billing\TicketLedgerService;
use App\Support\Legal\BillingRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 保持期限の台帳畳み込み (家系の正典 v1 = 二段判定・収束繰越形) の挙動。
 *
 * ★畳み込みは**会計上の残高を保存する操作**である。1 枚でも増減したら重大な不具合なので、
 *   「畳み込み前後で残高の観測値が一致する」ことを本ファイルが機械固定する。
 *
 * ★判定は 2 段である。
 *   - 第 1 段 (適格性): `created_at <= 閾値`。満たさない行は 1 行も触らない
 *   - 第 2 段 (寄与判定): 失効済み (`expires_at <= now`) は**物理削除**、
 *     寄与する行 (`expires_at IS NULL` または `> now`) だけを
 *     `(organization_id, source, expires_at)` ごとに合算した繰越 1 行へ畳み込む
 *
 * ★繰越行は「取引記録」ではなく**現在残高のスナップショット**である。原取引の識別子
 *   (説明 / stripe id / payment intent / 予約 id / 冪等キー) は 1 つも引き継がない。
 *   `created_at` は**畳み込んだ行の最大 `created_at`** (集約の基準時刻) であり実行時刻ではない
 *   — 実行時刻にすると繰越行が実行のたびに増え、集約単位ごとに 1 行へ収束しない。
 */

/**
 * 台帳の残高粒度ごとの合計 (organization_id / source / expires_at)。
 *
 * ★**寄与する行だけ**を数える (`expires_at` が NULL または未来)。v1 では失効済みの行は
 *   繰越に含めず物理削除されるのが**正しい挙動**なので、生の全行 SUM の一致を要求すると
 *   正典の要求と矛盾する。残高に効く枚数が 1 枚も動かないことがここでの不変条件である。
 *
 * **合計 0 の group は落とす**。畳み込みは残高に寄与しない行を作らないため、
 * 「0 の group が消えること」は残高の変化ではない。
 *
 * @return array<string, int>
 */
function ledgerBalanceByGroup(): array
{
    $now = CarbonImmutable::now();
    $totals = [];
    foreach (TicketLedgerEntry::query()->get() as $entry) {
        if ($entry->expires_at !== null && $entry->expires_at->lessThanOrEqualTo($now)) {
            continue; // 失効済み = 残高に寄与しない
        }
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
 * 3 組織ぶんの「保持期限以前の取引 + 新しい取引」を並べる。
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

    // --- 組織 B: 保持期限以前だが**まだ失効していない** monthly (残高に効いている)
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
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    seedCarryForwardLedger($threshold);

    $groupsBefore = ledgerBalanceByGroup();
    $balancesBefore = ledgerBalancesByOrganization();
    $rowsBefore = TicketLedgerEntry::query()->count();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    // 空振り検知: 実際に畳み込まれた (0 件で green になっていない)
    expect($result->candidates)->toBeGreaterThan(0);
    // ★件数の恒等式: **失敗 0 件かつ実行中に決着対象集合が変化しない**なら
    //   candidates = processed + expiredRemaining が成り立つ
    //   (`processed` は決着対象のうち消えた行数であり、再集約で作り直した繰越行は数えない)。
    //   組織行ロックを取らない追記経路が実行中に割り込むと母集団が動くので、
    //   この恒等式は**静止した集合**についての性質である (N1c がその窓を扱う)。
    expect($result->processed + $result->expiredRemaining)->toBe($result->candidates);
    expect($result->unexpectedFailures)->toBe(0);
    expect($result->expiredRemaining)->toBe(0);
    expect($result->failClosed)->toBe(0);

    expect(ledgerBalanceByGroup())->toBe($groupsBefore);
    expect(ledgerBalancesByOrganization())->toBe($balancesBefore);

    // 行数は必ず減る (畳み込みが実際に起きた証拠)
    expect(TicketLedgerEntry::query()->count())->toBeLessThan($rowsBefore);
});

test('検証 5: 畳み込み後も消費の出所と失効境界の選択が変わらない', function (): void {
    $this->freezeTime();
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
    $this->freezeTime();
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

    // 取引追跡情報は 1 つも残っていない (原取引が復元不能である)
    expect($carry->reservation_id)->toBeNull();
    expect($carry->granted_at)->toBeNull();
    expect($carry->stripe_checkout_session_id)->toBeNull();
    expect($carry->payment_intent_id)->toBeNull();
    expect($carry->purchase_amount)->toBeNull();
    expect($carry->stripe_invoice_id)->toBeNull();
    expect($carry->idempotency_key)->toBeNull();
    expect($carry->description)->not->toContain('cs_test_secret');

    // ★`created_at` は**集約の基準時刻** (畳み込んだ行の最大 created_at) であって実行時刻ではない
    expect($carry->created_at->toDateTimeString())->toBe($old->toDateTimeString());
});

test('group key は (organization_id, source, expires_at) の 3 つで、組織を跨いで合算しない', function (): void {
    $this->freezeTime();
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
    $this->freezeTime();
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
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(12)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->consumed(12)->create();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($result->processed)->toBe(2);
    expect(TicketLedgerEntry::query()->count())->toBe(0);
});

test('新しい取引 (閾値より後) は 1 行も畳み込まれない', function (): void {
    $this->freezeTime();
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
    $this->freezeTime();
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
    $this->freezeTime();
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
    // 保持期限以前の付与と消費が相殺し、かつ失効時刻が**まだ未来**という組み合わせでのみ起きる。
    // 残高は変わらない (0 のまま) が、消費境界の探索 (nearestMonthlyExpiry) が見る
    // 「delta>0 の未失効 monthly 行」が消えるため、次の予約の consume_expires_at が変わる。
    // 残高保存を優先し、この窓は受容する。
    $this->freezeTime();
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

/*
 * ---------------------------------------------------------------------------
 * 正典 v1 (二段判定・収束繰越形) が要求する不変条件 (T259)
 * ---------------------------------------------------------------------------
 */

test('N1: 失効済みの明細は繰越に含めず物理削除される', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    // 期限以前 + 既に失効している monthly (残高に 1 枚も寄与していない)
    $expired = $threshold->subMonthsNoOverflow(6);
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($expired)->delta(100)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($expired)->consumed(40, $expired)->create();
    // 寄与する行 (無期限 purchased)
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(50)->create();

    $service = app(TicketLedgerService::class);
    $balanceBefore = $service->availableTrueBalance($organization);

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    // 失効済みの群は**繰越行を 1 行も作らず**消える
    expect(TicketLedgerEntry::query()->whereNotNull('expires_at')->count())->toBe(0);
    // 寄与する群だけが繰越行になる
    $entries = TicketLedgerEntry::query()->get();
    expect($entries)->toHaveCount(1);
    expect($entries->firstOrFail()->kind)->toBe(TicketLedgerKind::CarryForward);
    expect($entries->firstOrFail()->delta)->toBe(50);
    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
});

test('N1b: 境界 — expires_at が now ちょうどの行は失効側、now より 1 秒未来の行は寄与側', function (): void {
    // 第 2 段の比較演算子を固定する。
    //   削除枝: expires_at IS NOT NULL AND expires_at <= now
    //   寄与枝: expires_at IS NULL      OR  expires_at >  now
    // ★このテストが赤にするのは**削除枝の `<=` → `<`** の変異である。
    //   静止した fixture では削除枝が先に走って `expires_at = now` の行を消すため、
    //   **寄与枝の `>` → `>=` はここでは観測できない**。
    //   寄与枝の境界は **N1c** (削除後・集約前に境界行が割り込む窓) が固定する。
    $this->freezeTime();
    $now = CarbonImmutable::now();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    // 失効時刻が **now ちょうど** (= 残高に寄与しない)
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($now)->delta(11)->create();
    // 失効時刻が **now + 1 秒** (= まだ寄与する)
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($now->addSecond())->delta(22)->create();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($result->unexpectedFailures)->toBe(0);
    expect($result->candidates)->toBe(2);
    expect($result->processed)->toBe(2);
    expect($result->expiredRemaining)->toBe(0);

    // now ちょうどの群は繰越行を作らず消え、now+1 秒の群だけが繰越行になる
    $entries = TicketLedgerEntry::query()->get();
    expect($entries)->toHaveCount(1);
    $carry = $entries->firstOrFail();
    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
    expect($carry->delta)->toBe(22);
    expect($carry->expires_at?->toDateTimeString())->toBe($now->addSecond()->toDateTimeString());
});

test('N1c: 失効 DELETE の後・集約 SELECT の前に expires_at = now の行が割り込んでも寄与側に入らない', function (): void {
    // ★寄与枝 `expires_at > now` の境界を固定する。静止した fixture では削除枝が先に
    //   その行を消してしまうので観測できないが、**組織行ロックを取らない追記経路**
    //   (grantMonthly / grantPurchased) は削除と集約の間に commit しうる
    //   (サービス docblock がこの窓を明記している)。その窓へ境界行を差し込むと、
    //   `>` と `>=` の違いが**振る舞いとして現れる**:
    //     `>`  (正) … 割り込んだ行は寄与側に入らないので**そのまま残る** (次回に決着する)
    //     `>=` (誤) … 集約に取り込まれ、既に失効している繰越行へ置き換わってしまう
    $this->freezeTime();
    $now = CarbonImmutable::now();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    // 寄与する明細 (無期限)。これが集約されることで畳み込みが実際に走る
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(50)->create();

    $injected = false;
    DB::listen(function (QueryExecuted $query) use ($organization, $old, $now, &$injected): void {
        // 失効済み行の範囲削除を観測した直後 = 集約 SELECT より前
        if ($injected
            || ! str_contains($query->sql, 'delete from "ticket_ledger_entries"')
            || ! str_contains($query->sql, '"expires_at" is not null')) {
            return;
        }
        $injected = true;
        DB::table('ticket_ledger_entries')->insert([
            'organization_id' => $organization->getKey(),
            'delta' => 9,
            'kind' => TicketLedgerKind::Grant->value,
            'source' => TicketSource::Monthly->value,
            'description' => '割り込みで入った境界の取引',
            'expires_at' => $now->toDateTimeString(),
            'created_at' => $old->toDateTimeString(),
        ]);
    });

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($injected)->toBeTrue(); // 空振り検知: 割り込みが実際に起きた
    expect($result->unexpectedFailures)->toBe(0);

    // ★監視値にも現れる。`candidates` は割り込み前に数えているので 1、
    //   `expiredRemaining` は割り込んだ行が決着対象として残るので 1 になる。
    //   **恒等式 candidates = processed + expiredRemaining は成り立たない** —
    //   実行中に決着対象が増えたからであり、述語ずれではない (DTO docblock の前提どおり)。
    expect($result->candidates)->toBe(1);
    expect($result->processed)->toBe(1);
    expect($result->expiredRemaining)->toBe(1);

    // 割り込んだ境界行は**寄与側に取り込まれず、手つかずで残る**
    $survivor = TicketLedgerEntry::query()->where('delta', 9)->sole();
    expect($survivor->kind)->toBe(TicketLedgerKind::Grant);
    expect($survivor->source)->toBe(TicketSource::Monthly);
    expect($survivor->expires_at?->toDateTimeString())->toBe($now->toDateTimeString());
    expect($survivor->description)->toBe('割り込みで入った境界の取引');

    // 元の寄与する明細は繰越行になっている (空振り検知)
    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::CarryForward)->sole()->delta)->toBe(50);
});

test('N2: 繰越行の created_at は畳み込んだ行の最大 created_at である', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    [$organization] = createOrganizationWithOwner();

    $oldest = $threshold->subYearsNoOverflow(3);
    $middle = $threshold->subYearNoOverflow();
    $newest = $threshold->subMonthsNoOverflow(2);

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($oldest)->purchased()->delta(1)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($middle)->purchased()->delta(2)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($newest)->purchased()->delta(4)->create();

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    $carry = TicketLedgerEntry::query()->sole();
    expect($carry->delta)->toBe(7);
    expect($carry->created_at->toDateTimeString())->toBe($newest->toDateTimeString());
});

test('N3: 収束 — 同じ閾値で 2 回実行しても繰越行は増えない', function (): void {
    // ★このテストは v0 (繰越行の created_at = 実行時刻) でも緑になるため**赤の起点にはならない**。
    //   収束の回帰として残す (N3b が短絡そのものを見る)。
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->legacy()->delta(6)->create();

    $service = app(TicketLedgerCarryForwardService::class);
    $service->carryForward($threshold);

    $afterFirst = TicketLedgerEntry::query()->orderBy('id')->pluck('delta', 'id')->all();
    expect($afterFirst)->toHaveCount(2);

    $second = $service->carryForward($threshold);

    expect($second->processed)->toBe(0);
    expect($second->unexpectedFailures)->toBe(0);
    expect(TicketLedgerEntry::query()->orderBy('id')->pluck('delta', 'id')->all())->toBe($afterFirst);
});

test('N3b: 既に繰越 1 行だけの集約キーは入れ替えられない (収束の短絡)', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();

    $service = app(TicketLedgerCarryForwardService::class);
    $service->carryForward($threshold);

    $converged = TicketLedgerEntry::query()->sole();
    expect($converged->kind)->toBe(TicketLedgerKind::CarryForward);
    $convergedId = $converged->getKey();

    // **別の集約キー**に期限超過の明細を置いて、組織を再び列挙させる
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($liveExpiry)->delta(9)->create();

    $service->carryForward($threshold);

    // 触られない側の繰越行は id ごと不変である (入れ替えが起きていない)
    $still = TicketLedgerEntry::query()->whereKey($convergedId)->first();
    expect($still)->not->toBeNull();
    expect($still?->delta)->toBe(15);
    expect(TicketLedgerEntry::query()->count())->toBe(2);
});

test('N4: 有界性 — 失効済みの窓を N 個置いても畳み込み後の行数が N に依存しない', function (int $windows): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    for ($i = 1; $i <= $windows; $i++) {
        TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
            ->monthly($threshold->subMonthsNoOverflow($i))->delta(10)->create();
    }
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(50)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($liveExpiry)->delta(4)->create();

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    // 残るのは「未失効の monthly」+「無期限の purchased」の 2 行だけ (窓の数に依存しない)
    expect(TicketLedgerEntry::query()->count())->toBe(2);
})->with([[1], [5]]);

test('N5: 既存の繰越行と後から入った古い明細は 1 行へ合算される', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearsNoOverflow(2);
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();

    $service = app(TicketLedgerCarryForwardService::class);
    $service->carryForward($threshold);
    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);

    // 同じ集約キーへ「閾値より古い」明細が後から入る (取り込み遅延 / 手動投入)
    $later = $threshold->subMonthNoOverflow();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($later)->purchased()->delta(5)->create();

    $result = $service->carryForward($threshold);

    expect($result->unexpectedFailures)->toBe(0);
    // ★`processed` は**決着対象**だけを数える。既存の繰越行は再集約のために消して
    //   作り直しただけで決着ではないので数えない (candidates と母集団を揃える)。
    expect($result->candidates)->toBe(1);
    expect($result->processed)->toBe(1);
    expect($result->expiredRemaining)->toBe(0);

    $carry = TicketLedgerEntry::query()->sole();
    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
    expect($carry->delta)->toBe(20);
    expect($carry->created_at->toDateTimeString())->toBe($later->toDateTimeString());
});

test('N6: 閾値が過去へ動いても残高が保存され繰越行が増えない', function (): void {
    // 保持年数を延ばす (7 年 → もっと長く) と閾値は過去へ動く。旧実装はここで集約範囲を
    // 専用列で単調前進させていたが、v1 は集約単位ごとに 1 行へ収束するので概念ごと不要である。
    // 守りたい実害 (集約の二重計上・行の増殖) を直接見る。
    $this->freezeTime();
    $now = CarbonImmutable::now();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)
        ->createdAt($now->subYearsNoOverflow(12))->purchased()->delta(15)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)
        ->createdAt($now->subYearsNoOverflow(11))->purchased()->delta(5)->create();

    $service = app(TicketLedgerCarryForwardService::class);
    $balancesBefore = ledgerBalancesByOrganization();

    // 1 回目: 新しい方の閾値 (now - 5 年)
    $service->carryForward($now->subYearsNoOverflow(5));
    expect(TicketLedgerEntry::query()->count())->toBe(1);

    // 2 回目: **過去へ戻った**閾値 (now - 9 年)
    $service->carryForward($now->subYearsNoOverflow(9));

    $carry = TicketLedgerEntry::query()->sole();
    expect($carry->delta)->toBe(20);
    expect(ledgerBalancesByOrganization())->toBe($balancesBefore);
});

test('N7: 合計が int4 上限ちょうどなら畳み込める', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(2147483646)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(1)->create();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($result->unexpectedFailures)->toBe(0);
    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(2147483647);
});

test('N8 / N17 / N19: 合計が int4 の範囲を超えたらその組織だけ巻き戻る', function (int $first, int $second) {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$overflowing] = createOrganizationWithOwner('溢れる組織');
    [$healthy] = createOrganizationWithOwner('健全な組織');

    TicketLedgerEntry::factory()->forOrganization($overflowing)->createdAt($old)
        ->purchased()->delta($first)->create();
    TicketLedgerEntry::factory()->forOrganization($overflowing)->createdAt($old)
        ->purchased()->delta($second)->create();
    TicketLedgerEntry::factory()->forOrganization($healthy)->createdAt($old)
        ->purchased()->delta(12)->create();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    // 溢れた組織は巻き戻る (行が 1 つも消えていない)
    expect($result->unexpectedFailures)->toBe(1);
    expect(TicketLedgerEntry::query()->where('organization_id', $overflowing->getKey())->count())->toBe(2);
    expect(TicketLedgerEntry::query()->where('organization_id', $overflowing->getKey())
        ->where('kind', TicketLedgerKind::CarryForward)->count())->toBe(0);

    // ★N17: 1 組織の失敗は他の組織を止めない
    expect($result->processed)->toBe(1);
    $healthyRow = TicketLedgerEntry::query()->where('organization_id', $healthy->getKey())->sole();
    expect($healthyRow->kind)->toBe(TicketLedgerKind::CarryForward);

    // ★N19: 失敗した組織があるとき publication-ready が誤って true にならない。
    //   **DB レベルの削除失敗は再現しない** (stub を挟まないと作れない) ので、
    //   失敗の注入は範囲検査で行う。この限界を承知したうえでの回帰である。
    expect($result->isPublicationReady())->toBeFalse();
    expect($result->expiredRemaining)->toBe(2);
})->with([
    'int4 上限 +1' => [2147483647, 1],
    'int4 下限 -1' => [-2147483648, -1],
]);

test('N10: 集計の後に古い明細が割り込んだら fail-closed (削除が合計に無い行を巻き込まない)', function (): void {
    // 組織行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
    // ロックを取らない冪等 insert)。集計と削除の間に `created_at <= 閾値` の行が入ると、
    // **合計に入っていない行を削除が巻き込む** = その枚数ぶん残高が消える。
    // v1 は「削除 → 追記」の順なので、**集約 SELECT (delta_sum) を観測した直後**に差し込む。
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(30)->create();

    $injected = false;
    DB::listen(function (QueryExecuted $query) use ($organization, $old, &$injected): void {
        if ($injected || ! str_contains($query->sql, 'delta_sum')) {
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

test('N11: 繰越行の列分類 (明細を 1 列も持たない)', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(40)->idempotencyKey('purchase:cs_test_secret')
        ->create(['description' => 'チケット購入 (checkout session: cs_test_secret)']);

    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    /** @var array<string, mixed> $row */
    $row = (array) DB::table('ticket_ledger_entries')->sole();

    // (1) kind が厳密に carry_forward
    expect($row['kind'])->toBe(TicketLedgerKind::CarryForward->value);
    // (2) description が固定文言と厳密一致
    expect($row['description'])->toBe(TicketLedgerCarryForwardService::DESCRIPTION);
    // (3) NULL_COLUMNS の全列が NULL
    foreach (TicketLedgerCarryForwardService::NULL_COLUMNS as $column) {
        expect($row[$column])->toBeNull($column.' は繰越行では NULL でなければならない');
    }
    // (4) VALUED_COLUMNS ∪ NULL_COLUMNS が実スキーマの全列と完全一致 (= (5) 未分類の列は失敗)
    $columns = Schema::getColumnListing('ticket_ledger_entries');
    sort($columns);
    $declared = array_merge(
        TicketLedgerCarryForwardService::VALUED_COLUMNS,
        TicketLedgerCarryForwardService::NULL_COLUMNS,
    );
    sort($declared);
    expect($declared)->toBe($columns,
        '表に列を足したら繰越行での扱い (値を持つ / 必ず NULL) を分類してください');
});

test('N12 / N13: 論理削除済み (退会済み) 組織の明細も畳み込まれ残高が保存される', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(33)->create();
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(7)->create();

    $balanceBefore = app(TicketLedgerService::class)->availableTrueBalance($organization);

    $organization->delete(); // 退会 (SoftDeletes)
    expect(Organization::query()->whereKey($organization->getKey())->exists())->toBeFalse();

    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);

    expect($result->processed)->toBe(2);
    $carry = TicketLedgerEntry::query()->sole();
    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
    // N13: 論理削除済み組織でも残高が保存される
    expect($carry->delta)->toBe(40);
    expect(app(TicketLedgerService::class)->availableTrueBalance($organization))->toBe($balanceBefore);
});

test('N14: 論理削除済み組織の期限超過明細は expiredRemaining に現れ、畳み込み後に 0 になる', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(12)->create();
    $organization->delete();

    $service = app(TicketLedgerCarryForwardService::class);
    expect($service->countExpired($threshold))->toBe(1);

    $result = $service->carryForward($threshold);

    expect($result->candidates)->toBe(1);
    expect($result->processed)->toBe(1);
    expect($result->unexpectedFailures)->toBe(0);
    expect($result->expiredRemaining)->toBe(0);
    expect($result->isPublicationReady())->toBeTrue();
});

test('N15 / N16: 決着対象の件数は繰越行を数えず、取引明細が残っていれば 0 にならない', function (): void {
    $this->freezeTime();
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(21)->create();

    $service = app(TicketLedgerCarryForwardService::class);
    $service->carryForward($threshold);

    // N15: 畳み込み後は 0 かつ繰越行は実在する (寄与中の集約レコードは決着対象ではない)
    expect($service->countExpired($threshold))->toBe(0);
    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::CarryForward)->count())->toBe(1);

    // N16: 繰越行以外の適格行が 1 行あれば 0 にならない
    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->purchased()->delta(3)->create();
    expect($service->countExpired($threshold))->toBe(1);
});

test('N18: 失効した繰越行だけが残った組織も決着する', function (): void {
    // 繰越行は **畳み込みの出力**として作る (factory で kind=carry_forward を直に作ると
    // 「畳み込みが本当にこの形を作るか」を検証していないことになる)。
    $now = CarbonImmutable::now();
    $this->travelTo($now);
    $threshold = BillingRetention::threshold();
    $old = $threshold->subYearNoOverflow();
    $expiry = $now->addMonthsNoOverflow(2); // 実行時点ではまだ寄与している
    [$organization] = createOrganizationWithOwner();

    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
        ->monthly($expiry)->delta(20)->create();

    $service = app(TicketLedgerCarryForwardService::class);
    $service->carryForward($threshold);

    $carry = TicketLedgerEntry::query()->sole();
    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
    expect($carry->expires_at?->toDateTimeString())->toBe($expiry->toDateTimeString());

    // 時計を失効後へ進める (組織には取引明細が 1 行も無い状態)
    $this->travelTo($expiry->addSecond());
    $laterThreshold = BillingRetention::threshold();

    expect($service->countExpired($laterThreshold))->toBe(1);

    $result = $service->carryForward($laterThreshold);

    expect($result->candidates)->toBe(1);
    expect($result->processed)->toBe(1);
    expect($result->expiredRemaining)->toBe(0);
    expect(TicketLedgerEntry::query()->count())->toBe(0);
});
