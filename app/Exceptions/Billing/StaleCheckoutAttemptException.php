<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * attempt_token が再利用できない状態 (count 不一致・期限切れ・並行 race の非 replayable 行)。
 *
 * 画面の再読み込みで新しい attempt_token を発行してやり直してもらう
 * (controller が back()->with('error') に変換)。
 */
class StaleCheckoutAttemptException extends RuntimeException {}
