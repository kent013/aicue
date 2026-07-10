<?php

declare(strict_types=1);

namespace App\Enums\Inquiry;

/**
 * 問い合わせ (Inquiry) の流入元。CTA ごとに付与される allowlist。
 *
 * 公開フォームの `source` はこの allowlist で検証し、該当しない値は null に
 * 正規化して保存する (自由入力を正本に残さず流入元分析の信頼性を担保)。
 * 派生アプリは導線追加時に case を増やす。
 */
enum InquirySource: string
{
    case Landing = 'landing';
    case Billing = 'billing';

    public function label(): string
    {
        return match ($this) {
            self::Landing => 'トップページ',
            self::Billing => '請求画面',
        };
    }

    /**
     * 入力値を allowlist で正規化する。該当しなければ null。
     */
    public static function normalize(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
