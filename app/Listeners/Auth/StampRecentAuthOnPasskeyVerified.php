<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\User;
use App\Security\RecentAuthState;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * passkey 検証成立を recent-auth の satisfier として stamp する。
 *
 * ⚠ PasskeyVerified は VerifyPasskey::__invoke() の**中**で dispatch されるため、
 * **login 経路と confirm 経路の両方**で発火する。経路ごとの最終 session state:
 *
 *   | 経路                  | 発火順                        | 最終 recent_auth_method |
 *   |-----------------------|-------------------------------|-------------------------|
 *   | passkey login         | PasskeyVerified → Login        | 'login' (後勝ち)        |
 *   | passkey confirm       | PasskeyVerified のみ           | 'passkey'               |
 *   | passkey 登録 / 削除   | PasskeyRegistered / Deleted    | 未設定 (clear 済み)     |
 *
 * login 経路では StampRecentAuthOnLogin が後勝ちで 'login' を書く。最終状態は決定的だが、
 * 順序に依存するため RecentAuthMethodStampingTest が経路別に固定する。
 *
 * ⚠ **本人性バインドが必須**。VerifyPasskey は
 * `Passkeys::allowsLogin()` の判定 **より前** に PasskeyVerified を dispatch する。
 * 素朴に stamp すると、TOTP 有効ユーザーの passkey login が
 * PasskeyLoginPolicy に deny された場合でも、**guest session に鮮度が残る**。
 * そこで「検証された passkey が **現在ログイン中ユーザー** のものである場合のみ stamp する」
 * (SocialAuthController::completeStepUp の本人性バインドと同じ作法)。
 *   - confirm 経路: 認証済みユーザー本人の passkey → stamp される (期待どおり)
 *   - login 経路:   その時点では guest → stamp されない。
 *                   ログイン成立後の鮮度は StampRecentAuthOnLogin が担うため欠落しない
 *   - deny 経路:    guest のまま終わるので鮮度は残らない (fail-closed)
 */
final class StampRecentAuthOnPasskeyVerified
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
    ) {}

    public function handle(PasskeyVerified $event): void
    {
        $current = request()->user();
        if (! $current instanceof User) {
            return;   // guest (login 経路 / deny 経路) では stamp しない
        }

        // 他人の credential での round-trip 完了を成立させない。
        // 型を確定できない値は「一致しない」に倒す (fail-closed)。
        $passkeyUserId = $event->passkey->getAttribute('user_id');
        $currentKey = $current->getKey();
        if (! is_scalar($passkeyUserId) || ! is_scalar($currentKey)) {
            return;
        }
        if ((string) $passkeyUserId !== (string) $currentKey) {
            return;
        }

        $this->recentAuthState->confirm(method: 'passkey');
    }
}
