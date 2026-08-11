<?php

declare(strict_types=1);

namespace App\Services\Auth\Fakes;

use App\Services\Auth\SocialiteDriverResolver;
use Laravel\Socialite\Contracts\Provider;

/**
 * SSO (Socialite) driver 解決点の fake。
 *
 * bug-hunt / 自動テストレーンのブラウザが SSO ボタンから**実 IdP へ出ないようにする**ための
 * 差し替え先。配線条件は `FakeExternalsServiceProvider::registerSocialAuthFake()`
 * (`config('testing.fake_externals') === true` ∧ env ∈ {testing, bughunt.local})。
 */
final class FakeSocialiteDriverResolver extends SocialiteDriverResolver
{
    public function driver(string $provider): Provider
    {
        return new FakeSocialiteProvider($provider);
    }
}
