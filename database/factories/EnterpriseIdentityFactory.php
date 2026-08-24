<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EnterpriseIdentity;
use App\Models\OrganizationOidcConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnterpriseIdentity>
 */
class EnterpriseIdentityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_oidc_connection_id' => OrganizationOidcConnection::factory(),
            'user_id' => User::factory(),
            'subject' => 'sub-'.fake()->unique()->bothify('????????########'),
            'claimed_email_encrypted' => fake()->safeEmail(),
            'last_login_at' => null,
        ];
    }
}
