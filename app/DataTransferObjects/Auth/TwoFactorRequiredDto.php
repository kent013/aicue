<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * 組織の 2FA 必須ゲートに引っかかった XHR (expectsJson) に返す 409 ボディ。
 * RecentAuthRequiredDto と同形だが、recent-auth 要求と 2FA 必須要求は別契約のため
 * 専用型とする (誤用防止)。クライアントは `redirect` (= 2FA 設定ページ) へ遷移する。
 */
final readonly class TwoFactorRequiredDto
{
    /**
     * 409 契約の判別子 (RecentAuthRequiredDto::CODE と同じ誤食防止の仕組み)。
     * クライアント側は status だけでなく code 厳格一致で自分宛ての応答のみ処理する。
     */
    public const string CODE = 'two_factor_required';

    public function __construct(
        public string $message,
        public string $redirect,
    ) {}
}
