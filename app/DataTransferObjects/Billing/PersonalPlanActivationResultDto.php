<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * Personal (free) プラン有効化の結果。
 *
 * granted = 初回無償チケットをこの有効化で付与したか (org 単位 1 回マーカーを先取した場合のみ
 * true。付与済み org の有効化では false になり、flash 文言の分岐に使う)。
 */
final readonly class PersonalPlanActivationResultDto
{
    public function __construct(
        public bool $granted,
    ) {}
}
