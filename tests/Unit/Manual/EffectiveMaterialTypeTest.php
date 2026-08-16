<?php

declare(strict_types=1);

use App\Enums\Manual\MaterialType;
use App\Models\Cut;
use App\Models\Take;
use App\Services\Manual\EffectiveMaterialType;

/*
 * 「このカットを実際にどちらの素材として合成するか」の式 (唯一の所在)。
 *
 * 実体優先である: 採用した後に編集者がシナリオ編集で cut.material_type を video へ戻せるため、
 * 入口 (presign 422) でも採用 API でも「cut=video / take=still」の状態は防げない。
 * この式は「画像が動画クリップ経路 (ffprobe で尺を測る) に流れる道」を構造的に消す。
 *
 * ready 判定は一切しない (AdoptedReadyTakeCoverage の専権。ドメイン固有規約 12)。
 */

/** DB を使わない (make で組む。式そのものの検査であり永続化は関係しない) */
function effectiveMaterialCut(?MaterialType $planned): Cut
{
    return Cut::factory()->make(['material_type' => $planned?->value]);
}

function effectiveMaterialTake(MaterialType $actual): Take
{
    return $actual === MaterialType::Still
        ? Take::factory()->still()->make()
        : Take::factory()->make();
}

test('cut=still × take=still → Still', function (): void {
    expect(EffectiveMaterialType::of(
        effectiveMaterialCut(MaterialType::Still),
        effectiveMaterialTake(MaterialType::Still),
    ))->toBe(MaterialType::Still);
});

test('cut=still × take=video → Still (先頭フレーム抽出。従来挙動)', function (): void {
    expect(EffectiveMaterialType::of(
        effectiveMaterialCut(MaterialType::Still),
        effectiveMaterialTake(MaterialType::Video),
    ))->toBe(MaterialType::Still);
});

test('cut=video × take=video → Video (回帰)', function (): void {
    expect(EffectiveMaterialType::of(
        effectiveMaterialCut(MaterialType::Video),
        effectiveMaterialTake(MaterialType::Video),
    ))->toBe(MaterialType::Video);
});

test('cut=video × take=still → Still (実体優先。採用後に計画を戻しても壊れない)', function (): void {
    expect(EffectiveMaterialType::of(
        effectiveMaterialCut(MaterialType::Video),
        effectiveMaterialTake(MaterialType::Still),
    ))->toBe(MaterialType::Still);
});

test('cut=未指定 × take=video → Video', function (): void {
    expect(EffectiveMaterialType::of(
        effectiveMaterialCut(null),
        effectiveMaterialTake(MaterialType::Video),
    ))->toBe(MaterialType::Video);
});

test('cut=未指定 × take=still → Still (実体優先)', function (): void {
    expect(EffectiveMaterialType::of(
        effectiveMaterialCut(null),
        effectiveMaterialTake(MaterialType::Still),
    ))->toBe(MaterialType::Still);
});
