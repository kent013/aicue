<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderConflictType;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;

/*
 * PHP enum ⇔ TS literal union の値集合同期 invariant (概念設計 Round 3)。
 *
 * resources/js/types/manual.ts の literal union を正規表現で抽出し、PHP enum の
 * 値集合と完全一致することを固定する (フロントの CTA 分岐・型分岐が enum 追加で
 * silent に壊れるのを防ぐ)。抽出不能 (degenerate PASS) は fail させる。
 */

/**
 * types/manual.ts から `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
 *
 * @return list<string>
 */
function extractTsUnionValues(string $typeName): array
{
    $path = base_path('resources/js/types/manual.ts');
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("types/manual.ts を読めません: {$path}");
    }

    // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
    $matched = preg_match(
        '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
        $contents,
        $matches,
    );
    if ($matched !== 1) {
        throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
    }

    $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
    if ($literalCount === false || $literalCount === 0) {
        throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
    }

    $values = $literals[1];
    sort($values);

    return $values;
}

/**
 * @param  list<BackedEnum>  $cases
 * @return list<string>
 */
function enumStringValues(array $cases): array
{
    $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
    sort($values);

    return $values;
}

test('RenderKind の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderKind'))->toBe(enumStringValues(RenderKind::cases()));
});

test('RenderStep の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderStep'))->toBe(enumStringValues(RenderStep::cases()));
});

test('RenderErrorCode の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderErrorCode'))->toBe(enumStringValues(RenderErrorCode::cases()));
});

test('RenderConflictType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderConflictType'))->toBe(enumStringValues(RenderConflictType::cases()));
});

test('AnalysisJobStatus (JobStatus 共用) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('AnalysisJobStatus'))->toBe(enumStringValues(JobStatus::cases()));
});

test('抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => extractTsUnionValues('NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
