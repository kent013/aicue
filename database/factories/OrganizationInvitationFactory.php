<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
{
    /**
     * DB には sha256 の token_hash のみ保存する (平文 token 非保存)。
     * 平文 token が必要なテストは createWithPlainToken() を使う (tuple で平文も受け取る)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'invited_by_user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Member->value,
            'token_hash' => OrganizationInvitation::hashToken(OrganizationInvitation::generateToken()),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * テスト用 helper: invitation を生成し、平文 token も合わせて返す (URL 生成用)。
     *
     * `$overrides['token_hash']` 指定は平文↔hash の不変条件を壊すため fail-fast で拒否する。
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: OrganizationInvitation, 1: string}
     */
    public function createWithPlainToken(array $overrides = []): array
    {
        if (array_key_exists('token_hash', $overrides)) {
            throw new InvalidArgumentException(
                'createWithPlainToken() does not accept token_hash override (would break plain↔hash invariant)',
            );
        }

        $plainToken = OrganizationInvitation::generateToken();

        $model = $this->state(fn (): array => [
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
        ])->create($overrides);

        Assert::isInstanceOf($model, OrganizationInvitation::class);

        return [$model, $plainToken];
    }

    /** 指定組織への招待として作る */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'accepted_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }

    public function asAdmin(): static
    {
        return $this->state(fn (): array => [
            'role' => OrganizationRole::Admin->value,
        ]);
    }
}
