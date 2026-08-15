<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use App\Models\Billing\BillingNotification;
use App\Models\Billing\OrganizationQuota;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Billing\TicketReservation;
use App\Models\Billing\TicketVolumePrice;
use Illuminate\Database\Eloquent\Model;

/**
 * 保持期間 (7 年) の purge 対象**外**と裁定した課金モデルの目録。
 *
 * 「取引記録ではない」か「保持ポリシーの所有者が別にいる」かのどちらかであること。
 * {@see BillingRetentionTarget} との合計が課金モデルの母集団と exact-fit であることは
 * `BillingRetentionTargetInventoryTest` が deny-by-default で機械強制する。
 *
 * ★除外は「消さない」の宣言であって「消せない」ではない。所有者が別にいるものは
 *   **その所有者の側で保持期間を持つ**こと (ここで二重に持つと決着が分岐する)。
 */
enum BillingRetentionExclusion: string
{
    case BillingNotification = 'billing_notification';
    case TicketReservation = 'ticket_reservation';
    case Plan = 'plan';
    case PlanPrice = 'plan_price';
    case TicketVolumePrice = 'ticket_volume_price';
    case OrganizationQuota = 'organization_quota';
    case TicketAutoRecharge = 'ticket_auto_recharge';

    /**
     * 対象モデル (母集団との突合キー)。
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::BillingNotification => BillingNotification::class,
            self::TicketReservation => TicketReservation::class,
            self::Plan => Plan::class,
            self::PlanPrice => PlanPrice::class,
            self::TicketVolumePrice => TicketVolumePrice::class,
            self::OrganizationQuota => OrganizationQuota::class,
            self::TicketAutoRecharge => TicketAutoRecharge::class,
        };
    }

    /** なぜ保持期間の対象にしないのか (30 文字以上)。 */
    public function rationale(): string
    {
        return match ($this) {
            self::BillingNotification => 'メール送達の重複防止台帳。UNIQUE が冪等の調停者であり、消すと同じ請求書の通知が再送される。'
                .'保持ポリシーの所有者は課金リマインダ機能である',
            self::TicketReservation => 'TTL で解放される一時状態であって取引記録ではない。'
                .'所有者は既存の滞留回収 (work:recover-stuck --stream=ticket_reservation) である',
            self::Plan => '価格カタログ (現在提供している商品の定義) であって取引の記録ではない',
            self::PlanPrice => 'Stripe Price のカタログ snapshot であって取引の記録ではない。過去行は価格改定の履歴として残す',
            self::TicketVolumePrice => 'チケット単価のカタログ snapshot であって取引の記録ではない。過去行は価格改定の履歴として残す',
            self::OrganizationQuota => '組織ごとの現在の上限設定値 (容量・人数) であって取引の記録ではない。契約中は常に参照される',
            self::TicketAutoRecharge => 'オートリチャージの現在の設定値と同意記録であって取引の記録ではない。'
                .'実際の課金試行は ticket_auto_recharge_attempts が持つ',
        };
    }
}
