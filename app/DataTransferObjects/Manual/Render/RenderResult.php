<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Render;

/**
 * レンダ完了結果 (finalize tx へ渡す実測値)。
 */
final readonly class RenderResult
{
    /**
     * @param  array<int, int>  $clipDurationsMs  cutId => 実測尺 (ms)
     */
    public function __construct(
        /** S3 キー (アップロード済み) */
        public string $outputPath,
        public array $clipDurationsMs,
        public int $totalDurationMs,
        /** manifest 由来のプレースホルダ件数。**現在の manual 状態から数え直さない** (生成物の説明) */
        public int $placeholderCutCount,
    ) {}
}
