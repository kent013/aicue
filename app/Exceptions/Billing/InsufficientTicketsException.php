<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * チケット残高不足 (reserve 時)。
 * web 経路では bootstrap/app.php の exceptions.render で back + error flash に変換される。
 */
class InsufficientTicketsException extends RuntimeException
{
    public static function forReserve(int $requested, int $balance): self
    {
        return new self("チケット残高が不足しています (必要: {$requested} / 残高: {$balance})。");
    }
}
