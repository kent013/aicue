<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * production runtime で未 sync の test mode Price (= livemode=false or synced_at IS NULL)
 * を checkout 経路に使おうとしたとき。
 * 通常は deploy 手順での sync 実行漏れが原因。
 */
class StripePriceNotSyncedException extends RuntimeException
{
    public function __construct(string $lookupKey, string $message = '')
    {
        if ($message === '') {
            $message = sprintf(
                'Stripe Price (lookup_key=%s) が未 sync の test mode のままです。 deploy 手順を確認してください。',
                $lookupKey,
            );
        }
        parent::__construct($message);
    }
}
