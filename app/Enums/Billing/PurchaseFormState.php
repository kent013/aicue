<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * チケット購入フォームの状態 (P8b / tc-5。aigenba PurchaseFormState 相当)。
 *
 * - Normal: 新規購入フォーム (fresh attempt_token)。
 * - Resume: 進行中 (live pending) Checkout への復帰。枚数は session に固定 (boundCount)。
 * - Completed: 直近 (purchase_resume_window_minutes 窓内) の完了。反映待ち案内 +
 *   「もう一度購入する」(?fresh=1) のみを出す。
 *
 * どの状態でも入力・ボタンを disabled にはしない (禁止事項 #8)。resume / completed では
 * 購入フォーム自体を描画せず、読み取りテキストと明示的な CTA に置き換える。
 */
enum PurchaseFormState: string
{
    case Normal = 'normal';
    case Resume = 'resume';
    case Completed = 'completed';
}
