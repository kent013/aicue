<?php

declare(strict_types=1);

/*
 * 一時検証スクリプト (設計時のみ。実装には含めない)。
 *
 * 目的: `HasOne::ofMany(['sort_order' => 'min', 'id' => 'min'], closure)` が
 * 「最小 sort_order の集合の中で最小 id」を選ぶ join を実際に組み立てるか、
 * および closure の whereHas が各集約サブクエリへ入るかを **SQL 文字列で**確認する。
 *
 * DB へは 1 件もクエリを投げない (toSql / getBindings のみ)。
 * app/ 配下には一切変更を入れず、匿名の派生モデルで relation を宣言する。
 */

use App\Models\Cut;
use App\Models\Take;
use App\Models\VideoManual;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** 設計案の coverCut() をそのまま持つ検証専用の派生モデル */
final class CoverProbeManual extends VideoManual
{
    protected $table = 'video_manuals';

    /** @return HasOne<Cut, $this> */
    public function coverCut(): HasOne
    {
        return $this->hasOne(Cut::class, 'video_manual_id')->ofMany(
            ['sort_order' => 'min', 'id' => 'min'],
            /** @param Builder<Cut> $query */
            function (Builder $query): void {
                $query->whereHas(
                    'adoptedTake',
                    /** @param Builder<Take> $take */
                    function (Builder $take): void {
                        $take->whereNotNull('thumbnail_path');
                    }
                );
            }
        );
    }
}

$model = new CoverProbeManual;
$model->setAttribute('id', 42);

$relation = $model->coverCut();

echo "=== relation SQL ===\n";
echo $relation->toSql()."\n\n";
echo "=== bindings ===\n";
var_export($relation->getBindings());
echo "\n";

// eager load 形 (複数 manual を 1 クエリで解決するか)
$eager = (new CoverProbeManual)->coverCut();
$eager->addEagerConstraints([
    (new CoverProbeManual)->forceFill(['id' => 1]),
    (new CoverProbeManual)->forceFill(['id' => 2]),
    (new CoverProbeManual)->forceFill(['id' => 3]),
]);

echo "\n=== eager load SQL ===\n";
echo $eager->toSql()."\n\n";
echo "=== eager bindings ===\n";
var_export($eager->getBindings());
echo "\n";
