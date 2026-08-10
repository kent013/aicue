<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Billing\Subscription;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Billing\TicketLedgerEntry;
use App\Services\Billing\Contracts\BillingRetentionPurger;
use App\Services\Billing\Retention\BillingCheckoutSessionPurger;
use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
use App\Services\Billing\Retention\StripeWebhookEventPurger;
use App\Services\Billing\Retention\SubscriptionItemPurger;
use App\Services\Billing\Retention\SubscriptionPurger;
use App\Services\Billing\Retention\TicketAutoRechargeAttemptPurger;
use App\Services\Billing\Retention\TicketCheckoutSessionPurger;
use App\Support\Legal\BillingRetention;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Laravel\Cashier\SubscriptionItem;
use Tests\Support\Billing\BillingRetentionFixtures;

/*
 * 保持期間 (7 年) purge の挙動 (PR-C1)。
 *
 * ★C1 は **dry-run 専用** (コマンドに --apply は無い)。purger 本体の削除挙動はここで
 *   直接呼んで固定し、コマンド経由では「1 行も消えない」ことを固定する。
 *
 * ★境界の定義: 起算日時が閾値**ちょうど以前**なら期限超過 (`<=`)。
 */

/** 自テーブルに起算列を持つ 4 target (purger クラス => target)。 */
dataset('自テーブル起算の target', [
    'stripe_webhook_event' => [StripeWebhookEventPurger::class, BillingRetentionTarget::StripeWebhookEvent],
    'billing_checkout_session' => [BillingCheckoutSessionPurger::class, BillingRetentionTarget::BillingCheckoutSession],
    'ticket_checkout_session' => [TicketCheckoutSessionPurger::class, BillingRetentionTarget::TicketCheckoutSession],
    'ticket_auto_recharge_attempt' => [TicketAutoRechargeAttemptPurger::class, BillingRetentionTarget::TicketAutoRechargeAttempt],
]);

test('境界: 起算日時が閾値の 1 秒前なら消え、1 秒後なら残る', function (string $purgerClass, BillingRetentionTarget $target): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::createStarted($target, $threshold->subSecond());
    BillingRetentionFixtures::createStarted($target, $threshold->addSecond());

    /** @var BillingRetentionPurger $purger */
    $purger = app($purgerClass);
    expect($purger->countExpired($threshold))->toBe(1);

    $result = $purger->purgeExpired($threshold);

    expect($result->target)->toBe($target);
    expect($result->candidates)->toBe(1);
    expect($result->processed)->toBe(1);
    expect($result->failClosed)->toBe(0);
    expect($result->unexpectedFailures)->toBe(0);
    expect($result->expiredRemaining)->toBe(0);
    expect($target->modelClass()::query()->count())->toBe(1); // 新しい方は残っている
})->with('自テーブル起算の target');

test('境界: 起算日時が閾値ちょうどなら期限超過 (<= で判定する)', function (string $purgerClass, BillingRetentionTarget $target): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::createStarted($target, $threshold);

    /** @var BillingRetentionPurger $purger */
    $purger = app($purgerClass);

    expect($purger->countExpired($threshold))->toBe(1);
})->with('自テーブル起算の target');

test('起算列が null で補助時計が閾値より古い行は fail-closed (消さずに計上する)', function (string $purgerClass, BillingRetentionTarget $target): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::createUnstarted($target, $threshold->subSecond());

    /** @var BillingRetentionPurger $purger */
    $purger = app($purgerClass);

    expect($purger->countExpired($threshold))->toBe(0);
    expect($purger->countFailClosed($threshold))->toBe(1);

    $result = $purger->purgeExpired($threshold);

    expect($result->processed)->toBe(0);
    expect($result->failClosed)->toBe(1);
    expect($result->hasFailClosedRecords())->toBeTrue();
    expect($target->modelClass()::query()->count())->toBe(1); // 消していない
})->with('自テーブル起算の target');

test('境界: 起算列 null かつ補助時計が閾値の 1 秒後なら fail-closed に計上しない', function (string $purgerClass, BillingRetentionTarget $target): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::createUnstarted($target, $threshold->addSecond());

    /** @var BillingRetentionPurger $purger */
    $purger = app($purgerClass);

    expect($purger->countExpired($threshold))->toBe(0);
    expect($purger->countFailClosed($threshold))->toBe(0); // 正常な未確定 (まだ新しい)
})->with('自テーブル起算の target');

