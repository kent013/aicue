<?php

declare(strict_types=1);

use App\Enums\Manual\LlmOutputInvalidReason;
use App\Support\Manual\LlmJson;
use Tests\Support\Manual\LlmJsonRejection;

/*
 * 復号点 `LlmJson::decode()` の受理契約 (家系の正典 v1 の i2〜i6)。
 *
 * 受理文法: 応答 = PRE OPEN VALUE GAP CLOSE POST
 * 区分の決定順序は `LlmJson` の docblock が正本。ここではその表の各行を 1 ケースずつ固定する。
 */

// ---- 受理 (6 件) ----

test('A1: 言語札つきの囲みちょうど 1 つを受理する', function (): void {
    expect(LlmJson::decode("```json\n{\"a\": 1}\n```"))->toBe(['a' => 1]);
});

test('A2: 言語札の無い囲みを受理する', function (): void {
    expect(LlmJson::decode("```\n{\"a\": 1}\n```"))->toBe(['a' => 1]);
});

test('A3: 印を含まない前置き・後書きがあっても受理する', function (): void {
    expect(LlmJson::decode("解析結果は次のとおりです。\n```json\n{\"a\": 1}\n```\n以上です。"))->toBe(['a' => 1]);
});

test('A4: 最上位が list でも受理する (正典 q3 は据え置き)', function (): void {
    expect(LlmJson::decode("```json\n[1, 2]\n```"))->toBe([1, 2]);
});

test('A5: 値の中に現れた印は終端に数えない', function (): void {
    expect(LlmJson::decode("```json\n{\"a\": \"``` inside\"}\n```"))->toBe(['a' => '``` inside']);
});

test('A6: 逆引用符の個数の対応は見ない (開き 4 個 + 閉じ 3 個)', function (): void {
    expect(LlmJson::decode("````json\n{\"a\": 1}\n```"))->toBe(['a' => 1]);
});

// ---- 拒否 (17 件。区分まで検証する) ----

dataset('受理契約に合わない応答', [
    'R1: 素の JSON' => ['{"a": 1}', LlmOutputInvalidReason::FenceAbsent],
    'R2: JSON でない文章' => ['これは JSON ではありません', LlmOutputInvalidReason::FenceAbsent],
    'R3: 閉じの印より後にもう 1 つ囲みがある' => [
        "```json\n{\"a\": 1}\n``` そして ```json\n{\"b\": 2}\n```",
        LlmOutputInvalidReason::FenceMultiple,
    ],
    'R4: 値の後の印が別言語の開き' => [
        "```json\n{\"a\": 1}\n```python\nprint()\n",
        LlmOutputInvalidReason::FenceMultiple,
    ],
    'R5: 括弧の対応が取れない' => ["```json\n{\"a\": [}\n```", LlmOutputInvalidReason::SyntaxBroken],
    'R6: 値の後の余剰トークン' => ["```json\n{\"a\": 1}}\n```", LlmOutputInvalidReason::SyntaxBroken],
    'R7: json_decode が落ちる値' => ["```json\n{\"a\": }\n```", LlmOutputInvalidReason::SyntaxBroken],
    'R8: 最上位が数値' => ["```json\n42\n```", LlmOutputInvalidReason::TopLevelNotContainer],
    'R9: 空のブロック' => ["```json\n```", LlmOutputInvalidReason::TopLevelNotContainer],
    'R10: 最上位が文字列' => ["```json\n\"文字列\"\n```", LlmOutputInvalidReason::TopLevelNotContainer],
    'R11: 値が完結しないまま終端' => ["```json\n{\"a\": 1", LlmOutputInvalidReason::ValueIncompleteInferred],
    'R12: 文字列の途中で終端' => ["```json\n{\"a\": \"未閉", LlmOutputInvalidReason::ValueIncompleteInferred],
    'R13: 開きの印の直後で終端' => ['```json', LlmOutputInvalidReason::ValueIncompleteInferred],
    'R14: 閉じの印が無い' => ["```json\n{\"a\": 1}\n", LlmOutputInvalidReason::ClosingFenceAbsent],
    'R16: 不正な UTF-8 を含む値' => ["```json\n{\"a\": \"\xC3\x28\"}\n```", LlmOutputInvalidReason::SyntaxBroken],
    'R17a: GAP に全角空白' => ["```json\n{\"a\": 1}\u{3000}\n```", LlmOutputInvalidReason::SyntaxBroken],
    'R17b: GAP に NBSP' => ["```json\n{\"a\": 1}\u{00A0}\n```", LlmOutputInvalidReason::SyntaxBroken],
]);

test('受理契約に合わない応答は区分つきで拒否される', function (string $text, LlmOutputInvalidReason $reason): void {
    expect(LlmJsonRejection::capture($text)->reason)->toBe($reason);
})->with('受理契約に合わない応答');

test('R15: 入れ子の深さ超過は委譲先の JsonException で syntax_broken', function (): void {
    $deep = str_repeat('[', 513).str_repeat(']', 513);

    expect(LlmJsonRejection::capture("```json\n".$deep."\n```")->reason)
        ->toBe(LlmOutputInvalidReason::SyntaxBroken);
});

// ---- 非漏洩 (6 区分。i9) ----

dataset('sentinel を含む 6 区分の応答', [
    'fence_absent' => ['プレーンな応答 '.LlmJsonRejection::SENTINEL, LlmOutputInvalidReason::FenceAbsent],
    'fence_multiple' => [
        "```json\n{\"a\": \"".LlmJsonRejection::SENTINEL."\"}\n```\n```json\n{\"b\": 2}\n```",
        LlmOutputInvalidReason::FenceMultiple,
    ],
    'syntax_broken' => [
        "```json\n{\"a\": \"".LlmJsonRejection::SENTINEL."\",}\n```",
        LlmOutputInvalidReason::SyntaxBroken,
    ],
    'top_level_not_container' => [
        "```json\n\"".LlmJsonRejection::SENTINEL."\"\n```",
        LlmOutputInvalidReason::TopLevelNotContainer,
    ],
    'value_incomplete_inferred' => [
        "```json\n{\"a\": \"".LlmJsonRejection::SENTINEL,
        LlmOutputInvalidReason::ValueIncompleteInferred,
    ],
    'closing_fence_absent' => [
        "```json\n{\"a\": \"".LlmJsonRejection::SENTINEL."\"}\n",
        LlmOutputInvalidReason::ClosingFenceAbsent,
    ],
]);

test('例外の message / userMessage に応答本文が漏れない', function (string $text, LlmOutputInvalidReason $reason): void {
    $exception = LlmJsonRejection::capture($text);

    expect($exception->reason)->toBe($reason);
    expect($exception->getMessage())->not->toContain(LlmJsonRejection::SENTINEL);
    expect($exception->userMessage())->not->toContain(LlmJsonRejection::SENTINEL);
})->with('sentinel を含む 6 区分の応答');
