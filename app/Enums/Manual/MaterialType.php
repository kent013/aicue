<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * カット素材の種別 (doc/10 §10.1)。Video = 動画、Still = 静止画 (静止表示秒数と対)。
 */
enum MaterialType: string
{
    case Video = 'video';
    case Still = 'still';
}