test('継続中の契約 (ends_at が null) は何年前に作られていても対象外かつ異常でもない', function (): void {
    $threshold = BillingRetention::threshold();
    [$organization] = createOrganizationWithOwner();
    $subscription = createFakeSubscription($organization);
    $subscription->forceFill([
        'ends_at' => null,
        'created_at' => $threshold->subYearsNoOverflow(3),
    ])->save();

    $purger = app(SubscriptionPurger::class);

    expect($purger->countExpired($threshold))->toBe(0);
    expect($purger->countFailClosed($threshold))->toBe(0);

    $result = $purger->purgeExpired($threshold);

    expect($result->processed)->toBe(0);
    expect(Subscription::query()->count())->toBe(1);
});

test('終了済み契約は ends_at で判定され、明細が無ければ消える', function (): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::endedSubscription($threshold->subSecond());
    BillingRetentionFixtures::endedSubscription($threshold->addSecond());

    $result = app(SubscriptionPurger::class)->purgeExpired($threshold);

    expect($result->candidates)->toBe(1);
    expect($result->processed)->toBe(1);
    expect($result->failClosed)->toBe(0);
    expect($result->expiredRemaining)->toBe(0);
    expect(Subscription::query()->count())->toBe(1);
});

test('明細が残っている期限超過の契約は fail-closed で残り、件数が報告される', function (): void {
    $threshold = BillingRetention::threshold();
    $subscription = BillingRetentionFixtures::endedSubscription($threshold->subSecond());
    BillingRetentionFixtures::attachItem($subscription);

    $purger = app(SubscriptionPurger::class);

    expect($purger->countFailClosed($threshold))->toBe(1);

    $result = $purger->purgeExpired($threshold);

    expect($result->candidates)->toBe(1);
    expect($result->processed)->toBe(0);
    expect($result->failClosed)->toBe(1);
    expect($result->expiredRemaining)->toBe(1);
    expect($result->isPublicationReady())->toBeFalse();
    expect(Subscription::query()->count())->toBe(1);
    expect(SubscriptionItem::query()->count())->toBe(1); // cascade で道連れにしない
});

test('明細は親の ends_at で判定され、子 → 親の順に消える', function (): void {
    $threshold = BillingRetention::threshold();
    $expired = BillingRetentionFixtures::endedSubscription($threshold->subSecond());
    BillingRetentionFixtures::attachItem($expired);

    $live = BillingRetentionFixtures::endedSubscription($threshold->addSecond());
    BillingRetentionFixtures::attachItem($live);

    $itemPurger = app(SubscriptionItemPurger::class);
    expect($itemPurger->countExpired($threshold))->toBe(1);
    expect($itemPurger->countFailClosed($threshold))->toBe(0); // 継続中の親は異常ではない

    // registry の順 (子 → 親) で回す
    $results = [];
    foreach (app(BillingRetentionPurgerRegistry::class)->purgers() as $purger) {
        $results[$purger->target()->value] = $purger->purgeExpired($threshold);
    }

    expect($results[BillingRetentionTarget::SubscriptionItem->value]->processed)->toBe(1);
    expect($results[BillingRetentionTarget::Subscription->value]->processed)->toBe(1);
    expect(SubscriptionItem::query()->count())->toBe(1);
    expect(Subscription::query()->count())->toBe(1);
});

test('dry-run コマンドは 1 行も消さず target 別の件数を報告する', function (): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subSecond());
    BillingRetentionFixtures::createUnstarted(BillingRetentionTarget::TicketCheckoutSession, $threshold->subSecond());
    $subscription = BillingRetentionFixtures::endedSubscription($threshold->subSecond());
    BillingRetentionFixtures::attachItem($subscription);

    $this->artisan('billing:purge-retention-expired')
        ->expectsOutputToContain('[dry-run]')
        ->expectsOutputToContain('stripe_webhook_event: expired=1 processed=0 fail_closed=0')
        ->expectsOutputToContain('ticket_checkout_session: expired=0 processed=0 fail_closed=1')
        ->expectsOutputToContain('subscription: expired=1 processed=0 fail_closed=1')
        ->expectsOutputToContain('dry-run のため 1 行も変更していません')
        ->assertExitCode(0);

    expect(StripeWebhookEvent::query()->count())->toBe(1);
    expect(TicketCheckoutSession::query()->count())->toBe(1);
    expect(Subscription::query()->count())->toBe(1);
    expect(SubscriptionItem::query()->count())->toBe(1);
});

