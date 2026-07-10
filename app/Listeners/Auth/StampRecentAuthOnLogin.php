<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Security\RecentAuthState;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * fresh credential login (web guard・非 recaller) を recent-auth 成立として stamp する。
 *
 * 目的: ログイン直後 (= たった今 password / TOTP / SSO で認証した) に機微操作へ進んだ際、
 * 「もう 1 回再認証」を要求される二重壁を消す。
 *
 * stamp する条件 (AND、fail-closed = 満たさない Login は skip):
 *   1. $event->guard === 'web' — recent-auth ゲートは web セッション専用。別 guard は除外。
 *   2. web guard の viaRemember() === false — remember-me cookie による自動ログイン復元
 *      (SessionGuard::userFromRecaller → fireLoginEvent($user, true)) を fresh 認証扱いしない。
 *      recaller 経路は発火前に viaRemember=true をセットするため、この条件で確実に除外される。
 *      (Login::$remember は明示 login でも true になり得るため判別子に使えない。)
 *
 * ⚠ 重要: 本 listener は「web guard の Login が全て credential-presentation である」前提に立つ。
 *   現行コードの web guard login は (1) Fortify password (2) Fortify TOTP (3) SSO
 *   Auth::login() の 3 種のみ。**将来 web guard に loginUsingId / impersonation /
 *   magic-link 等の非 credential login を追加する場合は、本 listener がそれらも fresh 扱いして
 *   しまうため必ず見直すこと**。
 */
final class StampRecentAuthOnLogin
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
        private readonly AuthFactory $auth,
    ) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $guard = $this->auth->guard('web');
        // web guard は SessionGuard 確定 (config/auth.php)。防御的に instanceof で narrowing。
        if (! $guard instanceof SessionGuard) {
            return; // fail-closed: viaRemember を判定できないなら stamp しない
        }

        if ($guard->viaRemember()) {
            return; // 自動ログイン復元 = fresh 認証ではない
        }

        $this->recentAuthState->confirm(method: 'login');
    }
}
