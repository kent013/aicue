<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * メール送信抑止 (サプレッション) の理由。
 *
 * SES の Permanent バウンスと苦情 (complaint) のみを抑止対象とする。
 * `manual` / `provider` 等は先取りしない (解除は行削除で表現するため reason 不要)。
 */
enum EmailSuppressionReason: string
{
    case Bounce = 'bounce';       // Permanent バウンス (届かなかった)
    case Complaint = 'complaint'; // 苦情 (受信者が迷惑メール報告した)
}
