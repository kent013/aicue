<?php

declare(strict_types=1);

use App\Services\Manual\RenderJobService;

/*
 * RenderJobService::assertTotalSourceDurationWithinLimit() の**現在のソース形**が
 * DeterminedCutDuration::milliseconds() への委譲を含むことを固定する source-shape pin である。
 *
 * **これは「唯一の所在」を保証する不変条件テストではない** (design-review Round 2/3 対応で
 * 保証範囲を明示的に狭めた)。検出するのは「委譲の文字列が現在のソースに存在するか」
 * という正の判定 1 つだけである。検出しないもの: alias import 経由の呼び出し
 * (`use DeterminedCutDuration as X`) / 旧式の 3 分岐が委譲と**並存して**残っていること /
 * 別クラスでの同じ 3 分岐の再実装 / コメント・文字列リテラル内の記述との区別。
 * (Round 2 版は「旧来のパターンを含まない」ことの否定判定も持っていたが、
 * 部分文字列一致の否定は AGENTS.md 走査器共通規約 (e) に抵触するため Round 3 で削除した。
 * 「拾いすぎる (誤って赤くする) のは可、見逃す (誤って緑にする) のは不可」の原則に照らすと、
 * 否定判定は見逃しの実害の方が大きいため、正の判定だけに絞る方を選ぶ。)
 *
 * 正例テストと合成負例の自己テストは**同一ファイルに置く**
 * (design-review Round 3 [Warning] 対応。別テストファイル・別レーンへローカル関数を
 * 共有する前提を作らない)。
 */
function determinedCutDurationDelegationPresent(string $methodBody): bool
{
    return str_contains($methodBody, 'DeterminedCutDuration::milliseconds(');
}

/**
 * メソッド定義行の範囲をファイルから切り出すだけの小さいヘルパ
 * (`ReflectionMethod::getStartLine()`/`getEndLine()` パターン。
 * クラス名・名前解決は伴わない = 走査器共通規約 (a) の対象外)。
 */
function sourceOf(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();
    if ($file === false) {
        throw new RuntimeException("{$class}::{$method}() のファイルパスが取得できない");
    }

    $lines = file($file);
    if ($lines === false) {
        throw new RuntimeException("{$file} が読み取れない");
    }

    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    if ($start === false || $end === false) {
        throw new RuntimeException("{$class}::{$method}() の行範囲が取得できない");
    }

    return implode('', array_slice($lines, $start - 1, $end - $start + 1));
}

test('RenderJobService の尺上限ゲートは DeterminedCutDuration への委譲を含む', function (): void {
    $body = sourceOf(RenderJobService::class, 'assertTotalSourceDurationWithinLimit');

    expect(determinedCutDurationDelegationPresent($body))->toBeTrue();
});

// 自己テスト: 委譲を含まない合成文字列を検出器へ直接与え、false を返すことを固定する
// (Pest の失敗する assertion を負例にそのまま流用する問題を避けるため、
// 検出処理を独立した純粋関数にしてある)
test('検出器は委譲を含まない文字列を偽と判定する (自己テスト)', function (): void {
    $legacyBody = <<<'PHP'
        $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
            ? StillDisplayDuration::secondsFor($cut) * 1000
            : ($take->duration_ms ?? $defaultMs);
        PHP;

    expect(determinedCutDurationDelegationPresent($legacyBody))->toBeFalse();
});
