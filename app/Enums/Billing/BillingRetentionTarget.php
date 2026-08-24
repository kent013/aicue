<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Billing\Subscription;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Billing\TicketLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\SubscriptionItem;

/**
 * 保持期間 (7 年) の purge 対象となる課金記録の目録。
 *
 * 「消さない」と決めたものは {@see BillingRetentionExclusion} へ根拠付きで登録する。
 * 母集団 (app/Models/Billing 配下 + Cashier の SubscriptionItem) がどちらかに
 * ちょうど 1 回現れることは `BillingRetentionTargetInventoryTest` が deny-by-default で
 * 機械強制する (テストクラスへの参照は app → tests の import を生むため書かない)。
 *
 * ★**目録は人間の申告である**。網羅性は機械では保証できない — 課金取引の記録が
 *   app/Models/Billing の外や Eloquent を経由しない表に置かれれば、目録も gate も沈黙する。
 */
enum BillingRetentionTarget: string
{
    case StripeWebhookEvent = 'stripe_webhook_event';
    case BillingCheckoutSession = 'billing_checkout_session';
    case TicketCheckoutSession = 'ticket_checkout_session';
    case TicketAutoRechargeAttempt = 'ticket_auto_recharge_attempt';
    case SubscriptionItem = 'subscription_item';
    case Subscription = 'subscription';
    case TicketLedgerEntry = 'ticket_ledger_entry';

    /**
     * 対象モデル (母集団との突合キー)。
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::StripeWebhookEvent => StripeWebhookEvent::class,
            self::BillingCheckoutSession => BillingCheckoutSession::class,
            self::TicketCheckoutSession => TicketCheckoutSession::class,
            self::TicketAutoRechargeAttempt => TicketAutoRechargeAttempt::class,
            self::SubscriptionItem => SubscriptionItem::class,
            self::Subscription => Subscription::class,
            self::TicketLedgerEntry => TicketLedgerEntry::class,
        };
    }

    /** 対象テーブル (起算点の修飾名を解決する基準)。 */
    public function table(): string
    {
        $model = $this->modelClass();

        return (new $model)->getTable();
    }

    /**
     * 正規の保持起算点 (clock start)。
     *
     * **自テーブルの列名、または `{table}.{column}` の修飾名**を返す
     * (子 target は親テーブルの列を起算点にするため修飾名が要る)。
     */
    public function clockStartColumn(): string
    {
        return match ($this) {
            self::StripeWebhookEvent => 'processed_at',
            self::BillingCheckoutSession, self::TicketCheckoutSession => 'completed_at',
            self::TicketAutoRechargeAttempt => 'resolved_at',
            // 子は親 (subscriptions) の契約終了日で判定する
            self::SubscriptionItem => 'subscriptions.ends_at',
            self::Subscription => 'ends_at',
            // 台帳は取引成立の時点で起算済み (null にならない)。
            // 決着は単純削除ではなく二段判定の畳み込み (App\Services\Billing\Retention\TicketLedgerCarryForwardService)
            self::TicketLedgerEntry => 'created_at',
        };
    }

    /**
     * **起算点が null の状態を「異常」として検出し始めるための補助時計**。
     *
     * 起算列が null だと、その列だけでは「古い」を判定できない。補助時計を使って
     * `{clockStart} IS NULL AND {anomalyClock} <= threshold` を **fail-closed** に計上する
     * (例: 未処理 webhook = `processed_at IS NULL AND created_at <= threshold`)。
     * 計上するだけで**消さない** — 判定できないものを消すのは fail-open である。
     *
     * **null を返す target は異常検出をしない**。`Subscription` / `SubscriptionItem` は
     * `ends_at IS NULL` が**正常な起算未到来** (継続中の契約) であって異常ではないため、
     * 明示的に対象から外す。`TicketLedgerEntry` は正規の起算点が null にならない。
     */
    public function anomalyClockColumn(): ?string
    {
        return match ($this) {
            self::StripeWebhookEvent,
            self::BillingCheckoutSession,
            self::TicketCheckoutSession,
            self::TicketAutoRechargeAttempt => 'created_at',
            self::SubscriptionItem, self::Subscription, self::TicketLedgerEntry => null,
        };
    }

    /** なぜこれが保持期間の対象なのか (30 文字以上)。 */
    public function rationale(): string
    {
        return match ($this) {
            self::StripeWebhookEvent => '決済事業者からの通知そのものの記録。処理完了 (processed_at) をもって取引の決着とみなす',
            self::BillingCheckoutSession => 'サブスク契約の決済手続きの記録。完了時刻 (completed_at) が取引の成立日時である',
            self::TicketCheckoutSession => 'チケット買い切り購入の決済手続きの記録。完了時刻 (completed_at) が取引の成立日時である',
            self::TicketAutoRechargeAttempt => 'オートリチャージの課金試行の記録。決着時刻 (resolved_at) をもって取引の終了とみなす',
            self::SubscriptionItem => '継続課金の明細 (価格・数量) の記録。親契約の終了日 (subscriptions.ends_at) で起算する',
            self::Subscription => '継続課金契約そのものの記録。契約終了日 (ends_at) が保持期間の起算点である',
            self::TicketLedgerEntry => 'チケット残高の取引台帳。append-only のため物理削除ではなく繰越 (畳み込み) で決着させる',
        };
    }
}
