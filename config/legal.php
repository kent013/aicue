<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Legal Configuration
|--------------------------------------------------------------------------
|
| 利用規約・プライバシーポリシーの同意管理と、問い合わせ (Inquiry) の
| PII 保持・通知に関する設定。
|
*/

return [

    /*
    | 利用規約・プライバシーポリシーの同意バージョン。規約改定時に更新する。
    | ユーザー登録時は users.consent_version、問い合わせ受付時は
    | inquiries.consent_version へ同意の証跡として記録される。
    */
    'consent_version' => env('LEGAL_CONSENT_VERSION', 'draft-1'),

    /*
    | Inquiry の保持日数 (spam は created_at 基準、closed は closed_at 基準)。
    | inquiry:purge (daily) がこの日数を超過した行を削除する。
    */
    'inquiry_retention_days' => (int) env('LEGAL_INQUIRY_RETENTION_DAYS', 365),

    /*
    | 問い合わせ受付通知 (運営宛) の宛先。INQUIRY_RECIPIENT 優先、未設定時は
    | MAIL_FROM_ADDRESS、それも未設定なら mail.php と同一の default に fallback
    | (null 化を防ぐ)。この mailbox は PII 保管先として扱うこと。
    */
    'inquiry_recipient' => env('INQUIRY_RECIPIENT') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'),

];
