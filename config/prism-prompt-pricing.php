<?php

declare(strict_types=1);

/**
 * LLM model pricing (per 1M tokens, USD)。
 *
 * vendor (kent013/laravel-prism-prompt) の defaults を publish したもの。価格改定時は
 * この表を更新し、`pricing_source` を新しい出典日付へ更新する。
 *
 * `pricing_source` は各 PricingSnapshot に埋め込まれ、価格表更新後も過去のコスト記録が
 * 監査可能に保たれる。監査証跡なので env で上書きせず出典日付で固定する
 * (PrismPromptPricingDriftTest が固定値と vendor default との構造整合を検査する)。
 */
return [
    'pricing_source' => 'anthropic_2026-04-10',

    'unknown_model_behavior' => env('PRISM_PROMPT_UNKNOWN_MODEL_BEHAVIOR', 'zero'),

    'models' => [
        'anthropic' => [
            'claude-opus-4-6' => [
                'input' => 15.00,
                'output' => 75.00,
                'cache_write' => 18.75,
                'cache_read' => 1.50,
            ],
            'claude-sonnet-4-6' => [
                'input' => 3.00,
                'output' => 15.00,
                'cache_write' => 3.75,
                'cache_read' => 0.30,
            ],
            'claude-sonnet-4-5-20250929' => [
                'input' => 3.00,
                'output' => 15.00,
                'cache_write' => 3.75,
                'cache_read' => 0.30,
            ],
            'claude-haiku-4-5-20251001' => [
                'input' => 1.00,
                'output' => 5.00,
                'cache_write' => 1.25,
                'cache_read' => 0.10,
            ],
        ],
    ],
];
