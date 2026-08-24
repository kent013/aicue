<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Models\EmailPromotion;
use App\Models\User;
use App\Support\EnterpriseSso\AttemptFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;

/**
 * @extends Factory<EmailPromotion>
 */
class EmailPromotionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_fingerprint' => AttemptFingerprint::of(
                FingerprintPurpose::EmailPromotionToken,
                AttemptFingerprint::newSecret(),
            ),
            'email_encrypted' => fake()->unique()->safeEmail(),
            'expires_at' => now()->addSeconds(Config::integer('enterprise-sso.email_promotion.ttl_seconds')),
        ];
    }

    /** 期限切れの昇格 (掃除・拒否の検査用)。 */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
