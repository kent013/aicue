<?php

declare(strict_types=1);

namespace App\Services\Help;

/**
 * 生成物 1 件の状態。**この 4 値がすべて**である。
 *
 * ★`Orphan` は「manifest に無いのに生成物ディレクトリに居る」であり、
 *   「違反 0 件」に畳まずに独立の種別として残す (消滅と検査不能を混同しない)。
 */
enum HelpArtifactState: string
{
    case UpToDate = 'up_to_date';
    case Stale = 'stale';
    case Missing = 'missing';
    case Orphan = 'orphan';
}
