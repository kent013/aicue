<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * P9 (N-1): 同一 `subscription_attempt_token` で **別プラン** の checkout が再送された。
 *
 * `Billing/Plans` は 1 render = 1 token のため「Starter を押して戻り Standard を押す」が
 * 同 token・別 plan として実在する。移植元 (aigenba) は保存済み session の plan の
 * Checkout URL へ replay するが、それでは **押した plan と違う plan の Checkout に着地**する。
 * AI-CUE は fail-closed に 422 (`plan_code`) へ倒し、ユーザーに再読み込みを促す
 * (先例: TicketCheckoutService の StaleCheckoutAttemptException 分岐)。
 */
final class SubscriptionAttemptPlanMismatchException extends RuntimeException {}
