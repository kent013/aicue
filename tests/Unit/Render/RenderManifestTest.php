<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderClipSpec;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Enums\Manual\RenderKind;

/*
 * RenderManifest::placeholderCutCount() (T148)。
 * 値の出所は**マニフェストの clips ただ 1 つ**であり、DB も現在の manual 状態も見ない
 * (マニフェストは読み取り一貫性の確定点 = 生成物の説明の唯一の根拠)。
 */

/** @param  list<RenderClipSource>  $sources */
function renderManifestWithSources(array $sources): RenderManifest
{
    $clips = [];
    foreach ($sources as $index => $source) {
        $clips[] = new RenderClipSpec(
            cutId: $index + 1,
            label: '手順'.($index + 1),
            source: $source,
            takeSourcePath: $source === RenderClipSource::Placeholder ? null : 'takes/x.mp4',
            stillDisplaySeconds: null,
            subtitlePrimary: null,
            subtitleSecondary: 'テロップ',
        );
    }

    return new RenderManifest(
        renderJobId: 1,
        kind: RenderKind::Preview,
        scenarioVersion: 2,
        outputKey: 'previews/v2-1.mp4',
        clips: $clips,
    );
}

test('placeholderCutCount は clips の Placeholder 件数を数える', function (): void {
    $manifest = renderManifestWithSources([
        RenderClipSource::TakeVideo,
        RenderClipSource::Placeholder,
        RenderClipSource::TakeStill,
        RenderClipSource::Placeholder,
    ]);

    expect($manifest->placeholderCutCount())->toBe(2);
});

test('Placeholder が無ければ 0 を返す', function (): void {
    $manifest = renderManifestWithSources([
        RenderClipSource::TakeVideo,
        RenderClipSource::TakeStill,
    ]);

    expect($manifest->placeholderCutCount())->toBe(0);
});
