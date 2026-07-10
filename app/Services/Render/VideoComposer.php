<?php

declare(strict_types=1);

namespace App\Services\Render;

use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Exceptions\Manual\RenderCompositionException;

/**
 * 動画合成の抽象 (doc/09 §9.7)。v1 実装は FfmpegVideoComposer。
 * 将来 AWS MediaConvert 等への差し替え点。S3 入出力は呼び出し側 (RenderPipeline) の責務で、
 * composer はローカルファイルのみ扱う (責務分離: composer は「合成」だけ)。
 */
interface VideoComposer
{
    /**
     * マニフェストのクリップ群を合成し、ローカル最終 mp4 を返す。
     *
     * @param  array<int, string>  $localSources  cutId => ローカル素材パス (Placeholder cut は不在)
     * @param  callable(int, int): void  $onClipComposed  進捗通知 (composedClips, totalClips)
     *
     * @throws RenderCompositionException ffmpeg/ffprobe 失敗
     */
    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo;
}
