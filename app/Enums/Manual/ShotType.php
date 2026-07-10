<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * カットの画角指定 (doc/10 §10.1)。AI がカット設計時に指示する撮り方。
 * Hiki = 引き (全体)、Yori = 寄り (手元アップ)。
 */
enum ShotType: string
{
    case Hiki = 'hiki';
    case Yori = 'yori';
}
