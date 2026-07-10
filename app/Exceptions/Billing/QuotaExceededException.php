<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Enums\QuotaKey;
use RuntimeException;

/**
 * Quota 上限超過 (QuotaService::check)。
 * web 経路では bootstrap/app.php の exceptions.render で back + error flash に変換される。
 * メッセージは機械可読キーではなく QuotaKey::label() の日本語表示に揃える。
 */
class QuotaExceededException extends RuntimeException
{
    public static function forLimit(QuotaKey $key, int $limit): self
    {
        return new self("現在のプランの上限 ({$key->label()}: {$limit}) に達しています。プランのアップグレードをご検討ください。");
    }
}
