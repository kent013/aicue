<?php

declare(strict_types=1);

use App\Services\Manual\ManualKeywordSearch;

/*
 * T202: 検索語の正規化 (normalize) の純粋関数契約。
 *
 * ここが PC 一覧 (ManualListQuery) と撮影 PWA 一覧 (CaptureManualController) の**共通の入口**で、
 * 面ごとに trim / 上限が食い違っていた状態 (T053 以降の実態) を再発させないための固定点。
 */

test('normalize は null をそのまま null にする', function (): void {
    expect(ManualKeywordSearch::normalize(null))->toBeNull();
});

test('normalize は空文字・空白のみを null にする (絞り込み無し)', function (): void {
    expect(ManualKeywordSearch::normalize(''))->toBeNull();
    expect(ManualKeywordSearch::normalize('   '))->toBeNull();
    expect(ManualKeywordSearch::normalize("\t\n "))->toBeNull();
    // **全角空白は trim されない** (PHP の trim の既定文字集合に U+3000 が入っていないため)。
    // よって全角空白だけの検索は「絞り込み無し」ではなく「全角空白を含む語の検索」になる。
    // これは PC 一覧の従来挙動と同じで、本改善では変えない (面によって挙動を変えないため)。
    expect(ManualKeywordSearch::normalize('　'))->toBe('　');
});

test('normalize は前後の空白を除く', function (): void {
    expect(ManualKeywordSearch::normalize('  ネジ  '))->toBe('ネジ');
});

test('normalize は先頭 MAX_LENGTH **文字**で切る (バイト数ではない)', function (): void {
    $normalized = ManualKeywordSearch::normalize(str_repeat('あ', 201));

    expect($normalized)->toBe(str_repeat('あ', 200));
    expect(mb_strlen((string) $normalized))->toBe(200);
    // UTF-8 の「あ」は 3 バイト。バイト数で切っていたら 200 バイト = 66 文字になる
    expect(strlen((string) $normalized))->toBe(600);
});

test('normalize は境界ちょうど (MAX_LENGTH 文字) を切らない', function (): void {
    $exact = str_repeat('あ', ManualKeywordSearch::MAX_LENGTH);

    expect(ManualKeywordSearch::normalize($exact))->toBe($exact);
});

test('normalize は "0" を検索語として通す (filled() の truthy 判定に依存しない)', function (): void {
    expect(ManualKeywordSearch::normalize('0'))->toBe('0');
});
