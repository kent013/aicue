<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * P9: 課金 Checkout / portal の着地フィードバック種別。
 * Inertia::location() の full page redirect を跨いだ後、/billing 着地で
 * ユーザーに「購入を受け付けたか / 処理中か / 既に受付済みか」を伝える。
 *
 * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
 * 唯一の経路が本 feedback (one-shot)** になっている。
 *
 * one-shot は **着地 query を canonical URL へ 303 で畳み、kind を FLASH_KEY の
 * session flash (次の 1 リクエストのみ生存) で運ぶ**ことで担保する
 * (詳細は docs/architecture.md §サブスク契約 Checkout とオンボーディング着地)。
 */
enum BillingFeedbackKind: string
{
    /** 着地 hop で kind を運ぶ session flash キー (shared flash の 4 キーと衝突しない名前)。 */
    public const string FLASH_KEY = 'billing_feedback_kind';

    case PurchaseReceived = 'purchase_received';
    case PurchaseProcessing = 'purchase_processing';
    case PurchaseAlreadyReceived = 'purchase_already_received';
    case CheckoutRetryRequired = 'checkout_retry_required';
    case PortalReturned = 'portal_returned';

    /** ユーザーに提示する確定文言 (単一出典。flash には kind だけを載せる)。 */
    public function message(): string
    {
        return match ($this) {
            self::PurchaseReceived => 'お支払いを受け付けました。プランへの反映には数分かかる場合があります。',
            self::PurchaseProcessing => 'お支払いを確認しています。プラン反映までしばらくお待ちください。',
            self::PurchaseAlreadyReceived => 'この内容のお支払いは既に受け付け済みです。',
            self::CheckoutRetryRequired => 'お手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
            self::PortalReturned => 'お支払い管理画面から戻りました。',
        };
    }
}
