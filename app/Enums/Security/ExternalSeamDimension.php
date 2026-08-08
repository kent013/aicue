<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 外部到達を数える「次元」。
 *
 * ★**次元そのものの数え落としは検出できない** (定義は人手)。未知の設定面や新しい SDK 表面が
 *   第 3 の次元を作った場合、gate は沈黙する。保証は登録済みの種別 × 次元の網羅に限る。
 */
enum ExternalSeamDimension: string
{
    /** どのクラスが外へ出るか (app/ の静的走査で数える)。 */
    case CodeReachPoint = 'code_reach_point';

    /** どこへ出るか (設定で増える宛先集合)。 */
    case DestinationSet = 'destination_set';
}
