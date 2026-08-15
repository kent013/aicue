<?php

declare(strict_types=1);

use App\Exceptions\Llm\PromptResponseRejectedException;
use App\Support\Llm\PromptCanary;

/*
 * 応答カナリア (裁定 AG-028 の「応答カナリアによる乗っ取り検知」)。
 * 検知できること・**検知できないこと**の両方を明示的に固定する。
 */

test('生成した合言葉は canary_bytes の 2 倍の長さの hex である', function (): void {
    $token = PromptCanary::generate()->token;

    expect($token)->toHaveLength(config()->integer('llm-defense.canary_bytes') * 2);
    expect(preg_match('/\A[0-9a-f]+\z/', $token))->toBe(1);
});

test('生成のたびに違う合言葉になる', function (): void {
    expect(PromptCanary::generate()->token)->not->toBe(PromptCanary::generate()->token);
});

test('合言葉を含まない応答は漏洩と判定しない', function (): void {
    $canary = PromptCanary::generate();

    expect($canary->leakedIn('{"steps":[]}'))->toBeFalse();
});

test('大文字化された合言葉の漏洩を検出する', function (): void {
    $canary = PromptCanary::generate();

    expect($canary->leakedIn('合言葉は '.strtoupper($canary->token).' です'))->toBeTrue();
});

test('空白 (改行を含む) を挟んだ合言葉の漏洩を検出する', function (): void {
    $canary = PromptCanary::generate();
    $split = implode(" \n ", str_split($canary->token, 4));

    expect($canary->leakedIn($split))->toBeTrue();
});

test('不正な UTF-8 を含む応答でも fail-open しない', function (): void {
    $canary = PromptCanary::generate();
    // 空白で分割した合言葉 + 不正バイト。/u 付きで正規化していると preg が false を返し、
    // 「漏洩なし」で素通り (fail-open) してしまう組み合わせ。
    $response = "\xC3\x28 ".implode(' ', str_split($canary->token, 8))." \xC3\x28";

    expect($canary->leakedIn($response))->toBeTrue();
});

test('非空白を挟んだ合言葉は検出しない (検知の限界の明示的な pin)', function (): void {
    $canary = PromptCanary::generate();
    $split = implode('-', str_split($canary->token, 4));

    // ★ 将来「検出できる」と誤解した拡張が入るとここが赤くなる。そのときは
    //   docs/architecture.md の「保証しないもの」も同じ PR で直すこと。
    expect($canary->leakedIn($split))->toBeFalse();
});

test('拒否例外の message に合言葉が含まれない', function (): void {
    $exception = PromptResponseRejectedException::canaryLeaked('sop-extract');

    expect($exception->getMessage())->toContain('sop-extract');
    expect($exception->getMessage())->not->toContain(PromptCanary::generate()->token);
});

test('合言葉の長さ設定が 0 なら生成を拒否する (空の合言葉で全応答を落とさない)', function (): void {
    config()->set('llm-defense.canary_bytes', 0);

    expect(fn (): PromptCanary => PromptCanary::generate())->toThrow(LogicException::class);
});
