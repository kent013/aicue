<?php

declare(strict_types=1);

namespace App\Enums\Capture;

/**
 * テイク登録の 409 競合種別 (概念設計 D4)。
 * 409 = 「リクエスト自体は正当だが今は確定できない」(422 の恒久拒否と明確に区別)。
 */
enum CaptureConflictType: string
{
    /** 同一予約を別リクエストが検証中 (fresh verifying)。リトライ可能 */
    case RegistrationInFlight = 'registration_in_flight';

    /** completed 予約に対応する Take が不在 / path 矛盾 (整合性異常。削除せず調査可能な状態を残す) */
    case ReservationInconsistent = 'reservation_inconsistent';

    public function message(): string
    {
        return match ($this) {
            self::RegistrationInFlight => 'このテイクは現在登録処理中です。しばらく待って再試行してください。',
            self::ReservationInconsistent => 'アップロード予約の整合性エラーが発生しました。管理者にお問い合わせください。',
        };
    }
}
