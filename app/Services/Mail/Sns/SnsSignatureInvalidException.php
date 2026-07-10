<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use RuntimeException;

/**
 * SNS 署名が不正 / 証明書 URL が信頼できない (恒久エラー)。middleware は 403 を返す。
 */
final class SnsSignatureInvalidException extends RuntimeException {}