test('コマンドは台帳 (畳み込みで決着する target) も集計対象に含める', function (): void {
    $this->artisan('billing:purge-retention-expired')
        ->expectsOutputToContain('ticket_ledger_entry: expired=0')
        ->assertExitCode(0);
});

test('コマンドの出力に PII (組織名 / メール / Stripe 識別子) が現れない', function (): void {
    $threshold = BillingRetention::threshold();
    [$organization, $owner] = createOrganizationWithOwner('秘密の組織名');
    $session = TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->completed()
        ->create(['completed_at' => $threshold->subSecond()]);

    $exitCode = Artisan::call('billing:purge-retention-expired');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);

    expect($output)->not->toContain('秘密の組織名');
    expect($output)->not->toContain($owner->email);
    expect($output)->not->toContain($session->stripe_session_id);
    expect($output)->toContain('ticket_checkout_session: expired=1');
});

test('コマンドは未知の --target を拒否する', function (): void {
    $this->artisan('billing:purge-retention-expired', ['--target' => 'unknown_table'])
        ->expectsOutputToContain('未知の target です: unknown_table')
        ->assertExitCode(1);
});

test('コマンドは --target で 1 つに絞れる', function (): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subSecond());
    BillingRetentionFixtures::createStarted(BillingRetentionTarget::BillingCheckoutSession, $threshold->subSecond());

    $this->artisan('billing:purge-retention-expired', ['--target' => 'stripe_webhook_event'])
        ->expectsOutputToContain('stripe_webhook_event: expired=1')
        ->doesntExpectOutputToContain('billing_checkout_session:')
        ->assertExitCode(0);
});

test('コマンドは --apply で実際に決着させ、horizon の観測点を出力する', function (): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subSecond());
    BillingRetentionFixtures::expiredLedgerEntries($threshold->subSecond());

    $this->artisan('billing:purge-retention-expired', ['--apply' => true])
        ->expectsOutputToContain('[apply]')
        ->expectsOutputToContain('stripe_webhook_event: expired=1 processed=1')
        ->expectsOutputToContain('ticket_ledger_entry: expired=2 processed=2')
        ->expectsOutputToContain('horizon: OK (期限超過 0 件)')
        ->assertExitCode(0);

    expect(StripeWebhookEvent::query()->count())->toBe(0);
    // 台帳は消えるのではなく繰越行 1 行へ畳み込まれる (残高 10 - 4 = 6 が保存される)
    expect(TicketLedgerEntry::query()->count())->toBe(1);
    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(6);
});

test('--apply でも決着できない記録が残れば horizon は NG と報告する (終了コードは成功)', function (): void {
    $threshold = BillingRetention::threshold();
    // 明細が残っている契約は消せない (fail-closed)。「安全に残した」も規約から見れば残存である。
    // --target で親だけを回し、子が残ったまま = fail-closed の状態を作る
    BillingRetentionFixtures::attachItem(BillingRetentionFixtures::endedSubscription($threshold->subSecond()));

    $this->artisan('billing:purge-retention-expired', ['--apply' => true, '--target' => 'subscription'])
        ->expectsOutputToContain('subscription: expired=1 processed=0 fail_closed=1')
        ->expectsOutputToContain('horizon: NG (期限超過 1 件が残存')
        ->assertExitCode(0);

    expect(Subscription::query()->count())->toBe(1);
});

/*
 * horizon の fail-open 是正 (T146)。
 *
 * `remaining=0` は「期限超過が無い」とは限らない。**集計クエリ自体が失敗して数えられなかった**
 * ときも 0 になる (コマンドは件数不明を 0 で報告し `unexpected_failures` を立てる)。
 * 終了コードは FAILURE になるので機械は気づけるが、**人間が読む horizon 行が「OK」と嘘をつく**と
 * PR-C3 (規約文面の公開) の唯一の歯止めが外れる。よって失敗が 1 件でもあれば
 * horizon は **OK と読めない**表現でなければならない。
 */

