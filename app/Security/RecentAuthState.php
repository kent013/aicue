<?php

declare(strict_types=1);

namespace App\Security;

/**
 * recent-auth (step-up) の session 状態の **唯一の writer**。
 *
 * satisfier (password 再入力 / 再SSO / fresh login) が成立した時に呼ばれ、
 * 鮮度 timestamp と method/provider を dedicated key に記録する。Fortify の
 * `auth.password_confirmed_at` には書かない (意味汚染・権限漏れ回避。横断標準は
 * `recent_auth_at` を正本とする)。
 *
 * 成立時は `session()->migrate(true)` で session ID を rotate する (OWASP: 権限
 * 上昇時の session ID 更新)。`regenerate()` と異なり CSRF token は再生成しないため、
 * XHR モーダルや別タブの進行中フォームを壊さない。
 *
 * session keys:
 * - `recent_auth_at`        : unix timestamp (int)。RecentAuthWindow が鮮度判定に使う。
 * - `recent_auth_method`    : 'password' | 'sso' | 'login' (将来の policy 計算材料)。
 * - `recent_auth_provider`  : SSO の場合の provider slug (なければ null)。
 */
final class RecentAuthState
{
    public function confirm(string $method, ?string $provider = null, ?int $verifiedAt = null): void
    {
        $at = $verifiedAt ?? time();

        session()->put('recent_auth_at', $at);
        session()->put('recent_auth_method', $method);
        session()->put('recent_auth_provider', $provider);

        // 権限上昇に伴う session fixation 対策。CSRF token は維持 (migrate, not regenerate)。
        session()->migrate(true);
    }

    /**
     * 認証要素変更 (password/email/2FA/social link·unlink 等) 後に鮮度を失効させる。
     */
    public function clear(): void
    {
        session()->forget(['recent_auth_at', 'recent_auth_method', 'recent_auth_provider']);
    }
}
