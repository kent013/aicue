<?php

declare(strict_types=1);

namespace App\Services\Auth\Fakes;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Two\User as SocialiteTwoUser;

/**
 * IdP へ出ない Socialite provider の fake。
 *
 * - `redirect()` は**自アプリの `social.callback` へ 302** する (IdP 風の中間画面は作らない。
 *   IdP の同意画面はアプリが所有する UX ではないため、作っても検証対象が増えない)。
 * - `user()` は provider 名から決定論的に導出した canned な identity を返す。
 *   決定論にするのは、`register` / `link` で作った連携へ次の `login` / `step-up` が
 *   同じ identity で戻れるようにするため。
 * - OAuth の `state` は持たない。`SocialAuthController` / `SocialAccountService` は
 *   `state` を一切参照しない (session に置くのは `social_auth_intent` だけ) ため、
 *   アプリ層の契約は 1 つも飛ばさない。
 * - `with()` は実装しない。controller の step-up 分岐は `method_exists($driver, 'with')` で
 *   守られており、未実装なら単に skip される。
 * - `$provider` は `SocialAuthController::ensureProviderEnabled()`
 *   (`array_key_exists($provider, config()->array('template.social_providers'))` で不一致は 404) を
 *   通過した後にしか到達しないため、**常に config で宣言済みのキー**である。
 *   ここで文字種を再検査しない (起こり得ない条件のための runtime throw を足さない)。
 */
final readonly class FakeSocialiteProvider implements Provider
{
    public function __construct(private string $provider) {}

    public function redirect(): RedirectResponse
    {
        // 自アプリ内で round-trip を閉じる (APP_URL の host。bughunt は 127.0.0.1:801x)
        return new RedirectResponse(route('social.callback', ['provider' => $this->provider]));
    }

    public function user(): SocialiteUserContract
    {
        return (new SocialiteTwoUser)
            ->setRaw([])
            ->map([
                'id' => 'fake-'.$this->provider.'-user',
                'nickname' => 'fake-'.$this->provider,
                'name' => 'SSO Fake User ('.$this->provider.')',
                'email' => 'fake-'.$this->provider.'-sso@example.com',
                'avatar' => null,
            ]);
    }
}
