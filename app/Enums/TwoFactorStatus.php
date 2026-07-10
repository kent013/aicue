<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 顧客 User (web guard) の TOTP 2FA 状態。Fortify の 2 カラム
 * (two_factor_secret / two_factor_confirmed_at) から導出する 3 値の状態機械。
 *
 * bool 化すると「未設定」と「設定途中 (secret 生成済・TOTP 未確認)」が潰れ、
 * 設定途中ユーザーの設定ページ再訪で secret を再生成して認証アプリ側エントリを
 * 無効化してしまうため、pending を独立した状態として保つ。
 */
enum TwoFactorStatus: string
{
    case Disabled = 'disabled';
    case Pending = 'pending';
    case Enabled = 'enabled';
}
