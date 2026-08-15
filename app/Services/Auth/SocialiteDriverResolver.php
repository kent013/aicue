<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;

/**
 * Socialite driver の唯一の解決点 (SSO の正規経路)。
 *
 * ★本クラスが `ExternalSeamInventory::socialLoginFunnel()` の名指し先である。
 *   他クラスに `Socialite::driver()` を書くと `ExternalSeamInventoryTest` が赤くなる。
 * ★非本番 (testing / bughunt.local) では `FakeSocialiteDriverResolver` へ container bind
 *   される (`ExternalFakeDeclaration`)。**差し替え点なので `final` にしない**。
 * ★責務は driver の解決 1 つだけ。intent 分岐・user 変換・state 照合の無効化などを足さない
 *   (太らせるとサブクラス差し替えが崩れる。state 照合を殺す呼び出しの封鎖は
 *   `ThrottleExemptionPremiseTest` が本ファイルも走査して守る)。
 */
class SocialiteDriverResolver
{
    public function driver(string $provider): Provider
    {
        return Socialite::driver($provider);
    }
}
