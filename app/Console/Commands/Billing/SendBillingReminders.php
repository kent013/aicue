<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Enums\Billing\BillingNotificationType;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Notifications\Billing\RenewalReminderNotification;
use App\Services\Billing\BillingNotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 請求予告 (更新リマインダー) を日次で送る scheduler コマンド。
 *
 * 予告は webhook 単発では拾えない「N 日前」条件のため cron 駆動。冪等は dedup_key
 * `{stripe_id}:{該当日時 epoch}` を持つ delivery record で担保し、同日 rerun は二重送信しない。
 *
 * テンプレートは renewal (current_period_end が窓内の active 契約) のみ。
 * 派生アプリはトライアル終了 / プラン移行等の予告種別を同型のクエリ + dispatch で追加する。
 */
class SendBillingReminders extends Command
{
    /** 期日の何日前から通知するか。日次ジョブが 1 日落ちても拾えるよう余裕を持たせる。 */
    private const int RENEWAL_DAYS_BEFORE = 3;

    protected $signature = 'billing:send-billing-reminders {--days= : 事前日数を上書き (0 以上の整数)}';

    protected $description = '次回更新が近い active 契約へ更新予告を送る (daily)';

    public function handle(BillingNotificationDispatcher $dispatcher): int
    {
        $daysOption = $this->option('days');
        if ($daysOption !== null && ! ctype_digit((string) $daysOption)) {
            $this->error('--days must be a non-negative integer.');

            return self::FAILURE;
        }
        $days = $daysOption !== null ? (int) $daysOption : self::RENEWAL_DAYS_BEFORE;

        $counts = $this->sendRenewalReminders($dispatcher, CarbonImmutable::now(), $days);
        $this->info(sprintf(
            'renewal: queued=%d skipped=%d failed=%d',
            $counts['queued'],
            $counts['skipped'],
            $counts['failed'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{queued: int, skipped: int, failed: int}
     */
    private function sendRenewalReminders(BillingNotificationDispatcher $dispatcher, CarbonImmutable $now, int $days): array
    {
        $counts = ['queued' => 0, 'skipped' => 0, 'failed' => 0];

        Subscription::query()
            ->where('stripe_status', 'active')
            // 解約予約済み (cancel at period end) は更新されないため対象外。
            ->whereNull('ends_at')
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [$now, $now->addDays($days)])
            ->with('organization')
            ->chunkById(100, function (Collection $subscriptions) use ($dispatcher, &$counts): void {
                /** @var Collection<int, Subscription> $subscriptions */
                foreach ($subscriptions as $sub) {
                    $this->dispatchReminderFor($dispatcher, $sub, $counts);
                }
            });

        return $counts;
    }

    /**
     * 1 subscription 分の予告 dispatch。1 件失敗で batch を止めない。
     *
     * @param  array{queued: int, skipped: int, failed: int}  $counts
     */
    private function dispatchReminderFor(BillingNotificationDispatcher $dispatcher, Subscription $sub, array &$counts): void
    {
        try {
            $org = $sub->organization;
            if (! $org instanceof Organization) {
                return;
            }
            // dedup_key は {stripe_id}:{epoch}。stripe_id が空のデータ異常はキー衝突を招くため明示検証
            // (異常時は下の catch で failed カウント + warning、batch は継続)。
            Assert::stringNotEmpty($sub->stripe_id);
            $end = $sub->current_period_end;
            Assert::notNull($end);
            $effective = CarbonImmutable::instance($end);
            $dedupKey = $sub->stripe_id.':'.$effective->getTimestamp();

            $result = $dispatcher->sendReminderOnce(
                $org,
                BillingNotificationType::RenewalReminder,
                $dedupKey,
                new RenewalReminderNotification($dedupKey, $org->name, route('billing.index', ['organization' => $org->slug]), $effective),
            );
            $counts[$result->value]++;
        } catch (Throwable $e) {
            $counts['failed']++;
            // PII 抑制: 例外メッセージは記録せずクラス名のみ。
            Log::warning('billing reminder iteration failed', [
                'subscription_id' => $sub->id,
                'error_class' => $e::class,
            ]);
        }
    }
}
