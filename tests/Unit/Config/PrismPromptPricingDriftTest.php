<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

/*
 * config/prism-prompt-pricing.php の drift テスト。
 *
 * 意図しない構造変化 (env override の混入・モデル欠落・価格キー欠落・挙動フラグの drift) が
 * 入るとコスト記録が静かに壊れる (unknown model が 0 円で記録される等) ため、CI で固定する。
 * 加えて vendor (kent013/laravel-prism-prompt) の default 価格表と構造比較し、
 * パッケージ更新でモデル / 価格キーが増減した際に publish 済み config の追随漏れを検知する。
 */

const REQUIRED_ANTHROPIC_MODELS = [
    'claude-opus-4-6',
    'claude-sonnet-4-6',
    'claude-sonnet-4-5-20250929',
    'claude-haiku-4-5-20251001',
];

const REQUIRED_PRICE_KEYS = ['input', 'output', 'cache_write', 'cache_read'];

it('unknown_model_behavior は zero に固定されている', function (): void {
    expect(Config::get('prism-prompt-pricing.unknown_model_behavior'))->toBe('zero');
});

it('pricing_source は出典日付の固定文字列 (env で上書き不可)', function (): void {
    expect(Config::get('prism-prompt-pricing.pricing_source'))->toBe('anthropic_2026-04-10');
});

it('必須 anthropic モデルが全価格キー付きで登録されている', function (): void {
    /** @var array<string, array<string, float>> $models */
    $models = Config::get('prism-prompt-pricing.models.anthropic');
    expect($models)->toHaveKeys(REQUIRED_ANTHROPIC_MODELS);

    foreach (REQUIRED_ANTHROPIC_MODELS as $model) {
        expect($models[$model])->toHaveKeys(REQUIRED_PRICE_KEYS);
        foreach (REQUIRED_PRICE_KEYS as $key) {
            expect($models[$model][$key])->toBeFloat()->toBeGreaterThan(0);
        }
    }
});

it('publish 済み config は vendor default と構造が一致する (パッケージ更新の追随漏れ検知)', function (): void {
    $vendorPath = base_path('vendor/kent013/laravel-prism-prompt/config/prism-prompt-pricing.php');
    expect(file_exists($vendorPath))->toBeTrue('vendor の default 価格表が見つかりません');

    /** @var array{models: array<string, array<string, array<string, float>>>} $vendor */
    $vendor = require $vendorPath;
    /** @var array<string, array<string, array<string, float>>> $appModels */
    $appModels = Config::get('prism-prompt-pricing.models');

    // provider セット・provider ごとのモデルセット・モデルごとの価格キーセットが一致すること。
    // (価格値そのものは app 側が出典日付付きで管理するため、構造のみ比較する)
    expect(array_keys($appModels))->toBe(array_keys($vendor['models']));

    foreach ($vendor['models'] as $provider => $vendorModels) {
        expect(array_keys($appModels[$provider]))->toBe(
            array_keys($vendorModels),
            "provider '{$provider}' のモデルセットが vendor default と一致しません",
        );
        foreach ($vendorModels as $model => $prices) {
            expect(array_keys($appModels[$provider][$model]))->toBe(
                array_keys($prices),
                "'{$provider}/{$model}' の価格キーが vendor default と一致しません",
            );
        }
    }
});
