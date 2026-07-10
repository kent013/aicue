<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApiKeyAbility;
use App\Models\ApiKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * organization 未指定なら OrganizationFactory に連鎖する (親 Factory 連鎖の規約)。
     * key_prefix / key_hash は generatePlainKey() で実形式の値を生成する
     * (平文が必要なテストでは withPlainToken() か issueApiKey ヘルパ (tests/Pest.php) を使うこと)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generated = ApiKey::generatePlainKey();

        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(2, true),
            'key_prefix' => $generated['prefix'],
            'key_hash' => ApiKey::hashSecret($generated['secret']),
            'abilities' => [ApiKeyAbility::Read->value, ApiKeyAbility::Write->value],
            'expires_at' => null,
        ];
    }

    /**
     * 平文 token 付きで factory を作る (Guard の認証テスト用)。
     *
     * @return array{static, string} [factory, plainToken]
     */
    public static function withPlainToken(): array
    {
        $generated = ApiKey::generatePlainKey();

        $factory = static::new()->state(fn () => [
            'key_prefix' => $generated['prefix'],
            'key_hash' => ApiKey::hashSecret($generated['secret']),
        ]);

        return [$factory, $generated['plain']];
    }

    /** 指定組織のキーとして作る */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }

    /** 失効済みとして作る */
    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    /** 期限切れとして作る */
    public function expired(?Carbon $expiresAt = null): static
    {
        return $this->state(fn () => ['expires_at' => $expiresAt ?? Carbon::now()->subMinute()]);
    }
}