/**
 * 指定 purger を「必ず例外を投げる」実装へ差し替える (集計クエリ失敗の再現)。
 *
 * registry は final だが `app($class)` で purger を解決するため、container への
 * bind がそのまま効く。
 */
function bindFailingBillingRetentionPurger(string $purgerClass, BillingRetentionTarget $target): void
{
    app()->bind($purgerClass, fn (): BillingRetentionPurger => new class($target) implements BillingRetentionPurger
    {
        public function __construct(private readonly BillingRetentionTarget $target) {}

        public function target(): BillingRetentionTarget
        {
            return $this->target;
        }

        public function countExpired(CarbonImmutable $threshold): int
        {
            throw new RuntimeException('集計に失敗した (テスト用)');
        }

        public function countFailClosed(CarbonImmutable $threshold): int
        {
            throw new RuntimeException('集計に失敗した (テスト用)');
        }

        public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
        {
            throw new RuntimeException('決着に失敗した (テスト用)');
        }
    });
}

test('集計に失敗した target があれば dry-run の horizon を OK と報告しない', function (): void {
    // 他 target は 1 件も持たないので、失敗を無視すると remaining 合計は 0 = 従来は OK と出ていた
    bindFailingBillingRetentionPurger(StripeWebhookEventPurger::class, BillingRetentionTarget::StripeWebhookEvent);

    $this->artisan('billing:purge-retention-expired')
        ->expectsOutputToContain('stripe_webhook_event: expired=0 processed=0 fail_closed=0 unexpected_failures=1 remaining=0')
        ->expectsOutputToContain('horizon: 判定不能 (処理または集計に失敗した target が 1 件')
        ->doesntExpectOutputToContain('horizon: OK')
        ->assertExitCode(1);
});

test('決着に失敗した target があれば --apply の horizon を OK と報告しない', function (): void {
    bindFailingBillingRetentionPurger(StripeWebhookEventPurger::class, BillingRetentionTarget::StripeWebhookEvent);

    $this->artisan('billing:purge-retention-expired', ['--apply' => true])
        ->expectsOutputToContain('horizon: 判定不能 (処理または集計に失敗した target が 1 件')
        ->doesntExpectOutputToContain('horizon: OK')
        ->assertExitCode(1);
});

test('失敗と実在の残存が同時にあっても件数を確定させず判定不能と報告する', function (): void {
    $threshold = BillingRetention::threshold();
    BillingRetentionFixtures::endedSubscription($threshold->subSecond());
    bindFailingBillingRetentionPurger(StripeWebhookEventPurger::class, BillingRetentionTarget::StripeWebhookEvent);

    $this->artisan('billing:purge-retention-expired')
        ->expectsOutputToContain('horizon: 判定不能 (処理または集計に失敗した target が 1 件。観測できた期限超過は 1 件だが、実数は不明)')
        ->doesntExpectOutputToContain('horizon: OK')
        ->doesntExpectOutputToContain('horizon: NG')
        ->assertExitCode(1);
});

test('負のコントロール: 失敗が無ければ従来どおり horizon: OK と報告する', function (): void {
    $this->artisan('billing:purge-retention-expired')
        ->expectsOutputToContain('unexpected_failures=0')
        ->expectsOutputToContain('horizon: OK (期限超過 0 件)')
        ->doesntExpectOutputToContain('判定不能')
        ->assertExitCode(0);
});

test('保持年数が 0 以下なら fail-fast する', function (): void {
    config()->set('legal.billing_retention_years', 0);

    expect(fn (): int => BillingRetention::years())->toThrow(InvalidArgumentException::class);
});

test('保持年数が整数でなければ fail-fast する', function (): void {
    config()->set('legal.billing_retention_years', '7');

    expect(fn (): int => BillingRetention::years())->toThrow(InvalidArgumentException::class);
});

test('閾値は保持年数ぶん過去であり、年の加減算は overflow しない', function (): void {
    $now = CarbonImmutable::parse('2032-02-29 12:00:00'); // 閏日

    expect(BillingRetention::threshold($now)->toDateTimeString())
        ->toBe('2025-02-28 12:00:00'); // 2025-03-01 へ溢れない
});
