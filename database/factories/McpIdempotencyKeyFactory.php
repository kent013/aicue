<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Mcp\ToolName;
use App\Models\McpIdempotencyKey;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * MCP 冪等性レコードの Factory。
 *
 * ownership キー (organization_id / user_id) は $fillable 外だが、Factory は
 * guarded を無視して埋められる。既定は有効期限内 (24h) の read 系レコード。
 *
 * @extends Factory<McpIdempotencyKey>
 */
class McpIdempotencyKeyFactory extends Factory
{
    protected $model = McpIdempotencyKey::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'tool_name' => ToolName::Whoami->value,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', '{}'),
            'response_body' => ['ok' => true],
            'created_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHours(24),
        ];
    }

    public function forOrganizationAndUser(Organization $organization, User $user): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);
    }
}
