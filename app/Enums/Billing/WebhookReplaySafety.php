<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 保存済み webhook payload を**再実行してよいか**の分類。
 *
 * **`SafeToReplay` の意味は「再実行しても追加の被害を生まない」に限定される。**
 * 「再実行すれば復旧する」ではない (復旧するかどうかは各ハンドラの事情による)。
 *
 * 分類の単一出典は `HandledStripeWebhookEvent::replaySafety()` の網羅 match で、
 * 滞留回収 (`StripeWebhookProcessor::recoverStale`) が自動再実行の可否に使う唯一の判断材料。
 * **ハンドラに副作用を足したら分類を再審査すること** (順序に依存する書き込みを足したら
 * `OrderSensitive` へ移す)。
 */
enum WebhookReplaySafety: string
{
    /** 再実行しても追加の被害を生まない (付与は台帳の idempotency_key UNIQUE で冪等)。 */
    case SafeToReplay = 'safe_to_replay';

    /** 順序に依存する (古い payload を後から流すと状態が巻き戻る)。 */
    case OrderSensitive = 'order_sensitive';
}
