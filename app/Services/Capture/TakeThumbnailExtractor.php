<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Enums\Manual\MaterialType;
use App\Exceptions\Capture\TakeThumbnailExtractionException;

/**
 * テイク素材から表示用サムネイル (JPEG) を 1 枚作る抽象 (v1 は ffmpeg 実装)。
 *
 * `Render\VideoComposer` と同じ作法で interface に切る = テストは実バイナリに依存せず
 * container swap で fake を注入できる (AppServiceProvider が本番実装を bind する)。
 */
interface TakeThumbnailExtractor
{
    /**
     * 素材種別を受け取り、seek 方針を実装側が決める。
     * 静止画に「1 秒地点」は存在しないため、種別を知らずに seek を決められない。
     *
     * @param  string  $localSourcePath  ローカルへ落とした素材 (サーバ生成のパス)
     * @param  string  $localThumbnailPath  出力先 (サーバ生成のパス)
     * @param  MaterialType  $material  登録された素材の実体種別 (takes.material_type)
     *
     * @throws TakeThumbnailExtractionException 抽出できなかった場合
     */
    public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void;
}
