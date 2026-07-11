<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * 直前のチケット購入手続きが進行中 (org 単位 lock の競合 / 決済処理中の expire 拒否)。
 *
 * fail-closed: ロックなし実行・二重 live session の作成へフォールバックせず、
 * ユーザーへ「数秒おいて再試行」を案内する (controller が back()->with('error') に変換)。
 */
class CheckoutInProgressException extends RuntimeException {}
