<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Stripe の契約照会が失敗した (存在しないのではなく、確認できなかった)。
 *
 * gateway が Stripe SDK の例外を境界の外へ出さないための変換先。
 * 「契約が無い」(= 404) は `null` 返却で表し、本例外は使わない。
 */
final class SubscriptionLookupFailedException extends RuntimeException {}
