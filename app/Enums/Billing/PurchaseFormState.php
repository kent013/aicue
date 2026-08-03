<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * チケット購入フォームの状態 (P8b / tc-5。aigenba PurchaseFormState 相当)。
 *
 * - Normal: 新規購入フォーム (fresh attempt_token)。
 * - Resume: 進行中 (live pending) Checkout への復帰。枚数は session に固定 (boundCount)。
 */
enum PurchaseFormState: string
{
    case Normal = 'normal';
    case Resume = 'resume';
}
