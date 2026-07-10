<?php

declare(strict_types=1);

use Tests\Support\PromptYaml;

/*
 * 全 prompt YAML の system_prompt 冒頭に DefensiveInstructions preamble
 * (`DefensiveInstructions::forUserInputJa()` / `forUserInput()`) が埋め込まれていることを
 * 保証する arch test。新規 prompt YAML を追加した際に preamble を入れ忘れると即 fail する。
 *
 * Blade は変数解決が必要なため raw レンダリングは行わず、source 文字列上の Blade
 * ディレクティブ存在を確認する (render 時に UserInput タグ境界の防御文が展開される)。
 * 設計上「冒頭」に置くことを要求しているため、system_prompt の最初の非空白行 (〜2 行目)
 * に出現することも検証する (末尾に置くと後続指示より優先度が下がる)。
 */
test('全 prompt YAML の system_prompt が DefensiveInstructions preamble を冒頭に持つ', function (): void {
    $files = PromptYaml::paths();
    expect($files)->not->toBeEmpty();

    $pattern = '/DefensiveInstructions::forUserInput(?:Ja)?\s*\(/';
    $violations = [];

    foreach ($files as $file) {
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($file, $parseErrors);
        if ($parsed === null) {
            array_push($violations, ...$parseErrors);

            continue;
        }

        $systemPrompt = $parsed['system_prompt'] ?? null;
        if (! is_string($systemPrompt) || trim($systemPrompt) === '') {
            $violations[] = "{$file}: system_prompt が非空 string でない";

            continue;
        }

        if (preg_match($pattern, $systemPrompt) !== 1) {
            $violations[] = "{$file}: DefensiveInstructions::forUserInputJa() の Blade ディレクティブがありません";

            continue;
        }

        // 冒頭配置の検証: 最初の非空白行から 2 行以内に preamble があること。
        $lines = preg_split('/\r?\n/', $systemPrompt) ?: [];
        $firstNonEmpty = null;
        foreach ($lines as $i => $line) {
            if (trim($line) !== '') {
                $firstNonEmpty = $i;
                break;
            }
        }
        if ($firstNonEmpty === null) {
            $violations[] = "{$file}: system_prompt が空";

            continue;
        }
        $head = implode("\n", array_slice($lines, $firstNonEmpty, 2));
        if (preg_match($pattern, $head) !== 1) {
            $violations[] = "{$file}: DefensiveInstructions ディレクティブを system_prompt 冒頭"
                .' (最初の非空白行 〜 2 行目) に置いてください';
        }
    }

    expect($violations)->toBe([],
        'DefensiveInstructions preamble invariant に違反があります。'.PHP_EOL.implode(PHP_EOL, $violations));
});
