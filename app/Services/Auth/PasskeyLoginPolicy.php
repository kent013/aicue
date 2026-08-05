<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\TwoFactorStatus;
use App\Models\User;
use Laravel\Fortify\Features;

/**
 * その User が passkey で **ログイン** することを許されるか (credential の有無とは無関係)。
 *
 * 判定を 1 箇所に集約する理由: 同じ条件が
 *   (1) Passkeys::authorizeLoginUsing() の closure (vendor のログイン直前ゲート)
 *   (2) LoginMethodInventory の passkey 判定
 *   (3) Settings/Security の passkeyLoginAvailable prop
 * で必要になり、別々に書けば必ず乖離するため。
 *
 * **TOTP confirmed のユーザーを拒否する理由**:
 * vendor の PasskeyLoginController::store() は $guard->login() を直接呼び、
 * Fortify の two-factor challenge を通らない。2026-08-04 裁定 A の再検討条件が
 * 「パスキーが 2FA 準拠判定に算入される時」である以上、現時点で passkey は 2FA 相当ではなく、
 * passkey login で TOTP を置き換えるのは assurance の後退にあたる。
 * これは c2c 未裁定の論点 (AG-新) であり、裁定が出たら **このクラス 1 箇所**を
 * 書き換えれば上記経路が同時に反転する。
 *
 * feature flag に連動するため、`config/fortify.php` から Features::passkeys() を外すと
 * 「passkey はログイン手段として数えない」も同時に成立する (= キルスイッチ)。
 */
final class PasskeyLoginPolicy
{
    public function allowsPasskeyLogin(User $user): bool
    {
        if (! Features::enabled(Features::passkeys())) {
            return false;   // feature off なら route ごと存在しない
        }

        return $user->twoFactorStatus() !== TwoFactorStatus::Enabled;
    }
}
