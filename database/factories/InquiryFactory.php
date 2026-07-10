<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Inquiry\InquirySource;
use App\Enums\Inquiry\InquiryStatus;
use App\Enums\Inquiry\InquiryType;
use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inquiry> */
class InquiryFactory extends Factory
{
    protected $model = Inquiry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => InquiryType::General->value,
            'status' => InquiryStatus::Open->value,
            'source' => InquirySource::Landing->value,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'company_name' => fake()->company(),
            'message' => fake()->paragraph(),
            'terms_accepted_at' => now(),
            'consent_version' => 'draft-1',
        ];
    }

    public function spam(): static
    {
        return $this->state(fn (): array => [
            'status' => InquiryStatus::Spam->value,
        ]);
    }

    public function closed(int $closedDaysAgo = 0): static
    {
        return $this->state(fn (): array => [
            'status' => InquiryStatus::Closed->value,
            'closed_at' => now()->subDays($closedDaysAgo),
        ]);
    }

    public function staleOpen(int $createdDaysAgo = 40): static
    {
        return $this->state(fn (): array => [
            'status' => InquiryStatus::Open->value,
            'created_at' => now()->subDays($createdDaysAgo),
        ]);
    }
}
