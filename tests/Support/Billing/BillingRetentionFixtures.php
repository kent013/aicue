<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Billing\Subscription;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Cashier\SubscriptionItem;

/**
 * 保持期間 (7 年) purge のテスト fixture。
 *
 * 目録 (`BillingRetentionTarget`) と 1:1 で対応する生成器を 1 箇所に置く。
 * 挙動テスト (`BillingRetentionPurgeTest`) と horizon テスト
 * (`BillingRetentionHorizonTest`) の両方から使うため、テストファイル内の
 * グローバル関数ではなくクラスに置いている (どちらか一方だけ実行しても壊れない)。
 *
 * 契約と Subscription 行の生成は tests/Pest.php の共有ヘルパへ委譲する
 * (Cashier の契約行の作り方を 2 箇所に増やさない)。
 */
final class BillingRetentionFixtures
{
    /** 起算済み (起算列が非 null = 取引が終了している) の行を作る。 */
    public static function createStarted(BillingRetentionTarget $target, CarbonImmutable $clock): void
    {
        match ($target) {
            BillingRetentionTarget::StripeWebhookEvent => StripeWebhookEvent::factory()
                ->processed($clock)->create(),
            BillingRetentionTarget::BillingCheckoutSession => BillingCheckoutSession::factory()
                ->completed()->create(['completed_at' => $clock]),
            BillingRetentionTarget::TicketCheckoutSession => TicketCheckoutSession::factory()
                ->completed()->create(['completed_at' => $clock]),
            BillingRetentionTarget::TicketAutoRechargeAttempt => TicketAutoRechargeAttempt::factory()
                ->paid()->create(['resolved_at' => $clock]),
            default => throw new InvalidArgumentException('自テーブル起算の target ではありません: '.$target->value),
        };
    }

    /** 起算されていない (起算列 null) 行を、補助時計 (created_at) を指定して作る。 */
    public static function createUnstarted(BillingRetentionTarget $target, CarbonImmutable $anomalyClock): void
    {
        match ($target) {
            BillingRetentionTarget::StripeWebhookEvent => StripeWebhookEvent::factory()
                ->failed()->create(['created_at' => $anomalyClock]),
            BillingRetentionTarget::BillingCheckoutSession => BillingCheckoutSession::factory()
                ->create(['completed_at' => null, 'created_at' => $anomalyClock]),
            BillingRetentionTarget::TicketCheckoutSession => TicketCheckoutSession::factory()
                ->create(['completed_at' => null, 'created_at' => $anomalyClock]),
            BillingRetentionTarget::TicketAutoRechargeAttempt => TicketAutoRechargeAttempt::factory()
                ->create(['resolved_at' => null, 'created_at' => $anomalyClock]),
            default => throw new InvalidArgumentException('補助時計を持つ target ではありません: '.$target->value),
        };
    }

    /** 終了済み (ends_at 指定) の契約を 1 件作る。 */
    public static function endedSubscription(CarbonImmutable $endsAt): Subscription
    {
        [$organization] = \createOrganizationWithOwner();
        $subscription = \createFakeSubscription($organization, status: 'canceled');
        $subscription->forceFill(['ends_at' => $endsAt])->save();

        /** @var Subscription $fresh */
        $fresh = $subscription->fresh();

        return $fresh;
    }

    /** 契約に明細を 1 件ぶら下げる。 */
    public static function attachItem(Subscription $subscription): SubscriptionItem
    {
        /** @var SubscriptionItem $item */
        $item = $subscription->items()->create([
            'stripe_id' => 'si_test_'.Str::random(20),
            'stripe_product' => 'prod_test',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        return $item;
    }

    /**
     * 期限超過の台帳行 (畳み込みで決着する target) を 1 組織ぶん作る。
     *
     * 付与と消費を 1 組ずつ置き、畳み込みが**合算して残高を保存する**ことを
     * horizon 側でも通す (残高保存そのものの検証は TicketLedgerCarryForwardTest)。
     */
    public static function expiredLedgerEntries(CarbonImmutable $clock): Organization
    {
        [$organization] = \createOrganizationWithOwner('台帳保持期間テスト組織');

        TicketLedgerEntry::factory()->forOrganization($organization)
            ->createdAt($clock)->purchased()->delta(10)->create();
        TicketLedgerEntry::factory()->forOrganization($organization)
            ->createdAt($clock)->purchased()->consumed(4)->create();

        return $organization;
    }

    /** 全 target ぶんの「期限超過だが決着できる」記録を作る (horizon 検査の母集団)。 */
    public static function seedExpiredRows(CarbonImmutable $threshold): void
    {
        self::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subDay());
        self::createStarted(BillingRetentionTarget::BillingCheckoutSession, $threshold->subDay());
        self::createStarted(BillingRetentionTarget::TicketCheckoutSession, $threshold->subDay());
        self::createStarted(BillingRetentionTarget::TicketAutoRechargeAttempt, $threshold->subDay());

        self::attachItem(self::endedSubscription($threshold->subDay()));

        self::expiredLedgerEntries($threshold->subDay());
    }
}
