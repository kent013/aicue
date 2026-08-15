<?php

declare(strict_types=1);

use Tests\Support\PromptYaml;

/*
 * prompt YAML の一括契約テスト (最後の防波堤)。
 *
 * `resources/prompts/` 配下を deny-by-default で再帰走査し、以下を CI で固定する:
 *  1. 走査ルート健全性 (0 件なら fail-fast。走査ルートの移動 / typo で黙って PASS しない)
 *  2. parse 可能性 + top-level が連想配列 (map)
 *  3. 必須キー present + 非空 string (name / system_prompt / prompt / provider / model)。
 *     テンプレートの prompt YAML は provider/model を自己完結で持つ規約
 *  4. name の全ファイル横断一意 (identity/observability hygiene)。name は load の registry 鍵では
 *     ないため、一意性は logging/metrics/debug の識別子衛生として課す
 *
 * client_options.timeout は PromptClientTimeoutInvariantTest、DefensiveInstructions preamble は
 * DefensiveInstructionsPresenceTest が担う (重複させず補完する)。
 */

test('prompt YAML が 1 件以上検出される (走査ルート健全性・fail-fast)', function (): void {
    expect(PromptYaml::paths())->not->toBeEmpty();
});

test('全 prompt YAML が parse 可能で top-level が連想配列 (map)', function (): void {
    $violations = [];
    foreach (PromptYaml::paths() as $path) {
        PromptYaml::parseOrFail($path, $violations);
    }
    expect($violations)->toBe([],
        'prompt YAML の parse / map 検査に違反があります。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('必須キーが present + 非空 string (name / system_prompt / prompt / provider / model)', function (): void {
    $requiredKeys = ['name', 'system_prompt', 'prompt', 'provider', 'model'];
    $violations = [];

    foreach (PromptYaml::paths() as $path) {
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($path, $parseErrors);
        if ($parsed === null) {
            array_push($violations, ...$parseErrors); // 個別実行でも parse 失敗を診断できるよう合流

            continue;
        }
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $parsed)) {
                $violations[] = "{$path}: 必須キー '{$key}' 欠落";

                continue;
            }
            $value = $parsed[$key];
            if (! is_string($value) || trim($value) === '') {
                $violations[] = "{$path}: '{$key}' が非空 string でない";
            }
        }
    }
    expect($violations)->toBe([],
        'prompt YAML の必須キー契約に違反があります。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('name は全 prompt YAML 横断で一意 (identity/observability hygiene)', function (): void {
    /** @var array<string, string> $seen name => path */
    $seen = [];
    $violations = [];
    foreach (PromptYaml::paths() as $path) {
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($path, $parseErrors);
        if ($parsed === null || ! isset($parsed['name']) || ! is_string($parsed['name'])) {
            continue;
        }
        $name = trim($parsed['name']); // 末尾空白差分の擬似重複を防ぐ
        if (isset($seen[$name])) {
            $violations[] = "name '{$name}' が重複: {$seen[$name]} / {$path}";
        } else {
            $seen[$name] = $path;
        }
    }
    expect($violations)->toBe([],
        'prompt YAML の name が重複しています (識別子衛生違反)。'.PHP_EOL.implode(PHP_EOL, $violations));
});

/*
 * Blade 構文契約 (裁定 AG-028 の窓口方式の土台)。
 *
 * ★ この契約は **PromptDefenseWindowGateTest の変数集合突き合わせの前提**である。
 *   あちらは `{{ $name }}` を正規表現で抽出しており、その抽出が成立するのは
 *   ここで書ける Blade 式を 2 形へ絞っているからである。
 *   **構文を増やすなら PromptDefenseWindowGateTest の抽出も同時に見直すこと。**
 *
 * 書けるのは 2 形だけ:
 *   (i)  単純変数展開            `{{ $name }}`
 *   (ii) 防御指示の静的呼び出し  `{{ \Kent013\PrismPrompt\Values\DefensiveInstructions::forUserInput(Ja)?() }}`
 * 禁止: `{!! !!}` (エスケープなし出力) / 上記 2 形以外の `{{ … }}` /
 *       `@if` 等のディレクティブ / 上記以外の位置に現れる `$`。
 */
test('prompt YAML の Blade 式は単純変数展開と防御指示の静的呼び出しの 2 形だけ', function (): void {
    $simpleVariable = '/\{\{\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*\}\}/';
    $defensiveCall = '/\{\{\s*\\\\Kent013\\\\PrismPrompt\\\\Values\\\\DefensiveInstructions::forUserInput(?:Ja)?\(\)\s*\}\}/';

    $violations = [];
    foreach (PromptYaml::paths() as $path) {
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($path, $parseErrors);
        if ($parsed === null) {
            array_push($violations, ...$parseErrors);

            continue;
        }

        foreach (['system_prompt', 'prompt'] as $key) {
            $value = $parsed[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }

            if (str_contains($value, '{!!')) {
                $violations[] = "{$path} ({$key}): エスケープなし出力 {!! !!} は使えません";
            }

            // 許可 2 形を取り除いた残りに Blade 由来の記号が残っていたら違反
            $residual = (string) preg_replace([$simpleVariable, $defensiveCall], '', $value);
            if (preg_match('/\{\{/', $residual) === 1) {
                $violations[] = "{$path} ({$key}): 許可されていない Blade 式 {{ … }} があります";
            }
            if (preg_match('/\$/', $residual) === 1) {
                $violations[] = "{$path} ({$key}): 変数展開の外に \$ があります";
            }
            if (preg_match('/(?:^|\s)@[a-zA-Z]/', $residual) === 1) {
                $violations[] = "{$path} ({$key}): Blade ディレクティブ (@…) は使えません";
            }
        }
    }

    expect($violations)->toBe([],
        'この契約は窓口 gate (PromptDefenseWindowGateTest) の変数集合突き合わせの前提です。'
        .'構文を増やすなら、あちらの変数抽出も同じ PR で見直してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
