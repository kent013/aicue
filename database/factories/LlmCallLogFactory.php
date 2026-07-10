<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LlmCallLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * LLM 呼び出しコスト記録の factory (成功系の 1 行が既定)。
 *
 * @extends Factory<LlmCallLog>
 */
class LlmCallLogFactory extends Factory
{
    protected $model = LlmCallLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inputTokens = $this->faker->numberBetween(100, 5000);
        $outputTokens = $this->faker->numberBetween(50, 2000);
        $inputCost = $inputTokens * 3.00 / 1_000_000;
        $outputCost = $outputTokens * 15.00 / 1_000_000;
        $totalCost = $inputCost + $outputCost;

        return [
            'execution_id' => $this->faker->uuid(),
            'organization_id' => Organization::factory(),
            'user_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'prompt_class' => 'App\\Prompts\\ExampleSummaryPrompt',
            'prompt_template' => 'example-summary',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5-20250929',
            'finish_reason' => 'stop',
            'step_count' => 1,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_write_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'thought_tokens' => null,
            'input_cost_usd' => number_format($inputCost, 6, '.', ''),
            'output_cost_usd' => number_format($outputCost, 6, '.', ''),
            'total_cost_usd' => number_format($totalCost, 6, '.', ''),
            'pricing_snapshot' => [
                'input' => 3.00, 'output' => 15.00,
                'cache_write' => 3.75, 'cache_read' => 0.30,
                'unit' => 'per_1m_tokens', 'currency' => 'USD',
                'source' => 'config',
            ],
            'fx_snapshot' => null,
            'total_cost_jpy' => null,
            'duration_ms' => $this->faker->numberBetween(1000, 30000),
            'request_id' => $this->faker->uuid(),
            'metadata_missing' => false,
            'failure_reason' => null,
            'created_at' => now(),
        ];
    }

    public function withFxSnapshot(float $rate = 154.32): self
    {
        return $this->state(function (array $attributes) use ($rate): array {
            $totalCostUsd = is_numeric($attributes['total_cost_usd']) ? (float) $attributes['total_cost_usd'] : 0.0;

            return [
                'fx_snapshot' => [
                    'rate' => $rate,
                    'pair' => 'USDJPY',
                    'source' => 'frankfurter',
                    'fetched_at' => now()->toIso8601String(),
                ],
                'total_cost_jpy' => number_format($totalCostUsd * $rate, 2, '.', ''),
            ];
        });
    }

    public function failed(string $reason = 'RuntimeException: API timeout'): self
    {
        return $this->state([
            'finish_reason' => 'failed',
            'step_count' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'input_cost_usd' => null,
            'output_cost_usd' => null,
            'total_cost_usd' => null,
            'pricing_snapshot' => null,
            'request_id' => null,
            'failure_reason' => $reason,
        ]);
    }

    public function metadataMissing(): self
    {
        return $this->state([
            'organization_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'metadata_missing' => true,
        ]);
    }
}
