<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Render;

use App\Enums\Manual\RenderKind;

/**
 * レンダマニフェスト (buildManifest tx で確定する読み取り一貫性の確定点。概念設計 §5)。
 * 以後 ffmpeg 実行中に cuts / takes が変わっても参照しない (version 固定の実体)。
 */
final readonly class RenderManifest
{
    /**
     * @param  list<RenderClipSpec>  $clips
     */
    public function __construct(
        public int $renderJobId,
        public RenderKind $kind,
        public int $scenarioVersion,
        /** S3 出力キー (version 付き = 再実行安全) */
        public string $outputKey,
        public array $clips,
    ) {}
}
