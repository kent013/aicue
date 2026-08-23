<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Auth\EmailPromotionStageBoundary;
use App\Models\User;

/**
 * 本番の継ぎ目。**何もしない**。
 *
 * ★「何もしない実装」であることを名前で言い切る (`Inert` = 不活性)。
 *   ここに処理が足されたら、それは 2 段の間に本番の副作用を挟んだということであり、
 *   レビューで必ず目に入る。
 */
final readonly class InertEmailPromotionStageBoundary implements EmailPromotionStageBoundary
{
    public function afterConsume(User $user): void
    {
        // 何もしない (継ぎ目に名前を与えるためだけの実装)
    }
}
