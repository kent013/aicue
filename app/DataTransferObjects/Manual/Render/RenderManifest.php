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

    /**
     * プレースホルダ (黒背景) に落ちたクリップ数。
     * 値の出所は**読み取り一貫性の確定点である clips ただ 1 つ**であり、DB も現在の manual 状態も
     * 見ない (生成物の説明であるため。生成後に採用しても件数は動かない = T148)。
     */
    public function placeholderCutCount(): int
    {
        return count(array_filter(
            $this->clips,
            static fn (RenderClipSpec $clip): bool => $clip->source === RenderClipSource::Placeholder,
        ));
    }
}
