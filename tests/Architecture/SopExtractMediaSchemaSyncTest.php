<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * sop-extract.yaml (text 版) と sop-extract-media.yaml (OCR 版) は同一の出力スキーマを
 * コピーで共有している (画像・スキャン SOP の OCR 対応)。共通化する仕組みが
 * prism-prompt に無いため、スキーマ変更時に片方だけ直すドリフトを防ぐ検査を置く。
 *
 * 「正規化」の定義を具体的に固定する: 両 YAML の `prompt:` フィールドから
 * `出力スキーマ:` という見出し行を目印にし、そこから JSON スキーマのブロック
 * (最初に現れる `{` から対応する `}` まで、ネストした波括弧を数える) だけを抽出する。
 * 見出しが 1 つの YAML 内に複数回現れた場合・見出しが見つからない場合は
 * 「抽出できない」として fail-closed にする (先頭/末尾のどちらかを暗黙選択しない)。
 */

/**
 * ★ **走査対象**: 引数 `$promptText` (YAML の `prompt:` フィールド文字列) 中の
 *   `出力スキーマ:` 見出しから、対応する波括弧までの JSON ブロック 1 つ。
 * ★ **保証しないもの (誇張しない)**: 単純な波括弧の深さカウントであり、構文解析器ではない。
 *   JSON 文字列リテラルの中に含まれる `{` / `}` は構文上の括弧と区別されない
 *   (例えば `{"note": "a{b"}` のような値の中の不対称な括弧は抽出範囲を誤らせうる)。
 *   本テスト対象の 2 つの YAML はこの構文を持たないため実害は無いが、
 *   新しく追加するスキーマ記述がこの構文を持つ場合は本抽出器の限界に注意すること。
 */
function extractSchemaBlock(string $promptText, array &$errors, string $file): ?string
{
    $marker = '出力スキーマ:';
    $count = substr_count($promptText, $marker);
    if ($count !== 1) {
        $errors[] = "{$file}: 見出し「{$marker}」が {$count} 回出現しています (ちょうど 1 回である必要)";

        return null;
    }

    $markerPosition = strpos($promptText, $marker);
    if ($markerPosition === false) {
        $errors[] = "{$file}: 見出しが見つかりません";

        return null;
    }

    $braceStart = strpos($promptText, '{', $markerPosition);
    if ($braceStart === false) {
        $errors[] = "{$file}: 見出しの後に JSON ブロックの開始 '{' が見つかりません";

        return null;
    }

    $depth = 0;
    $length = strlen($promptText);
    for ($i = $braceStart; $i < $length; $i++) {
        if ($promptText[$i] === '{') {
            $depth++;
        } elseif ($promptText[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($promptText, $braceStart, $i - $braceStart + 1);
            }
        }
    }

    $errors[] = "{$file}: JSON ブロックの対応する '}' が見つかりません (波括弧の対応不良)";

    return null;
}

test('sop-extract.yaml と sop-extract-media.yaml の出力スキーマが完全一致する', function (): void {
    $errors = [];

    $textYaml = Yaml::parseFile(resource_path('prompts/sop-extract.yaml'));
    $mediaYaml = Yaml::parseFile(resource_path('prompts/sop-extract-media.yaml'));
    expect($textYaml)->toBeArray();
    expect($mediaYaml)->toBeArray();

    $textPrompt = $textYaml['prompt'] ?? null;
    $mediaPrompt = $mediaYaml['prompt'] ?? null;
    expect($textPrompt)->toBeString();
    expect($mediaPrompt)->toBeString();

    $textSchema = extractSchemaBlock($textPrompt, $errors, 'sop-extract.yaml');
    $mediaSchema = extractSchemaBlock($mediaPrompt, $errors, 'sop-extract-media.yaml');

    expect($errors)->toBe([], implode(PHP_EOL, $errors));
    expect($textSchema)->not->toBeNull();
    expect($mediaSchema)->not->toBeNull();
    expect($mediaSchema)->toBe($textSchema,
        '2 つの YAML の出力スキーマがずれています。sop-extract.yaml のスキーマを変更したら'
        .'sop-extract-media.yaml も同じスキーマへ揃えてください。');
});

test('見出しが複数回現れる場合は抽出できないとして fail-closed になる (負例)', function (): void {
    $errors = [];
    $duplicated = "出力スキーマ:\n{ \"a\": 1 }\n出力スキーマ:\n{ \"b\": 2 }";

    $result = extractSchemaBlock($duplicated, $errors, 'fixture.yaml');

    expect($result)->toBeNull();
    expect($errors)->not->toBeEmpty();
});

test('見出しが無い場合も抽出できないとして fail-closed になる (負例)', function (): void {
    $errors = [];
    $result = extractSchemaBlock("スキーマの説明が無い本文だけ\n{ \"a\": 1 }", $errors, 'fixture.yaml');

    expect($result)->toBeNull();
    expect($errors)->not->toBeEmpty();
});

test('見出しの後に開始 { が無い場合も抽出できないとして fail-closed になる (負例)', function (): void {
    $errors = [];
    $result = extractSchemaBlock("出力スキーマ:\nJSON はありません", $errors, 'fixture.yaml');

    expect($result)->toBeNull();
    expect($errors)->not->toBeEmpty();
});

test('対応する閉じ } が無い場合も抽出できないとして fail-closed になる (負例)', function (): void {
    $errors = [];
    $result = extractSchemaBlock("出力スキーマ:\n{ \"a\": { \"b\": 1 }", $errors, 'fixture.yaml');

    expect($result)->toBeNull();
    expect($errors)->not->toBeEmpty();
});

test('正常なネストを持つスキーマは正しく抽出できる (正例)', function (): void {
    $errors = [];
    $source = "出力スキーマ:\n{ \"a\": { \"b\": [1, 2] }, \"c\": 3 }\n末尾の説明文";
    $result = extractSchemaBlock($source, $errors, 'fixture.yaml');

    expect($errors)->toBe([]);
    expect($result)->toBe('{ "a": { "b": [1, 2] }, "c": 3 }');
});
