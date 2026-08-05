<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * security_audit_events に記録するイベント種別 (固定集合)。
 * 追加時は RecordSecurityEvent の購読 map も同一 PR で更新すること。
 */
enum SecurityEventType: string
{
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    // パスワード初回設定 (PasswordCredentialService が直接記録。RecordSecurityEvent の購読対象外)
    case PasswordSet = 'password_set';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case EmailChanged = 'email_changed';
    case AccountDeleted = 'account_deleted';
    case SocialAccountLinked = 'social_account_linked';
    case OwnershipTransferred = 'ownership_transferred';
    case ApiKeyIssued = 'api_key_issued';
    case ApiKeyRevoked = 'api_key_revoked';
    // CLI (admin:reset-mfa) から直接記録する (RecordSecurityEvent の購読対象外)
    case AdminMfaReset = 'admin_mfa_reset';
    // 組織管理者によるメンバー 2FA リセット (OrganizationMemberController::resetTwoFactor が
    // 直接記録する。RecordSecurityEvent の購読対象外)
    case OrgMemberTwoFactorReset = 'org_member_two_factor_reset';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'ログイン',
            self::LoginFailed => 'ログイン失敗',
            self::Logout => 'ログアウト',
            self::PasswordReset => 'パスワードリセット',
            self::PasswordChanged => 'パスワード変更',
            self::PasswordSet => 'パスワード設定',
            self::TwoFactorEnabled => '2要素認証の有効化',
            self::TwoFactorDisabled => '2要素認証の無効化',
            self::EmailChanged => 'メールアドレス変更',
            self::AccountDeleted => 'アカウント削除',
            self::SocialAccountLinked => 'ソーシャルアカウント連携',
            self::OwnershipTransferred => '組織オーナー移譲',
            self::ApiKeyIssued => 'API キー発行',
            self::ApiKeyRevoked => 'API キー失効',
            self::AdminMfaReset => '管理者 MFA リセット',
            self::OrgMemberTwoFactorReset => '組織管理者によるメンバー 2FA リセット',
        };
    }
}
