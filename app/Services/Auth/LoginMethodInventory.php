<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DataTransferObjects\Auth\LoginMethodRemoval;
use App\DataTransferObjects\Auth\LoginMethodSet;
use App\Enums\Auth\LoginMethodRemovalKind;
use App\Models\User;

/**
 * 「ログイン画面から本人がアカウントに入れる手段」の集合。
 *
 * 基準は「データが存在する」ではなく「**使える**」。
 * feature を落とした後も使えない手段を数えると guard が形骸化するため。
 *
 * 唯一の公開 API は remainingAfter()。「現在の手段」も
 * LoginMethodRemoval::none() で表現する (API を 2 本にすると片方だけ使う実装が生える)。
 *
 * ⚠ 呼び出し側の契約: 除去の可否判定に使う場合、本メソッドは
 * **対象 User 行を lockForUpdate() で取得した同一トランザクション内**で呼ぶこと
 * (EnsureLoginMethodRemains が唯一の呼び出し点)。ロック外の評価は TOCTOU で破れる。
 *
 * ⚠ canSatisfy (ConfirmRecentAuthController) とは**別概念**。あちらは
 * 「step-up 再認証を成立させられるか」で ProviderCapability による絞り込みが入る。
 * こちらは「ログインできるか」なので capability では絞らない。統合しないこと。
 *
 * ⚠ **将来 password 削除 / SSO 連携解除 route を追加するときも、必ず
 * EnsureLoginMethodRemains を通すこと (= 単一の直列化点)**。
 * passkey だけ守って別経路を作ると TOCTOU が戻る。
 * tests/Architecture/LoginMethodRemovalRouteTest が deny-by-default で強制する。
 */
final class LoginMethodInventory
{
    public function __construct(
        private readonly PasskeyLoginPolicy $passkeyLoginPolicy,
    ) {}

    /** $removal が成功した後に残るログイン手段の集合 */
    public function remainingAfter(User $user, LoginMethodRemoval $removal): LoginMethodSet
    {
        /** @var list<string> $methods */
        $methods = [];

        // --- password ---
        if ($removal->kind !== LoginMethodRemovalKind::Password && $user->hasPassword()) {
            $methods[] = 'password';
        }

        // --- social ---
        // capability では絞らない (identity_only でもログインはできる)。
        // ただし config に無い provider は SocialAuthController::ensureProviderEnabled() が
        // 404 にするため、連携行があってもログインには使えない → 数えない。
        $enabled = array_keys(config()->array('template.social_providers'));
        foreach ($user->socialAccounts()->pluck('provider') as $provider) {
            if (! is_string($provider) || ! in_array($provider, $enabled, true)) {
                continue;   // fail-closed
            }
            if ($removal->kind === LoginMethodRemovalKind::Social && $removal->provider === $provider) {
                continue;   // 今から外す
            }
            $methods[] = 'social:'.$provider;
        }

        // --- passkey ---
        if ($this->passkeyLoginPolicy->allowsPasskeyLogin($user) && $this->hasRemainingPasskey($user, $removal)) {
            $methods[] = 'passkey';
        }

        return new LoginMethodSet(array_values(array_unique($methods)));
    }

    private function hasRemainingPasskey(User $user, LoginMethodRemoval $removal): bool
    {
        if ($removal->kind === LoginMethodRemovalKind::AllPasskeys) {
            return false;
        }

        $query = $user->passkeys();

        if ($removal->kind === LoginMethodRemovalKind::Passkey && $removal->passkey !== null) {
            // 削除対象自身を残存手段として数えない (投影)
            $query->whereKeyNot($removal->passkey->getKey());
        }

        return $query->exists();
    }
}
