<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use RuntimeException;

/**
 * 証明書取得失敗等の一時障害で署名検証ができなかった。
 *
 * middleware はこれを 503 に変換する → SNS が再試行する。一時障害で正当通知を
 * 恒久ドロップ (= 抑止漏れ) する事故を避けるための分類。
 */
final class SnsVerificationUnavailableException extends RuntimeException {}
