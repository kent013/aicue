<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * 登録 → verify notice ソフトゲートの「あとで認証する」継続導線。
 *
 * 生 URL を session に持たず organization_id のみ保持し、参照時に route を再構築 +
 * membership 確認する (URL 直保持はルート変更・値汚染に脆い)。所属確認 (relation 経由
 * fetch、IDOR 防御規約) を通らない値は null = 導線を出さない。
 * 寿命: remember (登録時) → forget (verify 完了時)。
 *
 * AI-CUE の onboarding route は current-org スコープ (route parameter なし) のため、
 * 再構築するのは引数なしの `route('onboarding.checkout')`。session に保持した
 * organization_id は「その組織のメンバーであること」の確認にのみ使う。
 */
final class EmailVerificationContinuation
{
    private const string SESSION_KEY = 'verify_continue_organization_id';

    public static function remember(Session $session, int $organizationId): void
    {
        $session->put(self::SESSION_KEY, $organizationId);
    }

    /**
     * session の organization_id から checkout URL を再構築する。
     * 所属確認を通らない値・非 int・null user は null (= 導線を出さない)。
     */
    public static function resolveUrl(?User $user, Session $session): ?string
    {
        if ($user === null) {
            return null;
        }

        $organizationId = $session->get(self::SESSION_KEY);
        if (! is_int($organizationId)) {
            return null;
        }

        $organization = $user->organizations()->whereKey($organizationId)->first();
        if ($organization === null) {
            return null;
        }

        return route('onboarding.checkout');
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
