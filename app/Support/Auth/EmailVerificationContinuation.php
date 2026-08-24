<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * 登録 → verify notice → **認証完了後**に checkout へ着地させる継続導線。
 *
 * 生 URL を session に持たず organization_id のみ保持し、参照時に route を再構築 +
 * membership 確認する (URL 直保持はルート変更・値汚染に脆い)。所属確認 (relation 経由
 * fetch、IDOR 防御規約) を通らない値は null = 継続なし。
 * 寿命: remember (登録時) → forget (verify 完了時)。
 *
 * URL を実際に使うのは `VerifyEmailResponse` (認証完了後の着地) だけである。
 * verify notice 画面へは URL を渡さない — `onboarding.checkout` は ['auth','verified']
 * 配下にあり、未認証で遷移させると必ず差し戻されるため (bug-hunt F-2-01)。
 * 画面へは `hasContinuation()` の bool だけを渡す。
 *
 * onboarding route は**組織 URL 配下**にある (家系裁定 AG-037) ので、session に保持した
 * organization_id は「その組織のメンバーであること」の確認と、**URL の再構築**の
 * 両方に使う (所属確認を通った組織の識別名だけが URL に載る)。
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

        return route('onboarding.checkout', ['organization' => $organization->slug]);
    }

    /**
     * 継続導線が実在するか (URL を露出せず有無だけを返す)。
     * membership 確認の単一出典を保つため resolveUrl() へ委譲する。
     * 「どの画面へ進むか」という UI 語彙はここに持ち込まない (呼び出し側が写像する)。
     */
    public static function hasContinuation(?User $user, Session $session): bool
    {
        return self::resolveUrl($user, $session) !== null;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
