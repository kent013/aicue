<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderConflictType;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use Tests\Support\TsUnionValues;

/*
 * PHP enum ⇔ TS literal union の値集合同期 invariant (概念設計 Round 3)。
 *
 * resources/js/types/manual.ts の literal union を正規表現で抽出し、PHP enum の
 * 値集合と完全一致することを固定する (フロントの CTA 分岐・型分岐が enum 追加で
 * silent に壊れるのを防ぐ)。抽出不能 (degenerate PASS) は fail させる。
 * 抽出ロジックは共有 helper (Tests\Support\TsUnionValues) に置き、
 * NotificationTypeTsSyncInvariantTest と共用する。
 */

/**
 * types/manual.ts から `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
 *
 * @return list<string>
 */
function extractTsUnionValues(string $typeName): array
{
    return TsUnionValues::extract('resources/js/types/manual.ts', $typeName);
}

test('RenderKind の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderKind'))->toBe(TsUnionValues::enumStringValues(RenderKind::cases()));
});

test('RenderStep の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderStep'))->toBe(TsUnionValues::enumStringValues(RenderStep::cases()));
});

test('RenderErrorCode の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderErrorCode'))->toBe(TsUnionValues::enumStringValues(RenderErrorCode::cases()));
});

test('RenderConflictType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderConflictType'))->toBe(TsUnionValues::enumStringValues(RenderConflictType::cases()));
});

test('AnalysisJobStatus (JobStatus 共用) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('AnalysisJobStatus'))->toBe(TsUnionValues::enumStringValues(JobStatus::cases()));
});

test('抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => extractTsUnionValues('NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
