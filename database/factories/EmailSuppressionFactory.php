<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailSuppressionReason;
use App\Models\EmailSuppression;
use App\Support\EmailHash;
use App\Support\EmailNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailSuppression>
 */
class EmailSuppressionFactory extends Factory
{
    protected $model = EmailSuppression::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = EmailNormalizer::normalize(fake()->unique()->safeEmail());

        return [
            'email' => $email,
            'email_hash' => EmailHash::compute($email),
            'reason' => EmailSuppressionReason::Bounce,
            'provider_message_id' => fake()->uuid(),
            'suppressed_at' => CarbonImmutable::now(),
        ];
    }

    public function bounce(): self
    {
        return $this->state(fn (): array => ['reason' => EmailSuppressionReason::Bounce]);
    }

    public function complaint(): self
    {
        return $this->state(fn (): array => ['reason' => EmailSuppressionReason::Complaint]);
    }

    /**
     * 指定 email で抑止行を作る (normalize を必ず通す)。
     */
    public function forEmail(string $email): self
    {
        $normalized = EmailNormalizer::normalize($email);

        return $this->state(fn (): array => [
            'email' => $normalized,
            'email_hash' => EmailHash::compute($normalized),
        ]);
    }
}
