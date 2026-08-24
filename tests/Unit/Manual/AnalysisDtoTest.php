<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\DataTransferObjects\Manual\Analysis\WorkDecompositionData;
use App\Enums\Manual\LlmOutputInvalidReason;
use App\Enums\Manual\ShotType;
use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Support\Manual\LlmJson;
use App\Support\Manual\ScenarioLimits;
use Tests\Support\Manual\FencedLlmResponse;
use Tests\Support\Manual\LlmJsonRejection;

/*
 * LLM 出力 DTO の fromLlmText 検証 (施策 8):
 * - 受理契約 (囲みちょうど 1 つ) / 区分つきの拒否 / スキーマ違反 / 有界性 / parent_no 整合
 * - 違反は LlmOutputInvalidException (有界リトライのトリガー)
 *
 * ★受理契約そのものの網羅は tests/Unit/Manual/LlmJsonTest.php が持つ。ここは
 *   「DTO の入口が復号点の契約に乗っている」ことだけを固定する。
 */

test('LlmJson::decode は囲みちょうど 1 つの応答を受理し、素の JSON は fence_absent で拒否する', function (): void {
    expect(LlmJson::decode("```json\n{\"a\": 1}\n```"))->toBe(['a' => 1]);

    expect(LlmJsonRejection::capture('{"a": 1}')->reason)
        ->toBe(LlmOutputInvalidReason::FenceAbsent);
});

test('LlmJson::decode は囲みの無い文章を fence_absent で拒否する', function (): void {
    expect(LlmJsonRejection::capture('これは JSON ではない')->reason)
        ->toBe(LlmOutputInvalidReason::FenceAbsent);
});

test('ExtractedSopData: 正常系 + 手順 0 件は SchemaViolation', function (): void {
    $valid = ExtractedSopData::fromLlmText(FencedLlmResponse::wrapArray([
        'header' => ['title' => 'SOP'],
        'sections' => [[
            'title' => null,
            'steps' => [[
                'no' => 1, 'work_process' => '締める',
                'work_points' => [], 'safety_points' => [], 'quality_points' => [], 'pm_points' => [],
            ]],
        ]],
    ]));
    expect($valid->sections)->toHaveCount(1);
    expect($valid->toJsonString())->toContain('締める');

    expect(fn (): ExtractedSopData => ExtractedSopData::fromLlmText(FencedLlmResponse::wrap('{"header": {}, "sections": []}')))
        ->toThrow(LlmOutputInvalidException::class);
});

test('WorkDecompositionData: steps 上限超過・非空 action を検証する', function (): void {
    $steps = array_map(
        static fn (int $no): array => ['no' => $no, 'action' => "動作 {$no}", 'points' => []],
        range(1, ScenarioLimits::MAX_STEPS + 1),
    );
    expect(fn (): WorkDecompositionData => WorkDecompositionData::fromPayload(['steps' => $steps]))
        ->toThrow(LlmOutputInvalidException::class);

    expect(fn (): WorkDecompositionData => WorkDecompositionData::fromPayload(
        ['steps' => [['no' => 1, 'action' => '', 'points' => []]]],
    ))->toThrow(LlmOutputInvalidException::class);
});

test('GeneratedScenarioData: steps ツリーへ変換される (id=null / shot_type enum)', function (): void {
    $data = GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
        ['no' => 1, 'type' => 'step', 'parent_no' => null, 'scene' => '全体', 'shot_type' => 'hiki',
            'shooting_point' => null, 'narration' => 'やります', 'subtitle_primary' => null, 'subtitle_secondary' => '字幕'],
        ['no' => 2, 'type' => 'point', 'parent_no' => 1, 'scene' => '手元', 'shot_type' => 'yori',
            'shooting_point' => '寄る', 'narration' => null, 'subtitle_primary' => '要点', 'subtitle_secondary' => null],
    ]]));

    $steps = $data->toScenarioSteps();
    expect($steps)->toHaveCount(1);
    expect($steps[0]->id)->toBeNull();
    expect($steps[0]->shotType)->toBe(ShotType::Hiki);
    expect($steps[0]->points)->toHaveCount(1);
    expect($steps[0]->points[0]->shotType)->toBe(ShotType::Yori);
    // null 許容フィールドは '' へ正規化 (DB NOT NULL)
    expect($steps[0]->points[0]->narration)->toBe('');
    expect($steps[0]->points[0]->subtitleSecondary)->toBe('');
});

test('GeneratedScenarioData: parent_no の前方参照・無参照は SchemaViolation', function (): void {
    // 前方参照 (point が後出の step を参照)
    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
        ['no' => 1, 'type' => 'point', 'parent_no' => 2, 'scene' => '手元', 'shot_type' => 'yori',
            'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
        ['no' => 2, 'type' => 'step', 'parent_no' => null, 'scene' => '全体', 'shot_type' => 'hiki',
            'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
    ]])))->toThrow(LlmOutputInvalidException::class);

    // step が parent_no を持つ
    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
        ['no' => 1, 'type' => 'step', 'parent_no' => 5, 'scene' => '全体', 'shot_type' => 'hiki',
            'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
    ]])))->toThrow(LlmOutputInvalidException::class);
});

test('GeneratedScenarioData: 文字数上限・不正 shot_type は SchemaViolation', function (): void {
    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
        ['no' => 1, 'type' => 'step', 'parent_no' => null,
            'scene' => str_repeat('あ', ScenarioLimits::MAX_SCENE_CHARS + 1), 'shot_type' => 'hiki',
            'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
    ]])))->toThrow(LlmOutputInvalidException::class);

    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
        ['no' => 1, 'type' => 'step', 'parent_no' => null, 'scene' => '全体', 'shot_type' => 'zoom',
            'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
    ]])))->toThrow(LlmOutputInvalidException::class);
});
