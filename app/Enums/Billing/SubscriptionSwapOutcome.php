<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * subscription の base Price 差し替え (プラン変更) の結果。
 *
 * - Applied: Stripe に update を送って受理された (accepted)。
 * - AlreadyOnTargetPrice: remote が既に対象 Price だったため update を送っていない
 *   (webhook 反映待ち中の再操作 / idempotency key 期限切れ後の再操作で到達する)。
 *
 * どちらも「利用者から見た結末は同じ (対象プランで確定済み)」。呼び出し側は flash 文言の
 * 出し分けにのみ使う。`organizations.plan_code` の追随 (projection_synced) は webhook が担う。
 */
enum SubscriptionSwapOutcome: string
{
    case Applied = 'applied';
    case AlreadyOnTargetPrice = 'already_on_target_price';
}
