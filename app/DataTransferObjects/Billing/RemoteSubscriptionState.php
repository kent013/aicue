<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Services\Billing\SubscriptionSnapshot;

/**
 * Stripe から読んだ契約 1 件の観測結果 (日次突き合わせの入力)。
 *
 * webhook が payload から組むのと**同じ値オブジェクト** (`SubscriptionSnapshot`) を運ぶ。
 * これにより突き合わせは列を直接書かず、webhook と同じ単一 writer
 * (`SubscriptionService::applySubscriptionSnapshot`) を通れる。
 */
final readonly class RemoteSubscriptionState
{
    /**
     * @param  bool|null  $hasPaymentMethod  **null は「決済手段が無い」ではなく「観測できなかった」**
     *                                       (契約に決済手段が紐づかず顧客既定を使う場合を含む)。
     *                                       書込は `=== true` のときだけ行う (単調更新を壊さない)。
     */
    public function __construct(
        public SubscriptionSnapshot $snapshot,
        public ?bool $hasPaymentMethod,
    ) {}
}
