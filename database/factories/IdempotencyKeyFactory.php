<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    /**
     * api_key 未指定なら ApiKeyFactory に連鎖する (親 Factory 連鎖の規約)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'api_key_id' => ApiKey::factory(),
            'route_name' => 'api.v1.projects.items.store',
            'key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', Str::random(32)),
            'response_status' => 201,
            'response_body' => ['data' => ['id' => 1]],
            'expires_at' => Carbon::now()->addDay(),
        ];
    }

    /** 指定 API キーの保存行として作る */
    public function forApiKey(ApiKey $apiKey): static
    {
        return $this->state(fn () => ['api_key_id' => $apiKey->id]);
    }

    /** TTL 超過 (未使用扱い) として作る */
    public function expired(?Carbon $expiresAt = null): static
    {
        return $this->state(fn () => ['expires_at' => $expiresAt ?? Carbon::now()->subMinute()]);
    }
}
