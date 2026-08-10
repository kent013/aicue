<?php

declare(strict_types=1);

use App\DataTransferObjects\LlmCallContextData;
use App\Models\VideoManual;
use App\Support\LlmMetadataExtractor;

/*
 * LLM 呼び出しの帰属コンテキスト DTO (施策 1)。
 *
 * この DTO は `Prompt::withMetadata()` へ渡す 4 つの汎用キー
 * (organization_id / user_id / subject_type / subject_id) の値オブジェクトであり、
 * listener 側の LlmMetadataExtractor が読み戻せる形になっていることが契約である。
 */

it('for() は subject を getMorphClass() と主キーの文字列表現で持つ', function (): void {
    $manual = VideoManual::factory()->makeOne(['id' => 42]);

    $context = LlmCallContextData::for(7, $manual, 3);

    expect($context->organizationId)->toBe(7)
        ->and($context->userId)->toBe(3)
        ->and($context->subjectType)->toBe($manual->getMorphClass())
        ->and($context->subjectId)->toBe('42');
});

it('null の成分は toMetadata() から落ちる', function (): void {
    $context = LlmCallContextData::for(null, null);

    expect($context->toMetadata())->toBe([]);
});

it('organization だけを持つ context は organization_id のみを載せる', function (): void {
    $context = LlmCallContextData::for(11, null);

    expect($context->toMetadata())->toBe(['organization_id' => 11]);
});

it('none() は帰属なしを明示し空の metadata を返す', function (): void {
    $context = LlmCallContextData::none();

    expect($context->organizationId)->toBeNull()
        ->and($context->userId)->toBeNull()
        ->and($context->subjectType)->toBeNull()
        ->and($context->subjectId)->toBeNull()
        ->and($context->toMetadata())->toBe([]);
});

it('toMetadata() は LlmMetadataExtractor の 4 抽出器を往復して元の値へ戻る', function (): void {
    $manual = VideoManual::factory()->makeOne(['id' => 42]);
    $metadata = LlmCallContextData::for(7, $manual, 3)->toMetadata();

    // listener (RecordLlmCallCost / RecordLlmCallFailure) が行う取り出しと同じ経路
    expect(LlmMetadataExtractor::extractInt($metadata, 'organization_id'))->toBe(7)
        ->and(LlmMetadataExtractor::extractInt($metadata, 'user_id'))->toBe(3)
        ->and(LlmMetadataExtractor::extractString($metadata, 'subject_type'))->toBe($manual->getMorphClass())
        // subject_id は string 化して渡す (extractIntOrString は ULID も int もこの形で吸収する)
        ->and(LlmMetadataExtractor::extractIntOrString($metadata, 'subject_id'))->toBe('42');
});
