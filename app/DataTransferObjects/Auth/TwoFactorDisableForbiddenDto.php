<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * 2FA 必須組織に所属するユーザーの self-disable を拒否した XHR (expectsJson) に返す
 * 422 ボディ。組織により必須化された 2FA をユーザー自身が外せないことを伝える。
 * 復旧 (= やむを得ず外す) は組織管理者の resetTwoFactor 経由のみ。
 *
 * TwoFactorRequiredDto (未準拠ゲートの 409) とは別契約のため専用型とする (誤用防止)。
 */
final readonly class TwoFactorDisableForbiddenDto
{
    /**
     * 422 契約の判別子。クライアントは code 厳格一致で処理する
     * (recent_auth_required 409 / two_factor_required 409 との誤食防止)。
     */
    public const string CODE = 'two_factor_disable_forbidden';

    public function __construct(
        public string $message,
    ) {}
}
