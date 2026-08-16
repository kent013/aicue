<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Analysis\SopValidationData;
use App\Enums\Manual\ScenarioVerdict;
use App\Exceptions\Manual\LlmOutputInvalidException;
use Illuminate\Support\Facades\Log;

/*
 * SopValidationData (手順書への所見) の 2 入口の厳しさの違いを固定する。
 * - fromPayload: LLM 応答用。不正は LlmOutputInvalidException (= 有界リトライ) で path 付き
 * - fromStorage: 保存済み JSON 用。不正は null + Log::warning (詳細画面を落とさない)
 */

/**
 * 妥当な validation payload を作る (上書きしたいキーだけ差し替える)。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validationPayload(array $overrides = []): array
{
    return [...[
        'verdict' => 'valid',
        'reason' => '手順と急所が読み取れています。',
        'works' => ['バルブ閉止作業'],
        'split_recommended' => false,
    ], ...$overrides];
}

test('fromPayload: 3 つの verdict 値をすべて受理する', function (string $raw, ScenarioVerdict $expected): void {
    $data = SopValidationData::fromPayload(['validation' => validationPayload(['verdict' => $raw])]);

    expect($data->verdict)->toBe($expected);
    expect($data->works)->toBe(['バルブ閉止作業']);
    expect($data->workCount())->toBe(1);
    expect($data->splitRecommended)->toBeFalse();
})->with([
    'valid' => ['valid', ScenarioVerdict::Valid],
    'needs_review' => ['needs_review', ScenarioVerdict::NeedsReview],
    'invalid' => ['invalid', ScenarioVerdict::Invalid],
]);

test('fromPayload: toArray() が保存 shape になる (fromStorage が受理する shape と同一)', function (): void {
    $data = SopValidationData::fromPayload(['validation' => validationPayload(['split_recommended' => true])]);

    expect($data->toArray())->toBe([
        'verdict' => 'valid',
        'reason' => '手順と急所が読み取れています。',
        'works' => ['バルブ閉止作業'],
        'split_recommended' => true,
    ]);
    // 往復できる (保存 shape → 復元)
    expect(SopValidationData::fromStorage($data->toArray(), 1)?->splitRecommended)->toBeTrue();
});

test('fromPayload: 不正な validation は path 付きの LlmOutputInvalidException になる', function (mixed $validation, string $expectedPath): void {
    try {
        SopValidationData::fromPayload(['validation' => $validation]);
        expect(false)->toBeTrue(); // 到達しない
    } catch (LlmOutputInvalidException $exception) {
        expect($exception->path)->toBe($expectedPath);
    }
})->with([
    'validation が object でない' => ['文字列', 'validation'],
    'verdict が未知の値' => [validationPayload(['verdict' => 'maybe']), 'validation.verdict'],
    'verdict が文字列でない' => [validationPayload(['verdict' => 1]), 'validation.verdict'],
    'reason が空' => [validationPayload(['reason' => '  ']), 'validation.reason'],
    'reason が上限超過' => [
        validationPayload(['reason' => str_repeat('あ', SopValidationData::MAX_REASON_CHARS + 1)]),
        'validation.reason',
    ],
    'works が配列でない' => [validationPayload(['works' => 'バルブ']), 'validation.works'],
    'works が 0 件' => [validationPayload(['works' => []]), 'validation.works'],
    'works が上限超過' => [
        validationPayload(['works' => array_fill(0, SopValidationData::MAX_WORKS + 1, '作業')]),
        'validation.works',
    ],
    'works の要素が非文字列' => [validationPayload(['works' => ['作業', 3]]), 'validation.works.1'],
    'works のタイトルが上限超過' => [
        validationPayload(['works' => [str_repeat('あ', SopValidationData::MAX_WORK_TITLE_CHARS + 1)]]),
        'validation.works.0',
    ],
    'split_recommended が真偽値でない' => [
        validationPayload(['split_recommended' => 'yes']),
        'validation.split_recommended',
    ],
]);

test('fromPayload: validation キーが欠けていたら path=validation で落ちる', function (): void {
    try {
        SopValidationData::fromPayload(['steps' => []]);
        expect(false)->toBeTrue(); // 到達しない
    } catch (LlmOutputInvalidException $exception) {
        expect($exception->path)->toBe('validation');
    }
});

test('fromStorage: null は正常系として null を返す (旧ジョブ)', function (): void {
    Log::spy();

    expect(SopValidationData::fromStorage(null, 42))->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

test('fromStorage: 壊れた保存値は null + Log::warning で画面を落とさない', function (mixed $stored): void {
    Log::spy();

    expect(SopValidationData::fromStorage($stored, 42))->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(
        function (string $message, array $context): bool {
            // 本文 (LLM 由来の可変文字列) は載せず、分類と違反位置だけを載せる
            return $context['analysis_job_id'] === 42
                && $context['failure_category'] === 'schema_violation'
                && is_string($context['failure_path'])
                && str_starts_with($context['failure_path'], 'validation');
        },
    )->once();
})->with([
    'array でない' => ['こわれた値'],
    'verdict が壊れている' => [[validationPayload(['verdict' => 'broken'])][0]],
    'works が空' => [[validationPayload(['works' => []])][0]],
]);
