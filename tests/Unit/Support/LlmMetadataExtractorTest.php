<?php

declare(strict_types=1);

use App\Support\LlmMetadataExtractor;

// extractInt: ctype_digit 厳格判定 (float / 負号 / 科学記法は null)

it('extractInt は int 値をそのまま返す', function (): void {
    expect(LlmMetadataExtractor::extractInt(['organization_id' => 42], 'organization_id'))->toBe(42);
});

it('extractInt は数字 string を int に変換する', function (): void {
    expect(LlmMetadataExtractor::extractInt(['organization_id' => '42'], 'organization_id'))->toBe(42);
});

it('extractInt はキー欠落で null を返す', function (): void {
    expect(LlmMetadataExtractor::extractInt([], 'organization_id'))->toBeNull();
});

it('extractInt は非数字 string で null を返す', function (): void {
    expect(LlmMetadataExtractor::extractInt(['organization_id' => 'abc'], 'organization_id'))->toBeNull();
});

it('extractInt は float で null を返す', function (): void {
    expect(LlmMetadataExtractor::extractInt(['organization_id' => 42.5], 'organization_id'))->toBeNull();
});

it('extractInt は負号付き string を弾く', function (): void {
    expect(LlmMetadataExtractor::extractInt(['organization_id' => '-42'], 'organization_id'))->toBeNull();
});

it('extractInt は小数・科学記法 string を弾く', function (string $value): void {
    expect(LlmMetadataExtractor::extractInt(['organization_id' => $value], 'organization_id'))->toBeNull();
})->with(['1.5', '1e3', ' 42', '42 ']);

// extractString

it('extractString は string 値をそのまま返す', function (): void {
    expect(LlmMetadataExtractor::extractString(['subject_type' => 'App\\Models\\Item'], 'subject_type'))->toBe('App\\Models\\Item');
});

it('extractString は空文字で null を返す', function (): void {
    expect(LlmMetadataExtractor::extractString(['subject_type' => ''], 'subject_type'))->toBeNull();
});

it('extractString は非 string で null を返す', function (): void {
    expect(LlmMetadataExtractor::extractString(['subject_type' => 123], 'subject_type'))->toBeNull();
});

it('extractString はキー欠落で null を返す', function (): void {
    expect(LlmMetadataExtractor::extractString([], 'subject_type'))->toBeNull();
});

// extractIntOrString: subject_id 用 (int 主キー / ULID string 双方対応)

it('extractIntOrString は int を string にキャストする', function (): void {
    expect(LlmMetadataExtractor::extractIntOrString(['subject_id' => 123], 'subject_id'))->toBe('123');
});

it('extractIntOrString は数字 string をそのまま返す', function (): void {
    expect(LlmMetadataExtractor::extractIntOrString(['subject_id' => '123'], 'subject_id'))->toBe('123');
});

it('extractIntOrString は ULID string をそのまま返す', function (): void {
    $ulid = '01JXAMPLE0000000000000000A';
    expect(LlmMetadataExtractor::extractIntOrString(['subject_id' => $ulid], 'subject_id'))->toBe($ulid);
});

it('extractIntOrString は空文字・bool・float・array で null を返す', function (mixed $value): void {
    expect(LlmMetadataExtractor::extractIntOrString(['subject_id' => $value], 'subject_id'))->toBeNull();
})->with([
    '空文字' => [''],
    'bool' => [true],
    'float' => [1.5],
    'array' => [[1]],
]);

it('extractIntOrString はキー欠落で null を返す', function (): void {
    expect(LlmMetadataExtractor::extractIntOrString([], 'subject_id'))->toBeNull();
});
