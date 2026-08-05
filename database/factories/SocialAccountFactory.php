<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
final class SocialAccountFactory extends Factory
{
    /** @var class-string<SocialAccount> */
    protected $model = SocialAccount::class;

    /**
     * SSO 連携 (provider + provider_user_id で一意)。
     *
     * 既定 provider は `google` (config('template.social_providers') に capability 宣言があり、
     * recent-auth の step-up satisfier として数えられる provider)。satisfier に数えられない
     * provider を試す場合は state で上書きすること。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'provider_user_id' => (string) fake()->unique()->numerify('##################'),
        ];
    }

    /** provider を明示する (capability 宣言の有無で挙動が変わるテスト用) */
    public function provider(string $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }
}
