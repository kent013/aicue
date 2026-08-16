<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Exceptions\Capture\TakeThumbnailExtractionException;

/**
 * テイク動画から表示用サムネイル (JPEG) を 1 枚作る抽象 (v1 は ffmpeg 実装)。
 *
 * `Render\VideoComposer` と同じ作法で interface に切る = テストは実バイナリに依存せず
 * container swap で fake を注入できる (AppServiceProvider が本番実装を bind する)。
 */
interface TakeThumbnailExtractor
{
    /**
     * @param  string  $localVideoPath  ローカルへ落とした動画 (サーバ生成のパス)
     * @param  string  $localThumbnailPath  出力先 (サーバ生成のパス)
     *
     * @throws TakeThumbnailExtractionException 抽出できなかった場合
     */
    public function extract(string $localVideoPath, string $localThumbnailPath): void;
}
