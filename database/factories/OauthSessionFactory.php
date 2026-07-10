<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OAuth\OAuthClientKind;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Webmozart\Assert\Assert;

/**
 * OAuth セッション (CLI ログイン) の Factory。
 *
 * 所有権キー (user_id / organization_id / client_id) は $fillable 外のため、
 * Factory は Eloquent 経由ではなく内部で forceFill 相当の挙動 (factory は guarded を
 * 無視する) で埋める。既定は client_kind='cli' の未失効セッション。
 *
 * @extends Factory<OauthSession>
 */
class OauthSessionFactory extends Factory
{
    protected $model = OauthSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'client_id' => function (): string {
                $id = Client::factory()->create()->getKey();
                Assert::stringNotEmpty($id);

                return $id;
            },
            'client_kind' => OAuthClientKind::Cli->value,
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }

    public function cli(): static
    {
        return $this->state(fn (): array => ['client_kind' => OAuthClientKind::Cli->value]);
    }

    public function mcp(): static
    {
        return $this->state(fn (): array => ['client_kind' => OAuthClientKind::Mcp->value]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
