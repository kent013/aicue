<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Render;

/**
 * VideoComposer の合成結果 (ローカル最終 mp4 + クリップ実測尺)。
 */
final readonly class ComposedLocalVideo
{
    /**
     * @param  array<int, int>  $clipDurationsMs  cutId => 実測尺 (ms)
     */
    public function __construct(
        public string $localPath,
        public array $clipDurationsMs,
        public int $totalDurationMs,
    ) {}
}
