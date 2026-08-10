<?php

declare(strict_types=1);

use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\Subscription;
use App\Services\Billing\Contracts\BillingRetentionPurger;
use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
use App\Support\Legal\BillingRetention;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Billing\BillingRetentionFixtures;

/*
 * 保持期間の **horizon (地平線) 検査**: purger を一通り実行したあと、
 * 起算済み・期限超過の記録が **1 件も残らない**ことを固定する。
 *
 * ★なぜ「target ごとに消えた」ではなく horizon で見るか:
 *   target 単位のテストは「その target が消えた」ことしか言わない。規約 (最長 N 年) が
 *   要求するのは**全体として超過が残らない**ことであり、target を 1 つ足して purger を
 *   書き忘れたときに赤くなるのは horizon 側だけである。
 *
 * ★`failClosed` を除外しない。「安全のため残した」も**規約から見れば残存**である。
 *
 * ★この検査が保証しないもの (誇張しない):
 *   - **本番で日次処理が止まっていないこと**は保証しない (責務は Command の件数報告 +
 *     FAILURE 終了コード + scheduler 運用。C1 では日次登録すらしていない)
 *   - **目録の網羅性**は保証しない (BillingRetentionTarget は人間の申告である)
 *   - **決着の方式は target で違う** (削除 6 本 / 畳み込み 1 本) が、horizon は方式を問わず
 *     「起算済み・期限超過が残っていないか」だけを見る。台帳の畳み込みが**残高を保存するか**は
 *     本検査の担当ではない (tests/Feature/Billing/TicketLedgerCarryForwardTest.php が担う)
 */

/** horizon の母集団に入る target (= 全 target。除外は無い)。 */
function billingRetentionHorizonTargets(): array
{
    return BillingRetentionTarget::cases();
}

/** @return list<BillingRetentionPurger> */
function billingRetentionPurgersInOrder(): array
{
    return app(BillingRetentionPurgerRegistry::class)->purgers();
}

/*
 * 詳細設計 C1d の検査 3 (起算点 / 補助時計の**実在列**照合) は本来 Architecture の
 * 目録 gate の責務だが、Architecture lane は DB を持たない (RefreshDatabase は
 * Feature/Unit にしか適用されず、migration 前の空スキーマしか見えないため常に空振りする)。
 * よって schema 照合だけをここへ移設している (BillingRetentionTargetInventoryTest 側には
 * 修飾名の構造検査が残る)。
 */
test('起算点と補助時計の列が実在する (修飾名も解決する)', function (): void {
    $missing = [];

    foreach (BillingRetentionTarget::cases() as $case) {
        foreach ([$case->clockStartColumn(), $case->anomalyClockColumn()] as $column) {
            if ($column === null) {
                continue;
            }
            $table = $case->table();
            if (str_contains($column, '.')) {
                [$table, $column] = explode('.', $column, 2);
            }
            if (! Schema::hasColumn($table, $column)) {
                $missing[] = $case->value.' => '.$table.'.'.$column;
            }
        }
    }

    expect($missing)->toBe([], '目録が宣言した列が実在しません: '.implode(', ', $missing));

    // 空振り検知: 実在しない列は確かに false になる (検査器が常に true を返していない)
    expect(Schema::hasColumn('subscriptions', 'ends_at'))->toBeTrue();
    expect(Schema::hasColumn('subscriptions', 'no_such_column'))->toBeFalse();
});

test('postcondition: purger を一通り実行すると期限超過が全 target で 0 件になる', function (): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::seedExpiredRows($threshold);

    // 前提: 実行前は確かに超過が存在する (空振りで green にならない)
    $before = 0;
    foreach (billingRetentionPurgersInOrder() as $purger) {
        $before += $purger->countExpired($threshold);
    }
    expect($before)->toBeGreaterThan(0);

    $remaining = [];
    foreach (billingRetentionPurgersInOrder() as $purger) {
        $result = $purger->purgeExpired($threshold);
        expect($result->unexpectedFailures)->toBe(0, $purger->target()->value);
        if (! $result->isPublicationReady()) {
            $remaining[] = sprintf(
                '%s: expiredRemaining=%d failClosed=%d',
                $result->target->value,
                $result->expiredRemaining,
                $result->failClosed,
            );
        }
    }

    expect($remaining)->toBe([], '保持期限を超えた記録が残っています: '.implode(' / ', $remaining));

    foreach (billingRetentionPurgersInOrder() as $purger) {
        expect($purger->countExpired($threshold))->toBe(0, $purger->target()->value);
    }
});

test('負のコントロール: purge 後に古い記録を作ると horizon が満たされなくなる', function (): void {
    $threshold = BillingRetention::threshold();

    foreach (billingRetentionPurgersInOrder() as $purger) {
        $purger->purgeExpired($threshold);
    }

    BillingRetentionFixtures::endedSubscription($threshold->subDay());

    $expired = 0;
    foreach (billingRetentionPurgersInOrder() as $purger) {
        $expired += $purger->countExpired($threshold);
    }

    expect($expired)->toBe(1);
    expect(Subscription::query()->count())->toBe(1);
});

test('母集団は全 target を含む (畳み込みで決着する台帳も horizon の対象である)', function (): void {
    $targets = array_map(
        static fn (BillingRetentionPurger $purger): string => $purger->target()->value,
        billingRetentionPurgersInOrder(),
    );

    expect($targets)->toContain(BillingRetentionTarget::TicketLedgerEntry->value);

    $expected = array_map(
        static fn (BillingRetentionTarget $case): string => $case->value,
        billingRetentionHorizonTargets(),
    );
    sort($expected);
    sort($targets);

    expect($targets)->toBe($expected);
});
