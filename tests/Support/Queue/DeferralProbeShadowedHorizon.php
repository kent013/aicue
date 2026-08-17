<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/**
 * C4 正例 fixture (行範囲切り出しの証明) の**先行 helper**。
 *
 * 意図的に**禁止式**の `retryUntil()` を持つ。`ReflectionMethod::getStartLine()` /
 * `getEndLine()` で切り出さず「ソース中の最初の `function retryUntil`」を見る実装は、
 * 後続の `DeferralProbeShadowedHorizon` を検査したつもりでこちらを読み、偽レッドになる。
 *
 * autoload されるのはファイル名と同名のクラスだけで、この helper はそのファイルの
 * 読み込みで一緒に宣言される (テストから名指ししない)。
 */
final class DeferralProbeShadowHelper
{
    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now();
    }
}

/**
 * C4 **正例** fixture: **1 ファイルに 2 クラス**あり、後ろのこちらが許可式を持つ。
 */
final class DeferralProbeShadowedHorizon
{
    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(45);
    }
}
