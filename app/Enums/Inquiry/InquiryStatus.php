<?php

declare(strict_types=1);

namespace App\Enums\Inquiry;

/**
 * 問い合わせ (Inquiry) の対応ステータス。運営が Filament で遷移させる。
 *
 * Closed への遷移で closed_at が自動集約される (Inquiry::booted の updating フック)。
 */
enum InquiryStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Open => '未対応',
            self::InProgress => '対応中',
            self::Closed => '完了',
            self::Spam => 'スパム',
        };
    }
}
