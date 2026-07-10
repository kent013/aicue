<?php

declare(strict_types=1);

namespace App\Enums\Inquiry;

/**
 * 問い合わせ (Inquiry) の種別。公開フォームの種別 select 用。
 *
 * テンプレートでは汎用 case のみ提供する。派生アプリはドメイン固有の case
 * (デモ希望 / 料金相談等) をここに追加する。
 */
enum InquiryType: string
{
    case General = 'general';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::General => '一般的なお問い合わせ',
            self::Other => 'その他',
        };
    }
}
