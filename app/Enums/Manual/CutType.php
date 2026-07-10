<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * カットの種別 (doc/10 §10.1)。
 * Step = 手順カット、Point = 急所カット (parent_cut_id で親手順に従属)。
 */
enum CutType: string
{
    case Step = 'step';
    case Point = 'point';
}
