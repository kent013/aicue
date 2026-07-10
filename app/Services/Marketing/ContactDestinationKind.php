<?php

declare(strict_types=1);

namespace App\Services\Marketing;

/**
 * 問い合わせ CTA の宛先種別。フロント側で Inertia 遷移 / 外部リンク / mailto を出し分ける。
 */
enum ContactDestinationKind: string
{
    case Internal = 'internal'; // 内部 Inertia route (/contact 等)
    case External = 'external'; // 外部 URL (http/https)
    case Mailto = 'mailto';     // mailto:
}
