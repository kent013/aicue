<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Analysis\WorkDecompositionResponseData;
use App\Enums\Manual\ScenarioVerdict;
use App\Exceptions\Manual\LlmOutputInvalidException;

/*
 * WorkDecompositionResponseData: work-decomposition 応答全体 ({steps, validation}) を
 * **1 回の decode** で組み立てることと、違反位置 (path) が steps 側と validation 側で
 * 識別できることを固定する。
 */

/**
 * 応答テキストを組み立てる (上書きしたいキーだけ差し替える)。
 *
 * @param  array<string, mixed>  $overrides
 */
function decompositionResponseText(array $overrides = []): string
{
    return json_encode([...[
        'steps' => [['no' => 1, 'action' => 'バルブを閉じる', 'points' => ['止まるまで回す']]],
        'validation' => [
            'verdict' => 'needs_review',
            'reason' => '一部の急所が読み取れませんでした。',
            'works' => ['バルブ閉止作業', '点検作業'],
            'split_recommended' => true,
        ],
    ], ...$overrides], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

test('steps と validation の両方が揃った応答を組み立てる', function (): void {
    $response = WorkDecompositionResponseData::fromLlmText(decompositionResponseText());

    expect($response->decomposition->steps)->toHaveCount(1);
    expect($response->decomposition->steps[0]->action)->toBe('バルブを閉じる');
    expect($response->validation->verdict)->toBe(ScenarioVerdict::NeedsReview);
    expect($response->validation->workCount())->toBe(2);
    expect($response->validation->splitRecommended)->toBeTrue();
    // 次段へ渡す JSON に所見は混ざらない (入力 token を無駄にせず生成器の指示も汚さない)
    expect($response->decomposition->toJsonString())->not->toContain('needs_review');
});

test('validation 欠落は path=validation の LlmOutputInvalidException になる', function (): void {
    $text = json_encode([
        'steps' => [['no' => 1, 'action' => 'バルブを閉じる', 'points' => []]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    try {
        WorkDecompositionResponseData::fromLlmText($text);
        expect(false)->toBeTrue(); // 到達しない
    } catch (LlmOutputInvalidException $exception) {
        expect($exception->path)->toBe('validation');
    }
});

test('steps 側の違反は path が steps. で始まる (validation 側と識別できる)', function (): void {
    try {
        WorkDecompositionResponseData::fromLlmText(decompositionResponseText([
            'steps' => [['no' => 1, 'action' => '', 'points' => []]],
        ]));
        expect(false)->toBeTrue(); // 到達しない
    } catch (LlmOutputInvalidException $exception) {
        expect($exception->path)->toBe('steps.0.action');
    }
});

test('JSON として壊れている応答は path=null のまま落ちる (既存経路は無変更)', function (): void {
    try {
        WorkDecompositionResponseData::fromLlmText('これは JSON ではない');
        expect(false)->toBeTrue(); // 到達しない
    } catch (LlmOutputInvalidException $exception) {
        expect($exception->path)->toBeNull();
    }
});
