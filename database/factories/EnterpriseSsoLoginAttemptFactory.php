<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Models\EnterpriseSsoLoginAttempt;
use App\Models\OrganizationOidcConnection;
use App\Support\EnterpriseSso\AttemptFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;

/**
 * @extends Factory<EnterpriseSsoLoginAttempt>
 */
class EnterpriseSsoLoginAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_oidc_connection_id' => OrganizationOidcConnection::factory(),
            'state_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::State, AttemptFingerprint::newSecret()),
            'nonce_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::Nonce, AttemptFingerprint::newSecret()),
            'browser_binding_fingerprint' => AttemptFingerprint::of(
                FingerprintPurpose::BrowserBinding,
                AttemptFingerprint::newSecret(),
            ),
            'pkce_verifier_encrypted' => AttemptFingerprint::newSecret(),
            'expires_at' => now()->addSeconds(Config::integer('enterprise-sso.login_attempt.ttl_seconds')),
        ];
    }

    /** 期限切れの試行 (掃除・拒否の検査用)。 */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
