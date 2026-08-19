<?php

declare(strict_types=1);

use App\Support\Manual\JapaneseTextRatio;

/*
 * JapaneseTextRatio (画像・スキャン SOP の OCR 対応): SopTextExtractor の文書受理ゲートと
 * AnalysisAcceptanceGate (OCR 経路の成功条件) が共有する日本語比率判定ロジック。
 * SopTextExtractor から切り出した後も既存の閾値挙動が変わらないことを固定する。
 */

test('全て日本語なら比率 1.0', function (): void {
    expect(JapaneseTextRatio::of('作業手順書'))->toBe(1.0);
});

test('日本語を含まない ASCII のみは比率 0.0', function (): void {
    expect(JapaneseTextRatio::of('[UNREADABLE][UNREADABLE]'))->toBe(0.0);
});

test('空文字列は比率 0.0 (0 除算を起こさない)', function (): void {
    expect(JapaneseTextRatio::of(''))->toBe(0.0);
});

test('空白のみの文字列は比率 0.0', function (): void {
    expect(JapaneseTextRatio::of("   \n\t　"))->toBe(0.0);
});

test('空白は分母に数えない (レイアウト由来の空白量に判定を引きずられない)', function (): void {
    $withSpaces = JapaneseTextRatio::of("作業 手順 書\n\n");
    $withoutSpaces = JapaneseTextRatio::of('作業手順書');

    expect($withSpaces)->toBe($withoutSpaces);
});

test('半角カナは日本語文字として数える', function (): void {
    expect(JapaneseTextRatio::of('ｱｲｳｴｵ'))->toBe(1.0);
});

test('日本語と非日本語が混在すると比率は 0 と 1 の間になる', function (): void {
    $ratio = JapaneseTextRatio::of('作abc');
    expect($ratio)->toBeGreaterThan(0.0);
    expect($ratio)->toBeLessThan(1.0);
});
