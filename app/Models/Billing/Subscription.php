<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\ScheduleSetupStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Cashier Subscription のテンプレート拡張 (AppServiceProvider の
 * Cashier::useSubscriptionModel で差し替え登録)。
 *
 * 追加列:
 * - current_period_end: 次回更新日時 (renewal reminder の真実源。
 *   StripeWebhookProcessor が customer.subscription.created/updated から同期する)
 * - stripe_schedule_id / schedule_setup_status: Subscription Schedule の
 *   2 段 API call (create → update phases) の部分完了追跡
 *   (billing:reconcile-schedules が復旧する。ScheduleSetupStatus 参照)
 * - has_payment_method: 決済手段が登録済みか (monotonic snapshot。true から false へ戻さない)。
 *   SubscriptionService::deriveEntitlement が trial 終了後の遮断判定に使う
 * - past_due_since: 支払い失敗 (stripe_status='past_due') を**観測した**時刻 =
 *   支払い猶予の起点。期限の計算は PaymentGracePolicy が唯一の正本で、書込は
 *   SubscriptionService に閉じる (PastDueSinceWriteInvariantTest)
 *
 * schedule 列は状態キーのため markSchedule* / clearSchedule 経由でのみ変更する。
 *
 * @property int $id
 * @property int $organization_id
 * @property string $stripe_id
 * @property string $stripe_status
 * @property bool $has_payment_method
 * @property Carbon|null $past_due_since
 * @property Carbon|null $current_period_end
 * @property string|null $stripe_schedule_id
 * @property ScheduleSetupStatus $schedule_setup_status
 */
class Subscription extends CashierSubscription
{
    /**
     * Cashier 既定は $guarded=[] (全開放) だが、テナント/所有権キーを payload から
     * 信頼しない不変条件 (MassAssignmentSafetyTest) に合わせて id / organization_id を
     * guard する。organization_id は billable relation (Organization->subscriptions()) が
     * FK として自動設定し、Cashier は mass-assign しないため課金経路は不変。
     *
     * @var list<string>
     */
    protected $guarded = ['id', 'organization_id'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** schedule 生成 (create API 成功) を記録する。phases 未設定の部分完了状態。 */
    public function markScheduleCreated(string $scheduleId): void
    {
        $this->forceFill([
            'stripe_schedule_id' => $scheduleId,
            'schedule_setup_status' => ScheduleSetupStatus::Created,
        ])->save();
    }

    /** phases 設定完了 (update API 成功) を記録する。 */
    public function markScheduleConfigured(): void
    {
        $this->forceFill([
            'schedule_setup_status' => ScheduleSetupStatus::Configured,
        ])->save();
    }

    /** schedule の解除 (release / remote 消失) を記録する。 */
    public function clearSchedule(): void
    {
        $this->forceFill([
            'stripe_schedule_id' => null,
            'schedule_setup_status' => ScheduleSetupStatus::None,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'past_due_since' => 'datetime',
            'has_payment_method' => 'boolean',
            'schedule_setup_status' => ScheduleSetupStatus::class,
        ];
    }
}
