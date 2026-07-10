<?php

declare(strict_types=1);

use Tests\Support\PromptYaml;

/*
 * LLM provider のハング対策として、全 prompt YAML が client_options.timeout (>0 の int) を
 * 宣言する不変条件を固定する。prism-prompt は YAML metadata の client_options を Prism
 * リクエストへ渡すため、これにより provider 無応答時に明示 timeout で打ち切られる。
 *
 * 走査は PromptYamlContractTest と同じ deny-by-default (再帰 + 0 件 fail-fast)。
 */
test('全 prompt YAML が client_options.timeout (>0) を宣言する', function (): void {
    $files = PromptYaml::paths();

    expect($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($file, $parseErrors);
        if ($parsed === null) {
            array_push($violations, ...$parseErrors);

            continue;
        }
        if (! array_key_exists('client_options', $parsed) || ! is_array($parsed['client_options'])) {
            $violations[] = "{$file}: client_options (map) がありません";

            continue;
        }
        $timeout = $parsed['client_options']['timeout'] ?? null;
        if (! is_int($timeout) || $timeout <= 0) {
            $violations[] = "{$file}: client_options.timeout は正の int で宣言してください";
        }
    }

    expect($violations)->toBe([],
        'client_options.timeout invariant に違反があります (provider 無応答時に打ち切れない)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
