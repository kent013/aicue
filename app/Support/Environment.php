<?php

declare(strict_types=1);

namespace App\Support;

/**
 * production / local 判定を config('app.env') 直読で一元化する。
 *
 * Laravel の `app()->isProduction()` は起動時に `config('app.env')` から取得した
 * キャッシュ値を見るため、テスト中に `config(['app.env' => 'production'])` で
 * 上書きしても切り替わらない。本ラッパは毎回 config を直読するので、
 * 実装とテストで判定経路を一本化できる (GTM partial の二重ゲート等で利用)。
 */
class Environment
{
    public static function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    public static function isLocal(): bool
    {
        return config('app.env') === 'local';
    }
}
