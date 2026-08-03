<?php

declare(strict_types=1);

namespace Tests\Support\Routing;

use Illuminate\Database\Eloquent\Model;

/**
 * IV-9(c) の負のコントロール用 fixture。
 *
 * `getRouteKeyName()` が PK でない列を返すモデル (= route key が bigint / uuid でない)。
 * 本 fixture は DB に触れない (metadata 検査のみに使う)。実アプリのモデルを
 * 一時的に書き換えずに「非 PK 解決を検出できること」を示すために置く。
 */
final class SlugKeyedFixtureModel extends Model
{
    protected $table = 'slug_keyed_fixtures';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
