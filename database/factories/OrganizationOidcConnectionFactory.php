<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationOidcConnection>
 */
class OrganizationOidcConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $loginSlug = 'idp-'.fake()->unique()->bothify('??????##');

        return [
            'organization_id' => Organization::factory(),
            'login_slug' => $loginSlug,
            'display_name' => fake()->company(),
            'issuer' => 'https://'.$loginSlug.'.idp.test',
            'client_id' => 'client-'.fake()->bothify('##########'),
            'client_secret_encrypted' => ConnectionSecret::fromPlaintext('secret-'.fake()->bothify('????????????')),
            'status' => OidcConnectionStatus::Draft,
            'verified_at' => null,
            'credentials_revision' => 1,
        ];
    }

    /** 確認済み (まだログインには使えない)。 */
    public function verified(): self
    {
        return $this->state(fn (): array => [
            'status' => OidcConnectionStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    /** ログインに使える。 */
    public function active(): self
    {
        return $this->state(fn (): array => [
            'status' => OidcConnectionStatus::Active,
            'verified_at' => now(),
        ]);
    }

    /** 運営が止めた。 */
    public function disabled(): self
    {
        return $this->state(fn (): array => [
            'status' => OidcConnectionStatus::Disabled,
            'verified_at' => now(),
        ]);
    }
}
