<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * 認証手段の変更を本人へメール通知する対象イベント (T110)。
 *
 * 発火点対応表 (どの vendor イベント / Service 呼び出しがどの case を発火させるか、
 * transaction の有無) は docs/architecture.md §認証手段変更のメール通知ポリシー が正本。
 * 対象は「本人が自分の認証手段を変更したとき」に限る。ログインのたびの通知・
 * 組織管理者によるメンバー操作 (別ポリシー。`TwoFactorResetSecurityNotification`) は含まない。
 */
enum AuthMethodChangeEvent: string
{
    case PasswordSet = 'password_set';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case PasskeyRegistered = 'passkey_registered';
    case PasskeyDeleted = 'passkey_deleted';
    case SocialAccountLinked = 'social_account_linked';

    /** メール本文の見出し文 (秘密情報は含めない)。 */
    public function headline(): string
    {
        return match ($this) {
            self::PasswordSet => 'パスワードが設定されました',
            self::PasswordChanged => 'パスワードが変更されました',
            self::PasswordReset => 'パスワードがリセットされました',
            self::TwoFactorEnabled => '2 段階認証が有効化されました',
            self::TwoFactorDisabled => '2 段階認証が無効化されました',
            self::RecoveryCodesRegenerated => '2 段階認証の回復コードが再発行されました',
            self::PasskeyRegistered => 'パスキーが追加されました',
            self::PasskeyDeleted => 'パスキーが削除されました',
            self::SocialAccountLinked => '外部ログインが連携されました',
        };
    }
}
